<?php

namespace App\Modules\Promotions\Application;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Promotions\Infrastructure\Voucher;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Shared\Application\Operations;
use App\Shared\Domain\Proportion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VoucherBook
{
    public function __construct(private Access $access, private Operations $ops) {}

    private function manager(User $u, string $org): void
    {
        abort_unless(in_array($this->access->role($u, $org)?->value, ['owner', 'admin', 'partner_lead'], true), 403);
    }

    public function index(User $u, string $org): array
    {
        $this->manager($u, $org);

        return ['organization_id' => $org, ...Voucher::where('organization_id', $org)->orderByDesc('id')->paginate(50)->toArray()];
    }

    public function create(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'voucher.create:'.$org, $v, function () use ($u, $org, $v) {
            $this->manager($u, $org);
            Organization::whereKey($org)->lockForUpdate()->firstOrFail();
            abort_if(Voucher::where('organization_id', $org)->where('code', $v['code'])->exists(), 409, 'Kode voucher sudah digunakan.');
            abort_unless($v['funding_organization_id'] === $org, 422, 'Pendanaan harus eksplisit oleh penerbit sendiri; klaim lintas organisasi belum aktif.');
            foreach ($v['store_ids'] as $id) {
                abort_unless(Store::where('organization_id', $org)->where('active', true)->whereKey($id)->exists(), 422);
            }foreach ($v['product_ids'] as $id) {
                abort_unless(Product::where('organization_id', $org)->where('active', true)->whereKey($id)->exists(), 422);
            }if ($v['kind'] === 'percent') {
                abort_unless((int) $v['value'] <= 10000, 422);
            }$voucher = Voucher::create(['organization_id' => $org, 'created_by' => $u->id, 'version' => 1, 'active' => true, 'used_count' => 0, ...array_intersect_key($v, array_flip(['code', 'funding_organization_id', 'kind', 'value', 'maximum_discount', 'minimum_subtotal', 'usage_limit', 'starts_on', 'ends_on', 'store_ids', 'product_ids', 'restore_policy', 'terms']))]);
            Audit::record($u->id, $org, 'voucher.created', $voucher->id, null, $voucher->toArray());

            return $voucher->toArray();
        });
    }

    public function status(User $u, string $org, string $id, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'voucher.status:'.$id, $v, function () use ($u, $org, $id, $v) {
            $q = Voucher::where('organization_id', $org)->lockForUpdate()->findOrFail($id);
            abort_unless($q->version === (int) $v['version'], 409);
            $before = $q->toArray();
            $q->fill(['active' => $v['active'], 'version' => $q->version + 1])->save();
            Audit::record($u->id, $org, 'voucher.status_changed', $id, $before, [...$q->toArray(), 'reason' => $v['reason']]);

            return $q->toArray();
        });
    }

    public function quote(string $org, string $store, array $items, ?string $code, bool $lock = false): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        abort_unless(app()->getProvider(PromotionsServiceProvider::class), 409, 'Modul voucher nonaktif.');
        $q = Voucher::where('organization_id', $org)->where('code', $code);
        if ($lock) {
            $q->lockForUpdate();
        }$v = $q->firstOrFail();
        $today = now('Asia/Jakarta')->format('Y-m-d');
        abort_unless($v->active && $v->starts_on->format('Y-m-d') <= $today && $v->ends_on->format('Y-m-d') >= $today && in_array($store, $v->store_ids, true) && $v->used_count < $v->usage_limit, 409, 'Voucher tidak aktif, di luar lingkup/periode atau kuota habis.');
        $eligible = [];
        $subtotal = 0;
        foreach ($items as $item) {
            if (! $v->product_ids || in_array($item['product_id'], $v->product_ids, true)) {
                $eligible[] = $item;
                $subtotal += $item['line_total'];
            }
        }
        abort_unless($subtotal > 0 && $subtotal >= $v->minimum_subtotal, 422, 'Produk/subtotal tidak memenuhi voucher.');
        $discount = min($subtotal, $v->maximum_discount, $v->kind === 'fixed' ? $v->value : intdiv($subtotal * $v->value, 10000));
        abort_unless($discount > 0, 422, 'Diskon voucher nol.');
        $splits = [];
        $left = $discount;
        foreach ($eligible as $item) {
            $x = Proportion::floor($discount, $item['line_total'], $subtotal);
            $splits[$item['product_id']] = $x;
            $left -= $x;
        }foreach ($eligible as $item) {
            if ($left === 0) {
                break;
            }$key = $item['product_id'];
            $take = min($left, $item['line_total'] - $splits[$key]);
            $splits[$key] += $take;
            $left -= $take;
        }

        return ['id' => $v->id, 'code' => $v->code, 'version' => $v->version, 'discount_amount' => $discount, 'line_discounts' => $splits, 'funding_organization_id' => $v->funding_organization_id, 'restore_policy' => $v->restore_policy, 'terms' => $v->terms];
    }

    public function redeem(array $snapshot, string $sale): void
    {
        $v = Voucher::lockForUpdate()->findOrFail($snapshot['id']);
        abort_unless($v->used_count < $v->usage_limit, 409);
        $v->increment('used_count');
        DB::table('voucher_redemptions')->insert(['id' => (string) Str::ulid(), 'voucher_id' => $v->id, 'sale_id' => $sale, 'discount_amount' => $snapshot['discount_amount'], 'redeemed_at' => now()]);
    }

    public function restore(?array $snapshot, string $sale, bool $fullyRefunded): void
    {
        if (! $snapshot || ! $fullyRefunded || $snapshot['restore_policy'] !== 'on_full_refund') {
            return;
        }$v = Voucher::lockForUpdate()->findOrFail($snapshot['id']);
        $r = DB::table('voucher_redemptions')->where('sale_id', $sale)->lockForUpdate()->first();
        if ($r && $r->restored_at === null) {
            abort_unless($v->used_count > 0, 409);
            $v->decrement('used_count');
            DB::table('voucher_redemptions')->where('id', $r->id)->update(['restored_at' => now()]);
        }
    }
}
