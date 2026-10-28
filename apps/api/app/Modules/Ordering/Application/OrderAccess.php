<?php

namespace App\Modules\Ordering\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderAccess
{
    public const RECIPIENT_ROLES = ['partner_lead', 'store_manager', 'owner', 'admin', 'warehouse'];

    public function __construct(private Access $access) {}

    public function recipient(User $user, string $store, bool $operating = false): Store
    {
        return $operating ? $this->access->operatingStore($user, $store, self::RECIPIENT_ROLES) : $this->access->store($user, $store, self::RECIPIENT_ROLES);
    }

    public function approver(User $user, SupplyOrder $order): void
    {
        $s = $this->recipient($user, $order->store_id, true);
        $role = $this->access->role($user, $s->organization_id)?->value;
        abort_unless(in_array($role, $order->kind === 'internal_transfer' ? ['owner', 'admin'] : ['partner_lead'], true), 403, 'Persetujuan harus oleh pimpinan yang berwenang.');
    }

    public function supplier(User $user, SupplyOrder $order): void
    {
        abort_unless($order->approved_at, 403, 'Pengajuan belum dikirim ke pusat.');
        $role = $this->access->role($user, $order->supplier_organization_id)?->value;
        if (in_array($role, ['owner', 'admin'], true)) {
            return;
        }
        abort_unless($role === 'warehouse' && $order->source_store_id, 403);
        $this->access->store($user, $order->source_store_id, ['warehouse']);
    }

    public function view(User $user, SupplyOrder $order): string
    {
        if ($this->access->role($user, $order->organization_id)) {
            try {
                $this->recipient($user, $order->store_id);

                return 'recipient';
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
            }
        }
        $this->supplier($user, $order);

        return 'supplier';
    }

    public function targetActive(SupplyOrder $order): void
    {
        abort_unless(Store::whereKey($order->store_id)->where('active', true)->exists() && Organization::whereKey($order->organization_id)->where('active', true)->exists(), 409, 'Toko atau organisasi tujuan nonaktif.');
    }

    public function source(User $user, SupplyOrder $order, string $store): Store
    {
        $source = $this->access->operatingStore($user, $store, ['owner', 'admin', 'warehouse']);
        abort_unless($source->organization_id === $order->supplier_organization_id && $source->id !== $order->store_id, 422, 'Gudang sumber harus milik pemasok dan berbeda dari toko tujuan.');

        return $source;
    }

    public function inbox(User $user)
    {
        $centers = Organization::where('type', 'center')->where('active', true)->select('id');
        $admin = DB::table('organization_user')->where('user_id', $user->id)->whereIn('role', ['owner', 'admin'])->whereIn('organization_id', $centers)->select('organization_id');
        $warehouses = $this->access->stores($user)->whereIn('organization_id', DB::table('organization_user')->where('user_id', $user->id)->where('role', 'warehouse')->whereIn('organization_id', $centers)->select('organization_id'))->select('id');

        return SupplyOrder::whereNotNull('approved_at')->where(fn ($q) => $q->whereIn('supplier_organization_id', $admin)->orWhereIn('source_store_id', $warehouses));
    }
}
