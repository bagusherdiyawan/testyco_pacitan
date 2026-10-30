<?php

namespace App\Modules\Sales\Application;

use App\Models\User;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Shifts\Application\PosAccess;
use Carbon\CarbonImmutable;

final class ReceiptHistory
{
    public function __construct(private PosAccess $access) {}

    public function read(User $user, string $store, array $filters): array
    {
        $s = $this->access->store($user, $store, false);
        $all = $this->access->canManage($user, $s->organization_id);
        $q = Sale::where('organization_id', $s->organization_id)->where('store_id', $store);
        if (! $all) {
            $q->where('cashier_id', $user->id);
        }
        if (isset($filters['from'], $filters['to'])) {
            $from = CarbonImmutable::parse($filters['from'])->utc();
            $to = CarbonImmutable::parse($filters['to'])->utc();
            abort_if($to->timestamp - $from->timestamp > 31 * 86400, 422, 'Rentang riwayat maksimal 31 hari.');
            $q->where('sold_at', '>=', $from)->where('sold_at', '<', $to);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->where('number', 'like', '%'.$filters['q'].'%');
        }

        return [...$q->orderByDesc('id')->paginate(50)->toArray(),
            'store' => ['id' => $s->id, 'name' => $s->name, 'active' => $s->active],
            'scope' => $all ? 'store' : 'own',
        ];
    }
}
