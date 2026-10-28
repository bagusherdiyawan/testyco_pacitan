<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class Access
{
    public function role(User $user, string $organization): ?Role
    {
        if (! $user->active || ! Organization::whereKey($organization)->where('active', true)->exists()) {
            return null;
        }
        $role = DB::table('organization_user')->where('organization_id', $organization)->where('user_id', $user->id)->value('role');

        return $role ? Role::tryFrom($role) : null;
    }

    public function centralAdministrator(User $user): bool
    {
        return $user->active && DB::table('organization_user')->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')->where('user_id', $user->id)->where('organizations.type', 'center')->where('organizations.active', true)->whereIn('role', ['owner', 'admin'])->exists();
    }

    public function manage(User $user, string $organization): void
    {
        Organization::findOrFail($organization);
        abort_unless($this->role($user, $organization)?->manages() || $this->centralAdministrator($user), 403, 'Tidak berwenang mengelola organisasi.');
    }

    public function store(User $user, string $id, array $roles = []): Store
    {
        $store = Store::findOrFail($id);
        $role = $this->role($user, $store->organization_id);
        $assigned = $role?->organizationWide() || DB::table('store_user')->where('store_id', $id)->where('user_id', $user->id)->exists();
        abort_unless($role && $assigned && (! $roles || in_array($role->value, $roles, true)), 403, 'Akses toko atau tindakan ditolak.');

        return $store;
    }

    public function stores(User $user): Builder
    {
        return Store::whereIn('organization_id', DB::table('organization_user')->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')->where('organizations.active', true)->where('user_id', $user->id)->select('organization_id'))->where(function ($q) use ($user) {
            $q->whereIn('id', DB::table('store_user')->where('user_id', $user->id)->select('store_id'))->orWhereIn('organization_id', DB::table('organization_user')->where('user_id', $user->id)->whereIn('role', ['owner', 'admin', 'partner_lead'])->select('organization_id'));
        });
    }

    public function operatingStore(User $user, string $id, array $roles = []): Store
    {
        $store = $this->store($user, $id, $roles);
        abort_unless($store->active, 409, 'Toko nonaktif.');

        return $store;
    }
}
