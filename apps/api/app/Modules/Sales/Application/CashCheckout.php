<?php

namespace App\Modules\Sales\Application;

use App\Models\User;
use App\Modules\Catalog\Application\RetailPricing;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Application\SaleStock;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleAllocation;
use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Sales\Infrastructure\SaleLine;
use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBook;
use App\Shared\Application\Operations;
use Illuminate\Support\Str;

final class CashCheckout
{
    public function __construct(private PosAccess $access, private ShiftBook $shifts, private RetailPricing $pricing, private SaleStock $stock, private Operations $operations) {}

    private function flatten(array $items, bool $ids = false): array
    {
        $allocations = [];
        foreach ($items as $index => $item) {
            foreach ($item['allocations'] as $a) {
                $allocations[] = [...$a, 'product_id' => $item['product_id'], 'condition' => $item['condition'], 'unit' => $item['unit'], 'pieces_per_unit' => $item['pieces_per_unit'], 'line_index' => $index, ...($ids ? ['allocation_id' => (string) Str::ulid()] : [])];
            }
        }

        return $allocations;
    }

    public function receipt(string $id): array
    {
        return Sale::with('lines')->findOrFail($id)->toArray();
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
            $intent = SaleIntent::create(['organization_id' => $s->organization_id, 'store_id' => $store, 'cash_shift_id' => $shift->id, 'cashier_id' => $user->id, 'status' => 'pending', ...$snapshot]);
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
            $cash = (int) $v['cash_received'];
            if ($intent->status === 'paid') {
                $sale = Sale::where('sale_intent_id', $id)->firstOrFail();
                abort_unless($sale->cash_received === $cash && $v['payment_method'] === 'cash', 409, 'Draft sudah dibayar dengan nominal berbeda.');

                return $this->receipt($sale->id);
            }
            abort_unless($intent->status === 'pending', 409, 'Draft tidak dapat dibayar.');
            $this->shifts->assertOpen($shift);
            $current = $this->pricing->snapshot($s->organization_id, $intent->items, true);
            abort_unless(hash_equals($intent->price_hash, $current['price_hash']), 409, 'Harga/konversi berubah. Batalkan draft dan tinjau ulang keranjang.');
            abort_unless($cash >= $intent->total_amount, 422, 'Uang tunai kurang dari total.');
            $sale = new Sale(['organization_id' => $s->organization_id, 'store_id' => $s->id, 'cash_shift_id' => $shift->id, 'sale_intent_id' => $id, 'cashier_id' => $user->id, 'number' => 'POS-'.(string) Str::ulid(), 'store_name' => $s->name, 'store_address' => $s->address, 'cashier_name' => $user->name, 'status' => 'completed', 'currency' => 'IDR', 'payment_method' => 'cash', 'subtotal' => $intent->total_amount, 'discount_amount' => 0, 'total_amount' => $intent->total_amount, 'cash_received' => $cash, 'change_amount' => $cash - $intent->total_amount, 'sold_at' => now()]);
            $sale->save();
            $lines = [];
            foreach ($intent->items as $index => $item) {
                $lines[$index] = SaleLine::create(['sale_id' => $sale->id, ...array_intersect_key($item, array_flip(['product_id', 'sku', 'name', 'condition', 'unit', 'quantity', 'pieces_per_unit', 'unit_price', 'line_total']))]);
            }
            $allocations = $this->stock->inspect($s->id, $this->flatten($intent->items, true), true, $user->id);
            foreach ($allocations as $a) {
                $allocation = new SaleAllocation(['sale_line_id' => $lines[$a['line_index']]->id, 'inventory_batch_id' => $a['batch_id'], 'bucket' => $a['bucket'], 'pieces' => $a['pieces'], 'batch_code' => $a['batch_code'], 'expires_on' => $a['expires_on'], 'batch_pack_size' => $a['batch_pack_size']]);
                $allocation->id = $a['allocation_id'];
                $allocation->save();
            }
            $this->shifts->recordSale($shift, $intent->total_amount);
            $intent->fill(['status' => 'paid']);
            $intent->save();
            Audit::record($user->id, $s->organization_id, 'sale.completed', $sale->id, null, ['number' => $sale->number, 'total_amount' => $sale->total_amount, 'shift_id' => $shift->id]);

            return $this->receipt($sale->id);
        });
    }
}
