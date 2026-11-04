<?php

namespace App\Modules\Reliability\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Inbox
{
    public function __construct(private Access $access) {}

    public function emit(string $key, string $org, ?string $store, string $type, string $title, string $path, array $roles = ['owner', 'admin', 'partner_lead']): void
    {
        $users = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')->where('organization_id', $org)->whereIn('role', $roles)->where('users.active', true)->get(['users.id', 'organization_user.role']);
        foreach ($users as $u) {
            if ($store && ! in_array($u->role, ['owner', 'admin', 'partner_lead'], true) && ! DB::table('store_user')->where('store_id', $store)->where('user_id', $u->id)->exists()) {
                continue;
            }$id = (string) Str::ulid();
            $inserted = DB::table('inbox_entries')->insertOrIgnore(['id' => $id, 'organization_id' => $org, 'user_id' => $u->id, 'store_id' => $store, 'event_key' => $key, 'event_type' => $type, 'title' => $title, 'local_path' => $path, 'roles' => json_encode($roles), 'created_at' => now()]);
            if ($inserted && DB::table('notification_preferences')->where('user_id', $u->id)->where('email_enabled', true)->exists()) {
                DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(), 'inbox_entry_id' => $id, 'status' => 'queued', 'next_attempt_at' => now()]);
            }
        }
    }

    public function capture(string $id, ?string $org, string $action, string $subject, ?array $after): void
    {
        if (! $org) {
            return;
        }$roles = ['owner', 'admin', 'partner_lead'];
        $titles = ['order.created' => 'Pengajuan pesanan perlu ditinjau', 'order.approve' => 'Pesanan telah disetujui', 'order.reject' => 'Pesanan ditolak', 'shipment.dispatched' => 'Kiriman perlu diperiksa penerima', 'shipment.received' => 'Penerimaan kiriman dicatat', 'billing.invoice_proposed' => 'Usulan tagihan perlu ditinjau', 'billing.credit_proposed' => 'Usulan kredit perlu ditinjau', 'billing.payment_verified' => 'Pembayaran terverifikasi dicatat', 'stock_count.created' => 'Pengajuan hitung/susut perlu ditinjau', 'asset.placement_proposed' => 'Usulan serah terima aset perlu ditinjau', 'asset.incident_reported' => 'Insiden aset perlu ditindaklanjuti', 'food.recalled' => 'Recall batch aktif: periksa stok', 'food.sop_approved' => 'SOP stok diperbarui'];
        if (! isset($titles[$action])) {
            return;
        }$store = null;
        $path = '/app/organizations/'.$org;
        if (str_starts_with($action, 'order.')) {
            $order = SupplyOrder::find($subject);
            if (! $order) {
                return;
            }$store = $org === $order->organization_id ? $order->store_id : null;
            $path = '/app/orders/'.$order->id;
            if ($action === 'order.approve' && $order->supplier_organization_id !== $org) {
                $this->emit('audit:'.$id.':supplier', $order->supplier_organization_id, null, $action, 'Pesanan baru siap diproses pemasok', $path, ['owner', 'admin']);
            }
        } elseif (str_starts_with($action, 'shipment.')) {
            $order = SupplyOrder::find($after['supply_order_id'] ?? '');
            if (! $order) {
                return;
            }$org = $order->organization_id;
            $store = $order->store_id;
            $path = '/app/orders/'.$order->id;
            $roles = ['owner', 'admin', 'partner_lead', 'store_manager', 'warehouse'];
        } elseif (str_starts_with($action, 'billing.')) {
            $orderId = $after['supply_order_id'] ?? DB::table('invoices')->where('id', $after['invoice_id'] ?? '')->value('supply_order_id');
            $path = $orderId ? '/app/orders/'.$orderId.'/billing' : '/app/organizations/'.$org;
        } elseif (str_starts_with($action, 'asset.')) {
            $store = $after['store_id'] ?? null;
            $path = '/app/assets/'.$subject;
        } elseif ($action === 'stock_count.created') {
            $store = $after['store_id'] ?? null;
            $path = '/app/stores/'.$store.'/operations';
        } elseif (str_starts_with($action, 'food.')) {
            $path = '/app/organizations/'.$org.'/food-safety';
        }
        $this->emit('audit:'.$id, $org, $store, $action, $titles[$action], $path, $roles);
    }

    public function lowStock(string $store, string $product): void
    {
        $minimum = DB::table('inventory_minimums')->where('store_id', $store)->where('product_id', $product)->value('minimum_pieces');
        if ($minimum === null) {
            return;
        }$org = DB::table('stores')->where('id', $store)->value('organization_id');
        $balance = (int) DB::table('inventory_batches')->where('store_id', $store)->where('product_id', $product)->selectRaw('SUM(usable_pieces+opened_pieces+ready_pieces) as available')->value('available');
        DB::table('stock_warning_states')->insertOrIgnore(['store_id' => $store, 'product_id' => $product, 'is_low' => false, 'episode' => 0]);
        $state = DB::table('stock_warning_states')->where('store_id', $store)->where('product_id', $product)->lockForUpdate()->first();
        $low = $balance < (int) $minimum;
        $episode = (int) $state->episode;
        if ($low && ! $state->is_low) {
            $episode++;
            $this->emit("stock-low:$store:$product:$episode", $org, $store, 'stock.low', 'Stok di bawah ambang yang dikonfigurasi', '/app/stores/'.$store.'/operations', ['owner', 'admin', 'partner_lead', 'store_manager', 'warehouse']);
        }DB::table('stock_warning_states')->where('store_id', $store)->where('product_id', $product)->update(['is_low' => $low, 'episode' => $episode]);
    }

    private function allowed(User $u, mixed $r): bool
    {
        if ((int) $r->user_id !== $u->id) {
            return false;
        }$role = $this->access->role($u, $r->organization_id)?->value;
        if (! $role || ! in_array($role, json_decode($r->roles, true), true)) {
            return false;
        }if ($r->store_id) {
            try {
                $this->access->store($u, $r->store_id);
            } catch (HttpException) {
                return false;
            }
        }

        return true;
    }

    public function read(User $u, int $page): array
    {
        $q = DB::table('inbox_entries as i')->join('organization_user as m', function ($j) {
            $j->on('m.organization_id', '=', 'i.organization_id')->on('m.user_id', '=', 'i.user_id');
        })->join('organizations as o', 'o.id', '=', 'i.organization_id')->where('i.user_id', $u->id)->where('o.active', true)->where(function ($q) {
            foreach (['owner', 'admin', 'partner_lead', 'store_manager', 'warehouse', 'cashier'] as $role) {
                $q->orWhere(fn ($x) => $x->where('m.role', $role)->whereJsonContains('i.roles', $role));
            }
        })->where(function ($q) use ($u) {
            $q->whereNull('i.store_id')->orWhereIn('m.role', ['owner', 'admin', 'partner_lead'])->orWhereExists(fn ($x) => $x->selectRaw('1')->from('store_user as su')->where('su.user_id', $u->id)->whereColumn('su.store_id', 'i.store_id'));
        });
        $unread = (clone $q)->whereNull('i.read_at')->count();
        $paginator = $q->select(['i.id', 'i.organization_id', 'i.store_id', 'i.event_type', 'i.title', 'i.local_path', 'i.created_at', 'i.read_at'])->orderByDesc('i.id')->paginate(50, ['*'], 'page', $page)->toArray();
        $p = DB::table('notification_preferences')->where('user_id', $u->id)->first();

        return [...$paginator, 'user_id' => $u->id, 'unread_count' => $unread, 'preferences' => ['version' => (int) ($p?->version ?? 0), 'email_enabled' => (bool) ($p?->email_enabled ?? false)], 'smtp_configured' => (bool) config('reliability.smtp_enabled') && config('mail.default') === 'smtp'];
    }

    public function mark(User $u, string $id): array
    {
        return DB::transaction(function () use ($u, $id) {
            $r = DB::table('inbox_entries')->lockForUpdate()->find($id);
            abort_unless($r && $this->allowed($u, $r), 404);
            $at = $r->read_at ?? now()->toDateTimeString();
            DB::table('inbox_entries')->where('id', $id)->update(['read_at' => $at]);

            return ['id' => $id, 'user_id' => $u->id, 'read_at' => $at];
        });
    }

    public function preferences(User $u, array $v): array
    {
        return DB::transaction(function () use ($u, $v) {
            User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            $p = DB::table('notification_preferences')->where('user_id', $u->id)->first();
            abort_unless((int) ($p?->version ?? 0) === (int) $v['version'], 409);
            $d = ['user_id' => $u->id, 'version' => (int) $v['version'] + 1, 'email_enabled' => $v['email_enabled'], 'updated_at' => now()];
            DB::table('notification_preferences')->updateOrInsert(['user_id' => $u->id], $d);

            return ['version' => $d['version'], 'email_enabled' => $d['email_enabled']];
        });
    }

    public function deliver(int $limit): array
    {
        abort_unless(config('reliability.smtp_enabled') && config('mail.default') === 'smtp', 503, 'SMTP belum diaktifkan.');
        $sent = 0;
        $failed = 0;
        for ($n = 0; $n < $limit; $n++) {
            $lease = Str::random(32);
            $delivery = DB::transaction(function () use ($lease) {
                $r = DB::table('notification_deliveries')->whereIn('status', ['queued', 'retry'])->where('attempts', '<', 5)->where('next_attempt_at', '<=', now())->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('id')->lockForUpdate()->first();
                if (! $r) {
                    return null;
                }DB::table('notification_deliveries')->where('id', $r->id)->update(['lease_until' => now()->addMinutes(2), 'lease_token' => $lease, 'attempts' => $r->attempts + 1]);

                return $r;
            });
            if (! $delivery) {
                break;
            }$e = DB::table('inbox_entries')->find($delivery->inbox_entry_id);
            $u = $e ? User::find($e->user_id) : null;
            try {
                if (! $u || ! $u->active || ! $this->allowed($u, $e) || ! DB::table('notification_preferences')->where('user_id', $u->id)->where('email_enabled', true)->exists()) {
                    $status = 'cancelled';
                } else {
                    Mail::raw('Ada pemberitahuan Testyco baru. Masuk ke aplikasi untuk meninjaunya. Email ini tidak menyertakan data transaksi.', fn ($m) => $m->to($u->email)->subject('Pemberitahuan Testyco'));
                    $status = 'sent';
                    $sent++;
                }DB::table('notification_deliveries')->where('id', $delivery->id)->where('lease_token', $lease)->update(['status' => $status, 'sent_at' => $status === 'sent' ? now() : null, 'lease_until' => null, 'lease_token' => null, 'error_code' => null]);
            } catch (\Throwable) {
                $failed++;
                DB::table('notification_deliveries')->where('id', $delivery->id)->where('lease_token', $lease)->update(['status' => $delivery->attempts + 1 >= 5 ? 'failed' : 'retry', 'next_attempt_at' => now()->addMinutes(2 ** min(5, $delivery->attempts)), 'lease_until' => null, 'lease_token' => null, 'error_code' => 'transport_failed']);
            }
        }

        return compact('sent', 'failed');
    }
}
