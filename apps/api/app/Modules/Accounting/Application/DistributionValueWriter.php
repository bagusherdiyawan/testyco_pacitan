<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Monetary/ownership proof writer; always entered through StockLedger, never client money. */
final class DistributionValueWriter
{
    public function write(InventoryBatch $batch, string $proof, int $delta): InventoryBatch
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $batch = InventoryBatch::lockForUpdate()->findOrFail($batch->id);
        $p = DB::table('inventory_distribution_values')->where('organization_id', $batch->organization_id)->where('inventory_batch_id', $batch->id)->where('id', $proof)->firstOrFail();
        $pool = DB::table('inventory_values')->where('organization_id', $batch->organization_id)->where('inventory_batch_id', $batch->id)->lockForUpdate()->firstOrFail();
        abort_unless(! DB::table('inventory_cost_entries')->where('source_type', 'distribution_value')->where('source_id', $proof)->exists() && (int) $p->stock_revision === $batch->stock_revision && (int) $pool->quantity === (int) $p->before_quantity && $pool->remaining_value !== null && (int) $pool->remaining_value === (int) $p->before_value && $delta === (int) $p->value_delta && (int) $p->after_value === (int) $p->before_value + $delta && $p->after_value >= 0 && $p->after_value <= Rupiah::LIMIT && (int) $p->after_quantity === app(InventoryValuation::class)->quantity($batch), 409, 'Snapshot valuasi/kepemilikan distribusi berubah.');
        $batch->increment('stock_revision');
        DB::table('inventory_values')->where('inventory_batch_id', $batch->id)->update(['quantity' => $p->after_quantity, 'remaining_value' => $p->after_value, 'updated_at' => now()]);
        DB::table('inventory_cost_entries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $batch->organization_id, 'inventory_batch_id' => $batch->id, 'stock_revision' => $batch->stock_revision, 'source_type' => 'distribution_value', 'source_id' => $proof, 'origin_id' => null, 'quantity_delta' => $p->after_quantity - $p->before_quantity, 'value_delta' => $delta, 'created_at' => now()]);

        return $batch;
    }
}
