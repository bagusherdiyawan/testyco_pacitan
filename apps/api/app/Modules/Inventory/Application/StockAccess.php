<?php

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;

final class StockAccess
{
    public const STAFF = ['owner', 'admin', 'warehouse', 'partner_lead', 'store_manager'];

    public const PROCESS_STAFF = ['owner', 'admin', 'warehouse', 'partner_lead', 'store_manager', 'cashier'];

    public function __construct(private Access $access) {}

    public function staff(User $user, string $store, bool $operating = false): Store
    {
        return $operating ? $this->access->operatingStore($user, $store, self::STAFF) : $this->access->store($user, $store, self::STAFF);
    }

    public function processStaff(User $user, string $store, bool $operating = false): Store
    {
        return $operating ? $this->access->operatingStore($user, $store, self::PROCESS_STAFF) : $this->access->store($user, $store, self::PROCESS_STAFF);
    }

    public function isStaff(User $user, Store $store): bool
    {
        return in_array($this->access->role($user, $store->organization_id)?->value, self::STAFF, true);
    }

    public function canReview(User $user, Store $store): bool
    {
        $type = Organization::findOrFail($store->organization_id)->type;

        return in_array($this->access->role($user, $store->organization_id)?->value, $type === 'center' ? ['owner', 'admin'] : ['partner_lead'], true);
    }

    public function reviewer(User $user, Store $store, int $proposer): void
    {
        abort_unless($this->canReview($user, $store), 403, 'Penyesuaian harus ditinjau pimpinan yang berwenang.');
        abort_if($user->id === $proposer, 403, 'Pengusul tidak dapat menyetujui atau menolak opname sendiri.');
    }
}
