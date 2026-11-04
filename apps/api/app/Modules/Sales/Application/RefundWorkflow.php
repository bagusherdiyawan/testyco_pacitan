<?php

namespace App\Modules\Sales\Application;

use App\Models\User;
use App\Modules\Commercial\Application\SponsoredPromotions;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Application\SaleReturns;
use App\Modules\Promotions\Application\VoucherBook;
use App\Modules\Promotions\Infrastructure\Voucher;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleRefund;
use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBook;
use App\Modules\Shifts\Infrastructure\CashShift;
use App\Shared\Application\Operations;
use App\Shared\Domain\Proportion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RefundWorkflow
{
    public function __construct(private PosAccess $access, private ShiftBook $shifts, private SaleReturns $stock, private VoucherBook $vouchers, private Operations $ops) {}

    public function context(User $u, string $id): array
    {
        $s = Sale::with('lines')->findOrFail($id);
        $this->access->manager($u, $s->store_id, false);

        return ['sale' => $s->toArray(), 'refunds' => SaleRefund::where('sale_id', $id)->orderBy('id')->get()->toArray(), 'open_shifts' => CashShift::where('store_id', $s->store_id)->where('status', 'open')->orderBy('id')->get()->toArray()];
    }

    private function review(User $u, Sale $s, array $v): array
    {
        abort_unless($s->version === (int) $v['version'] && $s->status === 'completed', 409, 'Versi struk berubah.');
        $s->load('lines');
        $old = SaleRefund::where('sale_id', $s->id)->get();
        $lines = [];
        $total = 0;
        $fully = true;
        $requested = collect($v['lines'])->keyBy('sale_line_id');
        abort_unless($requested->count() === count($v['lines']), 422);
        foreach ($requested as $id => $r) {
            abort_unless($s->lines->contains('id', $id), 422, 'Baris bukan milik struk.');
        }
        foreach ($s->lines as $line) {
            $prior = 0;
            foreach ($old as $refund) {
                $prior += (int) collect($refund->lines)->where('sale_line_id', $line->id)->sum('quantity');
            }$qty = (int) ($requested[$line->id]['quantity'] ?? 0);
            abort_unless($prior + $qty <= $line->quantity, 422, 'Refund melebihi unit terjual yang tersisa.');
            if ($prior + $qty !== $line->quantity) {
                $fully = false;
            }if ($qty === 0) {
                continue;
            }
            $net = $line->line_total - $line->discount_amount;
            $amount = Proportion::floor($net, $prior + $qty, $line->quantity) - Proportion::floor($net, $prior, $line->quantity);
            $total += $amount;
            $allocations = [];
            if ($v['stock_disposition'] === 'quarantine') {
                $batches = $this->stock->batches($s->organization_id, $s->store_id, $line->allocations->pluck('inventory_batch_id')->all());
                $groups = [];
                foreach ($line->allocations->sortBy('id') as $a) {
                    abort_unless(isset($batches[$a->inventory_batch_id]), 409, 'Batch historis tidak tersedia.');
                    $b = $batches[$a->inventory_batch_id];
                    $groups[$b->product_id][] = $a;
                }
                foreach ($groups as $product => $group) {
                    $pieces = array_sum(array_map(fn ($a) => $a->pieces, $group));
                    abort_unless($pieces % $line->quantity === 0, 409, 'Konversi alokasi historis perlu rekonsiliasi.');
                    $per = intdiv($pieces, $line->quantity);
                    $start = $prior * $per;
                    $end = ($prior + $qty) * $per;
                    $offset = 0;
                    foreach ($group as $a) {
                        $n = max(0, min($offset + $a->pieces, $end) - max($offset, $start));
                        $offset += $a->pieces;
                        if ($n > 0) {
                            $allocations[] = ['sale_allocation_id' => $a->id, 'batch_id' => $a->inventory_batch_id, 'product_id' => $product, 'batch_code' => $a->batch_code, 'pieces' => $n, 'destination_bucket' => 'quarantined'];
                        }
                    }
                }
            }
            $lines[] = ['sale_line_id' => $line->id, 'sku' => $line->sku, 'name' => $line->name, 'quantity' => $qty, 'unit' => $line->unit, 'unit_price' => $line->unit_price, 'historical_discount' => $line->discount_amount, 'amount' => $amount, 'allocations' => $allocations];
        }
        abort_unless(count($lines) > 0, 422, 'Pilih jumlah yang direfund.');
        usort($lines, fn ($a, $b) => strcmp($a['sale_line_id'], $b['sale_line_id']));
        $review = ['sale_id' => $s->id, 'store_id' => $s->store_id, 'version' => $s->version, 'shift_id' => $v['shift_id'], 'shift_version' => (int) $v['shift_version'], 'payment_method' => $s->payment_method, 'amount' => $total, 'stock_disposition' => $v['stock_disposition'], 'reference' => $v['reference'], 'reason' => $v['reason'], 'lines' => $lines, 'fully_refunded' => $fully];

        return [...$review, 'review_hash' => hash('sha256', json_encode($review, JSON_THROW_ON_ERROR))];
    }

    public function preview(User $u, string $id, array $v): array
    {
        $s = Sale::findOrFail($id);
        $this->access->manager($u, $s->store_id);

        return DB::transaction(function () use ($u, $id, $s, $v) {
            $this->shifts->refundShift($u, $s->store_id, $v['shift_id'], (int) $v['shift_version']);
            $s = Sale::lockForUpdate()->findOrFail($id);

            return $this->review($u, $s, $v);
        });
    }

    public function refund(User $u, string $id, array $v): array
    {
        $initial = Sale::findOrFail($id);
        $this->access->manager($u, $initial->store_id);

        return $this->ops->run($initial->organization_id, $u->id, $v['operation_id'], 'sale.refund:'.$id, $v, function () use ($u, $id, $initial, $v) {
            $this->access->manager($u, $initial->store_id);
            $shift = $this->shifts->refundShift($u, $initial->store_id, $v['shift_id'], (int) $v['shift_version']);
            $s = Sale::lockForUpdate()->findOrFail($id);
            abort_unless($v['verified'] === true, 422, 'Verifikasi pengembalian uang aktual wajib.');
            $review = $this->review($u, $s, $v);
            abort_unless(hash_equals($review['review_hash'], $v['review_hash']), 409, 'Review refund berubah.');
            abort_if(SaleRefund::where('sale_id', $id)->where('reference', $v['reference'])->exists(), 409, 'Referensi refund sudah digunakan.');
            $this->shifts->recordRefund($shift, $u, $review['amount'], $s->payment_method, $v['reference'], $v['reason']);
            $refund = SaleRefund::create(['sale_id' => $id, 'cash_shift_id' => $shift->id, 'actor_id' => $u->id, 'payment_method' => $s->payment_method, 'reference' => $v['reference'], 'stock_disposition' => $v['stock_disposition'], 'amount' => $review['amount'], 'lines' => $review['lines'], 'reason' => $v['reason'], 'refunded_at' => now()]);
            $allocations = [];
            foreach ($review['lines'] as $line) {
                foreach ($line['allocations'] as $a) {
                    $allocations[] = [...$a, 'source_id' => (string) Str::ulid()];
                }
            }
            // Voucher lock precedes batch locks, matching checkout's lock ordering.
            if ($s->voucher_snapshot) {
                Voucher::whereKey($s->voucher_snapshot['id'])->lockForUpdate()->firstOrFail();
            }
            $this->vouchers->restore($s->voucher_snapshot, $id, $review['fully_refunded']);
            $this->stock->quarantine($s->organization_id, $s->store_id, $u->id, $allocations, $v['reason']);
            $s->increment('version');
            app(SponsoredPromotions::class)->refunded($s);
            Audit::record($u->id, $s->organization_id, 'sale.refunded', $refund->id, null, $refund->toArray());

            return ['sale_id' => $id, 'sale_version' => $s->version, 'refund' => $refund->toArray()];
        });
    }
}
