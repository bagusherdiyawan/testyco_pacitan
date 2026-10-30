<?php

namespace App\Modules\Identity\Presentation;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class IdentityController
{
    public function __construct(private Access $access) {}

    public function organizations(Request $r): mixed
    {
        $q = Organization::query();
        if (! $this->access->centralAdministrator($r->user())) {
            $q->whereIn('id', DB::table('organization_user')->where('user_id', $r->user()->id)->select('organization_id'));
        }

        return $q->orderBy('name')->paginate(50)->through(function ($org) use ($r) {
            $role = $this->access->role($r->user(), $org->id);

            return [...$org->toArray(), 'role' => $role?->value, 'can_activate' => $this->access->centralAdministrator($r->user()), 'can_manage' => ($role?->manages() ?? false) || $this->access->centralAdministrator($r->user()), 'can_catalog' => $role?->manages() ?? false];
        });
    }

    public function organization(Request $r, string $organization): mixed
    {
        $this->access->manage($r->user(), $organization);

        return ['data' => Organization::findOrFail($organization)];
    }

    public function createOrganization(Request $r): mixed
    {
        abort_unless($this->access->centralAdministrator($r->user()), 403);
        $v = $r->validate(['name' => 'required|string|max:120', 'contact' => 'nullable|string|max:120', 'address' => 'nullable|string|max:2000']);

        return DB::transaction(function () use ($r, $v) {
            $org = Organization::create([...$v, 'type' => 'partner']);
            Audit::record($r->user()->id, $org->id, 'organization.created', $org->id, null, $org->toArray());

            return response()->json(['data' => $org], 201);
        });
    }

    public function updateOrganization(Request $r, string $organization): mixed
    {
        abort_unless($this->access->centralAdministrator($r->user()), 403);
        $v = $r->validate(['name' => 'sometimes|required|string|max:120', 'active' => 'sometimes|boolean', 'contact' => 'nullable|string|max:120', 'address' => 'nullable|string|max:2000']);

        return DB::transaction(function () use ($r, $organization, $v) {
            $org = Organization::lockForUpdate()->findOrFail($organization);
            abort_if($org->type === 'center' && array_key_exists('active', $v) && ! $v['active'], 409, 'Pusat tidak dapat dinonaktifkan melalui endpoint ini.');
            $before = $org->toArray();
            $org->update($v);
            Audit::record($r->user()->id, $organization, 'organization.updated', $organization, $before, $org->toArray());

            return ['data' => $org];
        });
    }

    public function stores(Request $r): mixed
    {
        $stores = $this->access->stores($r->user())->orderBy('name')->paginate(100);
        $types = Organization::whereIn('id', $stores->getCollection()->pluck('organization_id'))->pluck('type', 'id');

        return $stores->through(fn ($s) => [...$s->toArray(), 'organization_type' => $types[$s->organization_id], 'role' => $this->access->role($r->user(), $s->organization_id)?->value]);
    }

    public function organizationStores(Request $r, string $organization): mixed
    {
        $this->access->manage($r->user(), $organization);

        return Store::where('organization_id', $organization)->orderBy('name')->paginate(100);
    }

    public function createStore(Request $r, string $organization): mixed
    {
        $this->access->manage($r->user(), $organization);
        $v = $r->validate(['name' => 'required|string|max:120', 'address' => 'nullable|string|max:2000', 'region' => 'nullable|string|max:80']);
        abort_unless(Organization::findOrFail($organization)->active, 409, 'Organisasi nonaktif.');

        return DB::transaction(function () use ($r, $organization, $v) {
            $store = Store::create([...$v, 'organization_id' => $organization]);
            Audit::record($r->user()->id, $organization, 'store.created', $store->id, null, $store->toArray());

            return response()->json(['data' => $store], 201);
        });
    }

    public function updateStore(Request $r, string $store): mixed
    {
        $record = Store::findOrFail($store);
        $this->access->manage($r->user(), $record->organization_id);
        $v = $r->validate(['name' => 'sometimes|required|string|max:120', 'address' => 'nullable|string|max:2000', 'region' => 'nullable|string|max:80', 'active' => 'sometimes|boolean']);

        return DB::transaction(function () use ($r, $record, $v) {
            $record = Store::lockForUpdate()->findOrFail($record->id);
            $before = $record->toArray();
            $record->update($v);
            Audit::record($r->user()->id, $record->organization_id, 'store.updated', $record->id, $before, $record->toArray());

            return ['data' => $record];
        });
    }

