<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Application\ConsignorCosting;
use App\Modules\Accounting\Application\DistributionCosting;
use App\Modules\Accounting\Application\DistributionValueWriter;
use App\Modules\Accounting\Application\InventoryValuation;
use App\Modules\Commercial\Application\ConsignmentAutomation;
use App\Modules\Inventory\Domain\StockBucket;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Materials\Application\MaterialLedger;
use App\Modules\Materials\Infrastructure\MaterialLot;
use App\Modules\Reliability\Application\Inbox;
use App\Modules\Reliability\Application\RecallCoordinator;
use App\Modules\Reliability\ReliabilityServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockLedger
{
    public function credit(string $organization, string $store, array $product, string $batch, string $expiry, int $packs, int $actor, string $sourceType, string $sourceId, string $bucket, ?string $note = null, ?int $inventoryValue = null): array
    {
        abort_unless(in_array($bucket, ['usable', 'quarantined'], true), 422);
        $key = ['store_id' => $store, 'product_id' => $product['id'], 'batch_code' => $batch, 'pack_size' => $product['pack_size']];
        $now = now();
        DB::table('inventory_batches')->insertOrIgnore([...$key, 'id' => (string) Str::ulid(), 'organization_id' => $organization, 'sku' => $product['sku'], 'name' => $product['name'], 'condition' => $product['condition'], 'expires_on' => $expiry, 'usable_pieces' => 0, 'quarantined_pieces' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $record = InventoryBatch::where($key)->lockForUpdate()->firstOrFail();
        abort_unless($record->expires_on->format('Y-m-d') === $expiry && $record->condition === $product['condition'], 409, 'Identitas batch sudah ada dengan tanggal/kondisi berbeda.');
        if ($packs > 0) {
            $pieces = $packs * $record->pack_size;
            $this->apply($record, $actor, [$bucket => $pieces], $sourceType, $sourceId, $note, inventoryValue: $inventoryValue);
        }

        if (app()->getProvider(ReliabilityServiceProvider::class)) {
            app(RecallCoordinator::class)->received($record, $sourceType, $sourceId);
        }

        return $record->fresh()->toArray();
    }

    public function dispatch(string $batchId, string $store, string $product, int $packSize, int $packs, int $actor, string $sourceId, string $sourceType = 'shipment_dispatch'): array
    {
        $batch = InventoryBatch::where('store_id', $store)->where('product_id', $product)->where('pack_size', $packSize)->lockForUpdate()->findOrFail($batchId);
        app(FoodSafety::class)->assertEligible($batch, 'usable');
        app(ConsignmentAutomation::class)->guardDispatch($batch->id);
        $pieces = $packs * $packSize;
        abort_unless($batch->usable_pieces >= $pieces, 409, 'Stok batch tidak cukup; stok karantina tidak dapat dikirim.');
        $this->apply($batch, $actor, ['usable' => -$pieces], $sourceType, $sourceId);

        return $batch->fresh()->toArray();
    }

    public function apply(InventoryBatch|MaterialLot $batch, int $actor, array $changes, string $sourceType, string $sourceId, ?string $note = null, int $valueDelta = 0, ?int $inventoryValue = null, ?string $costOrigin = null, ?int $costOffset = null): InventoryBatch|MaterialLot
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Pergerakan stok harus dalam transaksi.');
        if ($batch instanceof MaterialLot) {
            return app(MaterialLedger::class)->commit($batch, $actor, $changes, $valueDelta, $sourceType, $sourceId, $note ?? '');
        }
        if ($sourceType === 'consignment_bind' && $changes === []) {
            return app(ConsignorCosting::class)->bind($batch, $sourceId);
        }
        if ($sourceType === 'distribution_value' && $changes === []) {
            return app(DistributionValueWriter::class)->write($batch, $sourceId, $valueDelta);
        }
        if ($valueDelta !== 0) {
            abort_unless($sourceType === 'invoice_variance' && $changes === [], 422, 'Nilai produk hanya dapat dikoreksi melalui sumber invoice terverifikasi.');

            return app(InventoryValuation::class)->invoiceValue($batch, $actor, $valueDelta, $sourceId);
        }
        abort_unless($valueDelta === 0, 422, 'Nilai bahan tidak boleh diposting pada ledger biji.');
        $batch = InventoryBatch::lockForUpdate()->findOrFail($batch->id);
        $ownedDelta = array_sum(array_filter($changes, fn ($key) => $key !== 'waste', ARRAY_FILTER_USE_KEY));
        if ($ownedDelta < 0) {
            abort_if(app(DistributionCosting::class)->blocked($batch->organization_id, $batch->id), 409, 'Pembagian biaya pengganti masih menunggu pemeriksaan; selesaikan valuasi sebelum barang dikeluarkan.');
        }
        $attributes = [];
        $deltas = [];
        foreach ($changes as $key => $delta) {
            $bucket = StockBucket::tryFrom($key);
            abort_unless($bucket && is_int($delta), 422, 'Bucket atau jumlah stok tidak valid.');
            if ($delta === 0) {
                continue;
            }
            $remaining = $batch->{$bucket->column()} + $delta;
            abort_if($remaining < 0, 409, 'Stok pada bucket sumber tidak cukup.');
            if ($bucket === StockBucket::Sealed) {
                abort_unless($remaining % $batch->pack_size === 0, 422, 'Stok utuh harus berupa kelipatan isi kemasan historis.');
            }
            if ($key === 'quarantined' && $delta < 0) {
                $custody = (int) DB::table('accounting_return_costs')->where('inventory_batch_id', $batch->id)->where('status', 'held')->sum('received_pieces');
                abort_unless($remaining >= $custody, 409, 'Barang titipan retur belum menjadi milik organisasi; nota kredit harus disetujui dahulu.');
            }
            $attributes[$bucket->column()] = $remaining;
            $deltas[$key] = $delta;
        }
        if ($deltas) {
            app(InventoryValuation::class)->movement(clone $batch, $deltas, $sourceType, $sourceId, $actor, $inventoryValue, $costOrigin, $costOffset);
            if (isset($attributes['ready_pieces']) && $attributes['ready_pieces'] === 0) {
                $attributes['ready_expires_at'] = null;
            }
            $batch->fill([...$attributes, 'stock_revision' => $batch->stock_revision + 1]);
            $batch->save();
            if (app()->getProvider(ReliabilityServiceProvider::class)) {
                app(Inbox::class)->lowStock($batch->store_id, $batch->product_id);
            }
            foreach ($deltas as $bucket => $delta) {
                $this->entry($batch, $actor, $delta, $sourceType, $sourceId, $bucket, $note);
            }
        }

        return $batch;
    }

    private function entry(InventoryBatch $batch, int $actor, int $pieces, string $type, string $id, string $bucket, ?string $note): void
    {
        DB::table('stock_entries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $batch->organization_id, 'store_id' => $batch->store_id, 'inventory_batch_id' => $batch->id, 'actor_id' => $actor, 'bucket' => $bucket, 'quantity_pieces' => $pieces, 'source_type' => $type, 'source_id' => $id, 'note' => $note, 'created_at' => now()]);
    }
}
