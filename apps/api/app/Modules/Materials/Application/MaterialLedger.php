<?php

namespace App\Modules\Materials\Application;

use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Materials\Infrastructure\MaterialLot;
use App\Shared\Domain\Proportion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MaterialLedger
{
    public function value(MaterialLot $lot, int $quantity): int
    {
        $total = $lot->usable_quantity + $lot->quarantined_quantity;
        abort_unless($quantity > 0 && $quantity <= $total, 409, 'Jumlah bahan tidak cukup.');

        return Proportion::floor($lot->remaining_value, $quantity, $total);
    }

    public function apply(MaterialLot $lot, int $actor, array $changes, int $valueDelta, string $type, string $source, string $note): MaterialLot
    {
        return app(StockLedger::class)->apply($lot, $actor, $changes, $type, $source, $note, $valueDelta);
    }

    /** @internal Unit-specific writer dispatched exclusively by StockLedger.apply. */
    public function commit(MaterialLot $lot, int $actor, array $changes, int $valueDelta, string $type, string $source, string $note): MaterialLot
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Ledger bahan wajib dalam transaksi.');
        $lot = MaterialLot::lockForUpdate()->findOrFail($lot->id);
        abort_unless($lot->remaining_value + $valueDelta >= 0 && $lot->remaining_value + $valueDelta <= 1000000000000, 409, 'Nilai bahan tidak cukup/melebihi batas.');
        foreach ($changes as $bucket => $delta) {
            abort_unless(in_array($bucket, ['usable', 'quarantined', 'waste'], true) && is_int($delta), 422);
            $column = $bucket.'_quantity';
            $after = $lot->$column + $delta;
            abort_unless($after >= 0 && $after <= 1000000000000, 409, 'Stok bahan tidak cukup/melebihi batas.');
            $lot->$column = $after;
        }
        $lot->remaining_value += $valueDelta;
        abort_if($lot->usable_quantity + $lot->quarantined_quantity === 0 && $lot->remaining_value !== 0, 409, 'Stok kosong harus mempunyai nilai sisa nol. Periksa nilai koreksi.');
        $lot->stock_revision++;
        $lot->save();
        $first = true;
        foreach ($changes as $bucket => $delta) {
            if ($delta === 0) {
                continue;
            }
            DB::table('erp_material_entries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $lot->organization_id, 'material_lot_id' => $lot->id, 'actor_id' => $actor, 'bucket' => $bucket, 'quantity_delta' => $delta, 'value_delta' => $first ? $valueDelta : 0, 'source_type' => $type, 'source_id' => $source, 'note' => $note, 'created_at' => now()]);
            $first = false;
        }

        $terms = match ($type) {
            'material_receipt' => [['raw', $lot->purchase_id ? 'payable' : 'opening', $valueDelta]],
            'material_reject' => [['loss', 'raw', -$valueDelta]],
            'production_quality' => $valueDelta < 0 ? [['loss', 'raw', -$valueDelta]] : [],
            'material_count' => $valueDelta > 0 ? [['raw', 'adjustment', $valueDelta]] : [['adjustment', 'raw', -$valueDelta]],
            default => [],
        };
        if ($terms) {
            app(AutomaticPosting::class)->record($lot->organization_id, $actor, $type, $lot->id.':'.$lot->stock_revision, $type === 'material_receipt' ? $lot->received_on->format('Y-m-d') : now('Asia/Jakarta')->format('Y-m-d'), $terms, $note);
        }

        return $lot;
    }

    public function eligible(MaterialLot $lot): void
    {
        abort_if($lot->blocked || ($lot->expires_on && $lot->expires_on->format('Y-m-d') < now('Asia/Jakarta')->format('Y-m-d')), 409, 'Bahan diblokir atau kedaluwarsa.');
    }
}
