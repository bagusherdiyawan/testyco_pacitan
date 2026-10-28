<?php

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;

final class ReportAccess
{
    public function __construct(private Access $access) {}

    public function scope(User $user, string $organization, ?string $store): array
    {
        $org = Organization::findOrFail($organization);
        abort_unless(in_array($this->access->role($user, $organization)?->value, ['owner', 'admin', 'partner_lead', 'store_manager'], true), 403, 'Tidak berwenang membaca laporan organisasi.');
        $stores = $this->access->stores($user)->where('organization_id', $organization)->orderBy('name')->get(['id', 'name', 'active'])->toArray();
        if ($store !== null) {
            abort_unless(in_array($store, array_column($stores, 'id'), true), 403, 'Filter toko di luar cakupan laporan.');
        }

        return ['organization' => $org->only(['id', 'name']), 'available_stores' => $stores, 'store_ids' => $store === null ? array_column($stores, 'id') : [$store]];
    }
}
