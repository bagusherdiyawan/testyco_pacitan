<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;

final class UserAccountStatus
{
    public function __construct(private Access $access, private Operations $operations) {}

    private function membership(User $actor, string $org, int $id): object
    {
        $this->access->manage($actor, $org);
        $m = DB::table('organization_user')->where('organization_id', $org)->where('user_id', $id)->first();
        abort_unless($m, 404);

        return $m;
    }

    private function allowed(User $actor, int $id): bool
    {
        return $actor->id !== $id
            && ! DB::table('organization_user')->where('user_id', $id)->where('role', 'owner')->exists()
            && ($this->access->centralAdministrator($actor) || DB::table('organization_user')->where('user_id', $id)->count() === 1);
    }

    public function context(User $actor, string $org, int $id): array
    {
        $this->membership($actor, $org, $id);

        return DB::transaction(function () use ($actor, $org, $id) {
            $u = User::lockForUpdate()->findOrFail($id);
            $m = $this->membership($actor, $org, $id);
            $o = Organization::findOrFail($org);
            $stores = Store::where('organization_id', $org)->whereIn('id', DB::table('store_user')->where('user_id', $id)->select('store_id'))->orderBy('id')->get(['id', 'organization_id', 'name', 'active']);

            return ['organization' => $o->only(['id', 'name', 'active']), 'user' => [...$u->only(['id', 'name', 'email', 'active', 'access_version']), 'role' => $m->role, 'membership_count' => DB::table('organization_user')->where('user_id', $id)->count(), 'stores' => $stores->toArray()], 'can_reactivate' => ! $u->active && $o->active && $this->allowed($actor, $id)];
        });
    }

    public function deactivate(User $actor, string $org, int $id): void
    {
        $this->membership($actor, $org, $id);
        DB::transaction(function () use ($actor, $org, $id) {
            $u = User::lockForUpdate()->findOrFail($id);
            $this->membership($actor, $org, $id);
            abort_unless($this->allowed($actor, $id), 403);
            $before = $u->only(['active', 'access_version']);
            $u->forceFill(['active' => false, 'access_version' => $u->access_version + 1])->save();
            $u->tokens()->delete();
            Audit::record($actor->id, $org, 'user.deactivated', (string) $id, $before, $u->only(['active', 'access_version']));
        });
    }

    public function reactivate(User $actor, string $org, int $id, array $v): array
    {
        $this->membership($actor, $org, $id);
        abort_unless($this->allowed($actor, $id), 403);

        return $this->operations->run($org, $actor->id, $v['operation_id'], 'user.reactivate:'.$id, $v, function () use ($actor, $org, $id, $v) {
            $u = User::lockForUpdate()->findOrFail($id);
            $this->membership($actor, $org, $id);
            abort_unless($this->allowed($actor, $id), 403);
            abort_unless(Organization::whereKey($org)->where('active', true)->exists(), 409, 'Organisasi nonaktif.');
            abort_unless(! $u->active && $u->access_version === (int) $v['version'], 409, 'Status atau penugasan akun berubah. Muat ulang dan tinjau kembali.');
            $before = $u->only(['active', 'access_version']);
            $u->forceFill(['active' => true, 'access_version' => $u->access_version + 1])->save();
            $u->tokens()->delete();
            Audit::record($actor->id, $org, 'user.reactivated', (string) $id, $before, [...$u->only(['active', 'access_version']), 'reason' => $v['reason']]);

            return ['organization_id' => $org, 'user' => $u->only(['id', 'name', 'email', 'active', 'access_version'])];
        });
    }
}
