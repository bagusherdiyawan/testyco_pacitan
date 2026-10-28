<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockLedger
{
    public function credit(string $organization, string $store, array $product, string $batch, string $expiry, int $packs, int $actor, string $sourceType, string $sourceId, string $bucket, ?string $note = null): array
    {
        abort_unless(in_array($bucket, ['usable', 'quarantined'], true), 422);
        $key = ['store_id' => $store, 'product_id' => $product['id'], 'batch_code' => $batch, 'pack_size' => $product['pack_size']];
        $now = now();
        DB::table('inventory_batches')->insertOrIgnore([...$key, 'id' => (string) Str::ulid(), 'organization_id' => $organization, 'sku' => $product['sku'], 'name' => $product['name'], 'condition' => $product['condition'], 'expires_on' => $expiry, 'usable_pieces' => 0, 'quarantined_pieces' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $record = InventoryBatch::where($key)->lockForUpdate()->firstOrFail();
        abort_unless($record->expires_on->format('Y-m-d') === $expiry && $record->condition === $product['condition'], 409, 'Identitas batch sudah ada dengan tanggal/kondisi berbeda.');
        if ($packs > 0) {
            $pieces = $packs * $record->pack_size;
            $record->increment($bucket.'_pieces', $pieces);
            $this->entry($record, $actor, $pieces, $sourceType, $sourceId, $bucket, $note);
        }

        return $record->fresh()->toArray();
    }

    public function dispatch(string $batchId, string $store, string $product, int $packSize, int $packs, int $actor, string $sourceId): array
    {
        $batch = InventoryBatch::where('store_id', $store)->where('product_id', $product)->where('pack_size', $packSize)->lockForUpdate()->findOrFail($batchId);
        $pieces = $packs * $packSize;
        abort_unless($batch->usable_pieces >= $pieces, 409, 'Stok batch tidak cukup; stok karantina tidak dapat dikirim.');
        $changed = InventoryBatch::whereKey($batch->id)->where('usable_pieces', '>=', $pieces)->decrement('usable_pieces', $pieces);
        abort_unless($changed === 1, 409, 'Stok berubah. Coba kembali.');
        $this->entry($batch, $actor, -$pieces, 'shipment_dispatch', $sourceId, 'usable', null);

        return $batch->toArray();
    }

    private function entry(InventoryBatch $batch, int $actor, int $pieces, string $type, string $id, string $bucket, ?string $note): void
    {
        DB::table('stock_entries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $batch->organization_id, 'store_id' => $batch->store_id, 'inventory_batch_id' => $batch->id, 'actor_id' => $actor, 'bucket' => $bucket, 'quantity_pieces' => $pieces, 'source_type' => $type, 'source_id' => $id, 'note' => $note, 'created_at' => now()]);
    }
}
