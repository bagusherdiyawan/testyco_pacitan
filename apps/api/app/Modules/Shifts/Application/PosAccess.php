<?php

namespace App\Modules\Shifts\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Store;

final class PosAccess
{
    public const CASHIERS = ['owner', 'admin', 'partner_lead', 'store_manager', 'cashier'];

    public const MANAGERS = ['owner', 'admin', 'partner_lead', 'store_manager'];

    public function __construct(private Access $access) {}

    public function store(User $user, string $id, bool $operating = true): Store
    {
        return $operating ? $this->access->operatingStore($user, $id, self::CASHIERS) : $this->access->store($user, $id, self::CASHIERS);
    }

    public function manager(User $user, string $id, bool $operating = true): Store
    {
        return $operating ? $this->access->operatingStore($user, $id, self::MANAGERS) : $this->access->store($user, $id, self::MANAGERS);
    }

    public function canManage(User $user, string $organization): bool
    {
        return in_array($this->access->role($user, $organization)?->value, self::MANAGERS, true);
    }
}
