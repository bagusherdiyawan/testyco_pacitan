<?php

namespace App\Modules\Manufacturing\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;

final class ErpAccess
{
    public function __construct(private Access $access) {}

    public function manage(User $user, string $org): void
    {
        abort_unless(Organization::whereKey($org)->where('type', 'center')->where('active', true)->exists() && in_array($this->access->role($user, $org)?->value, ['owner', 'admin'], true), 403, 'ERP produksi hanya owner/admin pusat dengan keanggotaan nyata.');
    }

    public function store(User $user, string $org, string $id): Store
    {
        $this->manage($user, $org);
        $s = $this->access->operatingStore($user, $id, ['owner', 'admin']);
        abort_unless($s->organization_id === $org, 403, 'Gudang berbeda organisasi.');

        return $s;
    }
}