    public function users(Request $r, string $organization): mixed
    {
        $this->access->manage($r->user(), $organization);

        return DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_id', $organization)->select('users.id', 'name', 'email', 'active', 'role')->orderBy('name')->paginate(100)->through(function ($user) use ($organization) {
            $user->store_ids = DB::table('store_user')->join('stores', 'stores.id', '=', 'store_user.store_id')->where('user_id', $user->id)->where('organization_id', $organization)->pluck('store_id')->all();

            return $user;
        });
    }

    public function createUser(Request $r, string $organization): mixed
    {
        $this->access->manage($r->user(), $organization);
        $org = Organization::findOrFail($organization);
        $roles = $org->type === 'center' ? ['admin', 'warehouse', 'store_manager', 'cashier'] : ['partner_lead', 'store_manager', 'cashier'];
        $r->merge(['email' => strtolower((string) $r->input('email'))]);
        $v = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()], 'role' => ['required', Rule::in($roles)], 'store_ids' => 'present|array|max:100', 'store_ids.*' => ['required', 'distinct', Rule::exists('stores', 'id')->where('organization_id', $organization)]]);

        return DB::transaction(function () use ($r, $organization, $v) {
            $user = User::create(['name' => $v['name'], 'email' => strtolower($v['email']), 'password' => $v['password']]);
            DB::table('organization_user')->insert(['organization_id' => $organization, 'user_id' => $user->id, 'role' => $v['role']]);
            foreach ($v['store_ids'] as $id) {
                DB::table('store_user')->insert(['store_id' => $id, 'user_id' => $user->id]);
            }
            Audit::record($r->user()->id, $organization, 'user.created', (string) $user->id, null, ['name' => $user->name, 'role' => $v['role'], 'store_ids' => $v['store_ids']]);

            return response()->json(['data' => $user], 201);
        });
    }

    public function updateMembership(Request $r, string $organization, string $user): mixed
    {
        $this->access->manage($r->user(), $organization);
        $membership = DB::table('organization_user')->where('organization_id', $organization)->where('user_id', $user)->first();
        abort_unless($membership, 404);
        abort_if($membership->role === 'owner' || (string) $r->user()->id === $user, 403, 'Akun pemilik dan akses sendiri tidak dapat diubah di sini.');
        $org = Organization::findOrFail($organization);
        $roles = $org->type === 'center' ? ['admin', 'warehouse', 'store_manager', 'cashier'] : ['partner_lead', 'store_manager', 'cashier'];
        $v = $r->validate(['role' => ['required', Rule::in($roles)], 'store_ids' => 'present|array|max:100', 'store_ids.*' => ['required', 'distinct', Rule::exists('stores', 'id')->where('organization_id', $organization)]]);

        return DB::transaction(function () use ($r, $organization, $user, $membership, $v) {
            DB::table('organization_user')->where('organization_id', $organization)->where('user_id', $user)->update(['role' => $v['role']]);
            $storeIds = Store::where('organization_id', $organization)->pluck('id');
            DB::table('store_user')->where('user_id', $user)->whereIn('store_id', $storeIds)->delete();
            foreach ($v['store_ids'] as $id) {
                DB::table('store_user')->insert(['store_id' => $id, 'user_id' => $user]);
            }
            User::findOrFail($user)->tokens()->delete();
            Audit::record($r->user()->id, $organization, 'membership.updated', $user, ['role' => $membership->role], $v);

            return response()->noContent();
        });
    }

    public function deactivateUser(Request $r, string $organization, string $user): mixed
    {
        $this->access->manage($r->user(), $organization);
        $m = DB::table('organization_user')->where('organization_id', $organization)->where('user_id', $user)->first();
        abort_unless($m, 404);
        abort_if($m->role === 'owner' || (string) $r->user()->id === $user, 403);
        // Account status is global. Only central admins can disable a multi-organization identity.
        abort_if(! $this->access->centralAdministrator($r->user()) && DB::table('organization_user')->where('user_id', $user)->count() > 1, 403);

        return DB::transaction(function () use ($r, $organization, $user) {
            $u = User::findOrFail($user);
            $u->forceFill(['active' => false])->save();
            $u->tokens()->delete();
            Audit::record($r->user()->id, $organization, 'user.deactivated', $user, ['active' => true], ['active' => false]);

            return response()->noContent();
        });
    }

    public function audit(Request $r, string $organization): mixed
    {
        $v = $r->validate([
            'page' => 'sometimes|integer|min:1',
            'action' => 'nullable|string|max:80',
            'subject_id' => 'nullable|string|max:80',
            'actor_id' => 'nullable|integer|min:1',
            'from' => ['nullable', 'required_with:to', 'string', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'to' => ['nullable', 'required_with:from', 'string', 'date', 'after:from', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
        ]);

        return response()->json(app(\App\Modules\Identity\Application\AuditHistory::class)->read($r->user(), $organization, $v))
            ->header('Cache-Control', 'private, no-store');
    }
}
