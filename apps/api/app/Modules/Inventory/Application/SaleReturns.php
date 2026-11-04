<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\InventoryBatch;

final class SaleReturns
{
    public function __construct(private StockLedger $ledger) {}

    public function batches(string $org, string $store, array $ids): array
    {
        return InventoryBatch::where('organization_id', $org)->where('store_id', $store)->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    public function quarantine(string $org, string $store, int $actor, array $allocations, string $note): void
    {
        usort($allocations, fn ($a, $b) => strcmp($a['batch_id'], $b['batch_id']));
        foreach ($allocations as $a) {
            $b = InventoryBatch::where('organization_id', $org)->where('store_id', $store)->lockForUpdate()->findOrFail($a['batch_id']);
            $this->ledger->apply($b, $actor, ['quarantined' => $a['pieces']], 'sale_refund', $a['source_id'], $note);
        }
    }
}
