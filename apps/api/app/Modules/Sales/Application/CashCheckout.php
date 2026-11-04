<?php

namespace App\Modules\Sales\Application;

use App\Models\User;
use App\Modules\Catalog\Application\RetailPricing;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Application\SaleStock;
use App\Modules\Promotions\Application\VoucherBook;
use App\Modules\Sales\Infrastructure\OfflinePermit;
use App\Modules\Sales\Infrastructure\OfflineSale;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleAllocation;
use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Sales\Infrastructure\SaleLine;
use App\Modules\Sales\Infrastructure\SaleRefund;
use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBook;
use App\Modules\Shifts\Infrastructure\CashShift;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashCheckout
{
    public function __construct(private PosAccess $access, private ShiftBook $shifts, private RetailPricing $pricing, private SaleStock $stock, private Operations $operations, private VoucherBook $vouchers) {}

    private function flatten(array $items, bool $ids = false): array
    {
        $allocations = [];
        foreach ($items as $index => $item) {
            foreach ($item['allocations'] as $a) {
                $allocations[] = [...$a, 'product_id' => $a['component_product_id'] ?? $item['product_id'], 'condition' => isset($a['component_product_id']) ? $a['condition'] : $item['condition'], 'unit' => isset($a['component_product_id']) ? 'piece' : $item['unit'], 'pieces_per_unit' => isset($a['component_product_id']) ? 1 : $item['pieces_per_unit'], 'line_index' => $index, ...($ids ? ['allocation_id' => (string) Str::ulid()] : [])];
            }
        }

        return $allocations;
    }

    public function receipt(string $id): array
    {
        return [...Sale::with('lines')->findOrFail($id)->toArray(), 'refunds' => SaleRefund::where('sale_id', $id)->orderBy('id')->get()->toArray()];
    }

    public function create(User $user, string $store, array $v): array
    {
        $s = $this->access->store($user, $store);

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'sale.intent:'.$store, $v, function () use ($user, $store, $s, $v) {
            $shift = $this->shifts->lockOwned($user, $store, $v['shift_id']);
            $this->shifts->assertOpen($shift);
            abort_if(SaleIntent::where('cash_shift_id', $shift->id)->where('status', 'pending')->count() >= 20, 409, 'Selesaikan atau batalkan draft lama (maksimal 20 draft aktif).');
            $snapshot = $this->pricing->snapshot($s->organization_id, $v['lines'], true);
            $this->stock->inspect($store, $this->flatten($snapshot['items']));
            $voucher = $this->vouchers->quote($s->organization_id, $store, $snapshot['items'], $v['voucher_code'] ?? null, true);
            $gross = $snapshot['total_amount'];
            $discount = $voucher['discount_amount'] ?? 0;
            $snapshot['total_amount'] = $gross - $discount;
            $intent = SaleIntent::create(['subtotal' => $gross, 'discount_amount' => $discount, 'voucher_snapshot' => $voucher, 'organization_id' => $s->organization_id, 'store_id' => $store, 'cash_shift_id' => $shift->id, 'cashier_id' => $user->id, 'status' => 'pending', ...$snapshot]);
            Audit::record($user->id, $s->organization_id, 'sale.intent_created', $intent->id, null, ['shift_id' => $shift->id, 'total_amount' => $intent->total_amount]);

            return $intent->toArray();
        });
    }

    public function cancel(User $user, string $id, array $v): array
    {
        $initial = SaleIntent::findOrFail($id);
        $s = $this->access->store($user, $initial->store_id);
        abort_unless((int) $initial->cashier_id === $user->id, 403);

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'sale.cancel:'.$id, $v, function () use ($user, $initial, $id, $s, $v) {
            $this->shifts->lockOwned($user, $s->id, $initial->cash_shift_id);
            $intent = SaleIntent::lockForUpdate()->findOrFail($id);
            abort_unless($intent->status === 'pending', 409, 'Draft sudah dibayar atau dibatalkan.');
            $intent->fill(['status' => 'cancelled', 'cancel_reason' => $v['reason']]);
            $intent->save();
            Audit::record($user->id, $s->organization_id, 'sale.intent_cancelled', $id, null, ['reason' => $v['reason']]);

            return $intent->toArray();
        });
    }

    public function pay(User $user, string $id, array $v): array
    {
        $initial = SaleIntent::findOrFail($id);
        $s = $this->access->store($user, $initial->store_id);
        abort_unless((int) $initial->cashier_id === $user->id, 403, 'Draft milik kasir lain.');

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'sale.pay:'.$id, $v, function () use ($user, $initial, $id, $s, $v) {
            $shift = $this->shifts->lockOwned($user, $s->id, $initial->cash_shift_id);
            $intent = SaleIntent::lockForUpdate()->findOrFail($id);
            $method = $v['payment_method'];
            $cash = $method === 'cash' ? (int) ($v['cash_received'] ?? 0) : 0;
            $reference = $method === 'cash' ? null : $v['payment_reference'];
            if ($intent->status === 'paid') {
                $sale = Sale::where('sale_intent_id', $id)->firstOrFail();
                abort_unless($sale->cash_received === $cash && $sale->payment_method === $method && $sale->payment_reference === $reference && ($method === 'cash' || (($v['verified'] ?? false) === true && (int) ($v['verified_amount'] ?? -1) === $sale->total_amount)), 409, 'Draft sudah dibayar dengan nominal berbeda.');

                return $this->receipt($sale->id);
            }
            abort_unless($intent->status === 'pending', 409, 'Draft tidak dapat dibayar.');
            $this->shifts->assertOpen($shift);
            $current = $this->pricing->snapshot($s->organization_id, $intent->items, true);
            abort_unless(hash_equals($intent->price_hash, $current['price_hash']), 409, 'Harga/konversi berubah. Batalkan draft dan tinjau ulang keranjang.');
            if ($method === 'cash') {
                abort_unless($cash >= $intent->total_amount, 422, 'Uang tunai kurang dari total.');
            } else {
                abort_unless(($v['verified'] ?? false) === true && (int) ($v['verified_amount'] ?? -1) === $intent->total_amount, 422, 'Periksa bukti pembayaran dan nominal persis secara manual.');
                abort_if(Sale::where('organization_id', $s->organization_id)->where('payment_method', $method)->where('payment_reference', $reference)->exists(), 409, 'Referensi pembayaran telah digunakan.');
            }
            $voucher = $this->vouchers->quote($s->organization_id, $s->id, $current['items'], $intent->voucher_snapshot['code'] ?? null, true);
            abort_unless($voucher === $intent->voucher_snapshot, 409, 'Voucher/kuota berubah. Tinjau draft baru.');
            $sale = new Sale(['organization_id' => $s->organization_id, 'store_id' => $s->id, 'cash_shift_id' => $shift->id, 'sale_intent_id' => $id, 'cashier_id' => $user->id, 'number' => 'POS-'.(string) Str::ulid(), 'store_name' => $s->name, 'store_address' => $s->address, 'cashier_name' => $user->name, 'status' => 'completed', 'currency' => 'IDR', 'version' => 1, 'voucher_snapshot' => $voucher, 'payment_reference' => $reference, 'payment_verified_by' => $method === 'cash' ? null : $user->id, 'payment_verified_at' => $method === 'cash' ? null : now(), 'payment_method' => $method, 'subtotal' => $intent->subtotal ?? $intent->total_amount, 'discount_amount' => $intent->discount_amount, 'total_amount' => $intent->total_amount, 'cash_received' => $cash, 'change_amount' => $method === 'cash' ? $cash - $intent->total_amount : 0, 'sold_at' => now()]);
            $sale->save();
            $lines = [];
            foreach ($intent->items as $index => $item) {
                $lines[$index] = SaleLine::create(['sale_id' => $sale->id, 'discount_amount' => $voucher['line_discounts'][$item['product_id']] ?? 0, ...array_intersect_key($item, array_flip(['product_id', 'sku', 'name', 'condition', 'unit', 'quantity', 'pieces_per_unit', 'unit_price', 'line_total', 'recipe_snapshot']))]);
            }
            $allocations = $this->stock->inspect($s->id, $this->flatten($intent->items, true), true, $user->id);
            foreach ($allocations as $a) {
                $allocation = new SaleAllocation(['sale_line_id' => $lines[$a['line_index']]->id, 'inventory_batch_id' => $a['batch_id'], 'bucket' => $a['bucket'], 'pieces' => $a['pieces'], 'batch_code' => $a['batch_code'], 'expires_on' => $a['expires_on'], 'batch_pack_size' => $a['batch_pack_size']]);
                $allocation->id = $a['allocation_id'];
                $allocation->save();
            }
            $this->shifts->recordSale($shift, $intent->total_amount, $method);
            if ($voucher) {
                $this->vouchers->redeem($voucher, $sale->id);
            }
            $intent->fill(['status' => 'paid']);
            $intent->save();
            Audit::record($user->id, $s->organization_id, 'sale.completed', $sale->id, null, ['number' => $sale->number, 'total_amount' => $sale->total_amount, 'shift_id' => $shift->id]);

            return $this->receipt($sale->id);
        });
    }

    /** Called only after a locked offline record has been validated or explicitly reviewed. */
    public function postOffline(User $actor, OfflineSale $record, CashShift $shift, array $snapshot): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $this->shifts->assertOpen($shift);
        $permit = OfflinePermit::findOrFail($record->offline_permit_id);
        $s = $permit->snapshot['store'];
        $cash = (int) $record->payload['cash_received'];
        abort_unless($cash >= $snapshot['total_amount'] && $shift->store_id === $record->store_id && (int) $shift->cashier_id === (int) $record->cashier_id, 409, 'Kas/shift penyelesaian tidak sesuai.');
        $intent = SaleIntent::create(['organization_id' => $record->organization_id, 'store_id' => $record->store_id, 'cash_shift_id' => $shift->id, 'cashier_id' => $record->cashier_id, 'status' => 'paid', 'subtotal' => $snapshot['total_amount'], 'discount_amount' => 0, 'voucher_snapshot' => null, ...$snapshot]);
        $sale = Sale::create(['organization_id' => $record->organization_id, 'store_id' => $record->store_id, 'cash_shift_id' => $shift->id, 'sale_intent_id' => $intent->id, 'cashier_id' => $record->cashier_id, 'number' => 'POS-'.Str::ulid(), 'store_name' => $s['name'], 'store_address' => $s['address'] ?? null, 'cashier_name' => $permit->snapshot['cashier_name'], 'status' => 'completed', 'currency' => 'IDR', 'version' => 1, 'payment_method' => 'cash', 'subtotal' => $snapshot['total_amount'], 'discount_amount' => 0, 'total_amount' => $snapshot['total_amount'], 'cash_received' => $cash, 'change_amount' => $cash - $snapshot['total_amount'], 'sold_at' => $record->captured_at, 'offline_sale_id' => $record->id, 'synced_at' => now()]);
        $lines = [];
        foreach ($snapshot['items'] as $index => $item) {
            $lines[$index] = SaleLine::create(['sale_id' => $sale->id, 'discount_amount' => 0, ...array_intersect_key($item, array_flip(['product_id', 'sku', 'name', 'condition', 'unit', 'quantity', 'pieces_per_unit', 'unit_price', 'line_total', 'recipe_snapshot']))]);
        }
        foreach ($this->stock->inspect($record->store_id, $this->flatten($snapshot['items'], true), true, $actor->id) as $a) {
            $allocation = new SaleAllocation(['sale_line_id' => $lines[$a['line_index']]->id, 'inventory_batch_id' => $a['batch_id'], 'bucket' => $a['bucket'], 'pieces' => $a['pieces'], 'batch_code' => $a['batch_code'], 'expires_on' => $a['expires_on'], 'batch_pack_size' => $a['batch_pack_size']]);
            $allocation->id = $a['allocation_id'];
            $allocation->save();
        }
        $this->shifts->recordSale($shift, $snapshot['total_amount']);
        Audit::record($actor->id, $record->organization_id, 'sale.offline_completed', $sale->id, null, ['offline_record_id' => $record->id, 'number' => $sale->number, 'total_amount' => $sale->total_amount]);

        return $this->receipt($sale->id);
    }
}
