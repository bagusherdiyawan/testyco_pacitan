<?php

namespace App\Modules\Manufacturing\Application;

use App\Models\User;
use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Manufacturing\Infrastructure\Production;
use Illuminate\Support\Facades\DB;

final class ErpAccess
{
    public function __construct(private Access $access) {}

    public function manage(User $user, string $org): void
    {
        abort_unless(Organization::whereKey($org)->where('type', 'center')->where('active', true)->exists() && in_array($this->access->role($user, $org)?->value, ['owner', 'admin'], true), 403, 'ERP produksi hanya owner/admin pusat dengan keanggotaan nyata.');
    }

    public function manager(User $u, string $org): bool
    {
        return Organization::whereKey($org)->where('type', 'center')->where('active', true)->exists() && in_array($this->access->role($u, $org)?->value, ['owner', 'admin'], true);
    }

    public function operator(User $u, string $org, string $store): void
    {
        abort_unless(Organization::whereKey($org)->where('type', 'center')->where('active', true)->exists() && in_array($this->access->role($u, $org)?->value, ['warehouse', 'store_manager'], true), 403);
        $s = $this->access->operatingStore($u, $store, ['warehouse', 'store_manager']);
        abort_unless($s->organization_id === $org && DB::table('erp_operator_grants')->where('organization_id', $org)->where('store_id', $store)->where('user_id', $u->id)->where('enabled', true)->exists(), 403, 'Izin produksi gudang belum diberikan atau sudah dicabut.');
    }

    public function command(User $u, string $org, string $action, array $v): void
    {
        if (in_array($action, AccountingAccess::ACTIONS, true)) {
            app(AccountingAccess::class)->manage($u, $org);

            return;
        }
        if ($this->manager($u, $org)) {
            return;
        }
        abort_unless(in_array($action, ['production', 'complete', 'production_cancel'], true), 403);
        $store = $action === 'production' ? ($v['store_id'] ?? '') : Production::where('organization_id', $org)->findOrFail($v['id'])->store_id;
        $this->operator($u, $org, $store);
    }

    public function store(User $user, string $org, string $id): Store
    {
        if ($this->manager($user, $org)) {
            $s = $this->access->operatingStore($user, $id, ['owner', 'admin']);
        } else {
            $this->operator($user, $org, $id);
            $s = Store::findOrFail($id);
        }
        abort_unless($s->organization_id === $org, 403, 'Gudang berbeda organisasi.');

        return $s;
    }
}
