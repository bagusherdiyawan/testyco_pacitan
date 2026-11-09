<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Domain\Proportion;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryValuation
{
    public function quantity(InventoryBatch $b): int
    {
        return $b->usable_pieces + $b->opened_pieces + $b->ready_pieces + $b->quarantined_pieces;
    }

    public function movement(InventoryBatch $before, array $changes, string $type, string $source, int $actor, ?int $incoming = null, ?string $origin = null): void
    {
        $quantity = $this->quantity($before);
        $delta = 0;
        foreach ($changes as $bucket => $n) {
            if ($bucket !== 'waste') {
                $delta += $n;
            }
        }
        $pool = DB::table('inventory_values')->where('inventory_batch_id', $before->id)->lockForUpdate()->first();
        // Pre-existing stock has unknown value, never a price-based or zero-cost estimate.
        $value = $pool?->remaining_value;
        abort_if($pool && (int) $pool->quantity !== $quantity, 409, 'Nilai persediaan perlu rekonsiliasi jumlah.');
        if ($quantity === 0 && ! $pool) {
            $value = 0;
        }
        $cost = 0;
        if ($delta < 0) {
            $cost = $value === null ? null : Proportion::floor((int) $value, -$delta, $quantity);
        } elseif ($delta > 0) {
            $cost = $incoming;
        }
        $signed = $cost === null ? null : ($delta < 0 ? -$cost : $cost);
        $after = $value === null || $signed === null ? null : ($signed < 0 ? (int) $value + $signed : Rupiah::add((int) $value, $signed));
        // Empty physical stock has no remaining asset even after unknown historical costs.
        if ($quantity + $delta === 0) {
            $after = 0;
        }
        DB::table('inventory_values')->updateOrInsert(['inventory_batch_id' => $before->id], ['organization_id' => $before->organization_id, 'quantity' => $quantity + $delta, 'remaining_value' => $after, 'created_at' => $pool?->created_at ?? now(), 'updated_at' => now()]);
        $entry = (string) Str::ulid();
        DB::table('inventory_cost_entries')->insert(['id' => $entry, 'organization_id' => $before->organization_id, 'inventory_batch_id' => $before->id, 'stock_revision' => $before->stock_revision + 1, 'source_type' => $type, 'source_id' => $source, 'origin_id' => $origin, 'quantity_delta' => $delta, 'value_delta' => $signed, 'created_at' => now()]);
        if ($delta === 0 || in_array($type, ['pos_sale', 'sale_refund', 'production_output', 'warehouse_intake', 'intake'], true)) {
            return;
        }
        $auto = app(AutomaticPosting::class);
        if ($delta < 0 && ($changes['waste'] ?? 0) > 0) {
            $auto->record($before->organization_id, $actor, 'stock_loss', $entry, now('Asia/Jakarta')->format('Y-m-d'), [['loss', 'finished', $cost]], $type.' · '.$source);
        } else {
            $auto->record($before->organization_id, $actor, 'stock_review', $entry, now('Asia/Jakarta')->format('Y-m-d'), [], $type.' · '.$source, 'Penerimaan, distribusi atau koreksi produk membutuhkan rekonsiliasi nilai/ownership; belum diposting otomatis.');
        }
    }

    public function returned(string $org, string $allocation, int $pieces, ?int $offset = null): ?int
    {
        $sale = DB::table('inventory_cost_entries')->where('organization_id', $org)->where('source_type', 'pos_sale')->where('source_id', $allocation)->first();
        if (! $sale || $sale->value_delta === null) {
            return null;
        }
        $prior = (int) DB::table('inventory_cost_entries')->where('organization_id', $org)->where('source_type', 'sale_refund')->where('origin_id', $allocation)->sum('quantity_delta');
        $total = -(int) $sale->quantity_delta;
        abort_unless($prior + $pieces <= $total, 409, 'Nilai retur melebihi alokasi penjualan asal.');
        $start = $offset ?? $prior;
        abort_unless($start >= 0 && $start + $pieces <= $total, 409);

        return Proportion::floor(-(int) $sale->value_delta, $start + $pieces, $total) - Proportion::floor(-(int) $sale->value_delta, $start, $total);
    }

    public function propose(User $u, string $org, array $v): array
    {
        $b = InventoryBatch::where('organization_id', $org)->lockForUpdate()->findOrFail($v['batch_id']);
        abort_unless($b->stock_revision === $v['stock_revision'] && $this->quantity($b) > 0, 409);
        $pool = DB::table('inventory_values')->where('inventory_batch_id', $b->id)->first();
        abort_if($pool && $pool->remaining_value !== null, 409, 'Nilai batch sudah diketahui; tidak boleh ditimpa.');
        $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'inventory_batch_id' => $b->id, 'stock_revision' => $b->stock_revision, 'quantity' => $this->quantity($b), 'amount' => $v['amount'], 'version' => 1, 'status' => 'draft', 'created_by' => $u->id, 'note' => $v['note'], 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_value_proposals')->insert($r);

        return $r;
    }

    public function decide(User $u, string $org, array $v): array
    {
        $r = (array) DB::table('accounting_value_proposals')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($r['version'] === $v['version'] && $r['status'] === 'draft', 409);
        abort_if($r['created_by'] === $u->id, 403);
        if ($v['decision'] === 'approve') {
            abort_unless(DB::table('accounting_policies')->where('organization_id', $org)->where('status', 'active')->exists(), 409, 'Aktifkan pemetaan sebelum saldo awal.');
            $b = InventoryBatch::where('organization_id', $org)->lockForUpdate()->findOrFail($r['inventory_batch_id']);
            abort_unless($b->stock_revision === $r['stock_revision'] && $this->quantity($b) === $r['quantity'], 409, 'Stok berubah sejak penilaian; ajukan ulang setelah rekonsiliasi fisik.');
            $pool = DB::table('inventory_values')->where('inventory_batch_id', $b->id)->lockForUpdate()->first();
            abort_if($pool && $pool->remaining_value !== null, 409, 'Nilai batch sudah diketahui.');
            DB::table('inventory_values')->updateOrInsert(['inventory_batch_id' => $b->id], ['organization_id' => $org, 'quantity' => $r['quantity'], 'remaining_value' => $r['amount'], 'created_at' => $pool?->created_at ?? now(), 'updated_at' => now()]);
            app(AutomaticPosting::class)->record($org, $u->id, 'opening_finished', $r['id'], now('Asia/Jakarta')->format('Y-m-d'), [['finished', 'opening', $r['amount']]], $r['note']);
        }
        $new = ['status' => $v['decision'] === 'approve' ? 'approved' : 'rejected', 'version' => $r['version'] + 1, 'approved_by' => $u->id, 'updated_at' => now()];
        DB::table('accounting_value_proposals')->where('id', $r['id'])->update($new);

        return [...$r, ...$new];
    }
}
