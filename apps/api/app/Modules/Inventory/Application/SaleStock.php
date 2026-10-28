<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\InventoryBatch;

final class SaleStock
{
    public function __construct(private StockLedger $ledger) {}

    public function inspect(string $store, array $allocations, bool $debit = false, ?int $actor = null): array
    {
        $ids = array_unique(array_column($allocations, 'batch_id'));
        $batches = InventoryBatch::where('store_id', $store)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $projected = [];
        $result = [];
        usort($allocations, fn ($a, $b) => strcmp($a['batch_id'].':'.$a['bucket'], $b['batch_id'].':'.$b['bucket']));
        foreach ($allocations as $allocation) {
            $batch = $batches->get($allocation['batch_id']);
            abort_unless($batch && $batch->product_id === $allocation['product_id'], 404, 'Batch tidak termasuk SKU dan toko yang dipilih.');
            abort_unless($batch->condition === $allocation['condition'], 409, 'Kondisi batch tidak sesuai katalog.');
            $bucket = $allocation['bucket'];
            abort_unless(in_array($bucket, ['usable', 'opened', 'ready'], true), 422, 'Karantina dan susut tidak dapat dijual.');
            if ($allocation['unit'] === 'pack') {
                abort_unless($bucket === 'usable' && $batch->pack_size === $allocation['pieces_per_unit'], 422, 'Penjualan kemasan harus memakai stok utuh dengan isi historis yang sama.');
            } else {
                abort_unless(in_array($bucket, ['opened', 'ready'], true), 422, 'Biji/porsi memerlukan stok terbuka atau siap jual; buka kemasan terlebih dahulu.');
            }
            $key = $batch->id.':'.$bucket;
            $left = $projected[$key] ?? $batch->{$bucket.'_pieces'};
            abort_unless($left >= $allocation['pieces'], 409, 'Stok batch/bucket tidak cukup. Muat ulang keranjang.');
            $projected[$key] = $left - $allocation['pieces'];
            $result[] = [...$allocation, 'batch_code' => $batch->batch_code, 'expires_on' => $batch->expires_on->format('Y-m-d'), 'batch_pack_size' => $batch->pack_size];
        }
        if ($debit) {
            abort_unless($actor !== null, 500);
            foreach ($result as $a) {
                abort_unless(isset($a['allocation_id']), 500);
                $this->ledger->apply($batches[$a['batch_id']], $actor, [$a['bucket'] => -$a['pieces']], 'pos_sale', $a['allocation_id'], 'Checkout POS tunai');
            }
        }

        return $result;
    }
}
