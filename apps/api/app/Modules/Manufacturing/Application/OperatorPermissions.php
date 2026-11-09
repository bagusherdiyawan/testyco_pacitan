<?php

namespace App\Modules\Manufacturing\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperatorPermissions
{
    public function set(User $actor, string $org, array $v): array
    {
        [$userId,$storeId] = explode(':', $v['assignment']);
        $key = ['organization_id' => $org, 'store_id' => $storeId, 'user_id' => (int) $userId];
        $old = DB::table('erp_operator_grants')->where($key)->lockForUpdate()->first();
        $store = Store::where('organization_id', $org)->findOrFail($storeId);
        $user = User::findOrFail((int) $userId);
        if ($v['enabled'] || ! $old) {
            abort_unless($store->active && $user->active && in_array(app(Access::class)->role($user, $org)?->value, ['warehouse', 'store_manager'], true) && DB::table('store_user')->where('user_id', $user->id)->where('store_id', $store->id)->exists(), 403, 'Operator harus anggota gudang/kepala toko aktif dengan penugasan gudang nyata.');
        }
        abort_unless(($old?->version ?? 0) === ($v['grant_versions'][$v['assignment']] ?? 0), 409, 'Izin berubah. Tinjau ulang.');
        $data = ['id' => $old?->id ?? (string) Str::ulid(), ...$key, 'enabled' => $v['enabled'], 'version' => ($old?->version ?? 0) + 1, 'granted_by' => $actor->id, 'reason' => $v['note'], 'updated_at' => now()];
        if ($old) {
            DB::table('erp_operator_grants')->where('id', $old->id)->update($data);
        } else {
            DB::table('erp_operator_grants')->insert([...$data, 'created_at' => now()]);
        }

        return $data;
    }
}
