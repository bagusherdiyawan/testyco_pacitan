<?php

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Inventory\Infrastructure\InventoryBatch;

final class BatchCatalogue
{
    public function __construct(private Access $access) {}

    public function read(User $user, string $store, array $filters): array
    {
        $s = $this->access->store($user, $store);
        abort_if(isset($filters['expires_from'], $filters['expires_to']) && $filters['expires_from'] > $filters['expires_to'], 422, 'Tanggal akhir kedaluwarsa tidak boleh mendahului awal.');
        $q = InventoryBatch::where('store_id', $store);
        if (isset($filters['expires_from'])) {
            $q->where('expires_on', '>=', $filters['expires_from']);
        }
        if (isset($filters['expires_to'])) {
            $q->where('expires_on', '<=', $filters['expires_to']);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->where(fn ($q) => $q->where('sku', 'like', '%'.$filters['q'].'%')->orWhere('name', 'like', '%'.$filters['q'].'%')->orWhere('batch_code', 'like', '%'.$filters['q'].'%'));
        }
        if ($filters['with_balance'] ?? false) {
            $q->where(fn ($q) => $q->where('usable_pieces', '>', 0)->orWhere('opened_pieces', '>', 0)->orWhere('ready_pieces', '>', 0)->orWhere('quarantined_pieces', '>', 0));
        }

        return [...$q->orderBy('expires_on')->orderBy('id')->paginate(100)->toArray(),
            'store' => $s->only(['id', 'name', 'active']), 'observed_at' => now()->utc()->toIso8601String(),
        ];
    }
}
