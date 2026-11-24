<?php

namespace App\Modules\Reliability\Application;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Inventory\Application\FoodSafety;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Application\Operations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FoodPolicy
{
    public function __construct(private Access $access, private Operations $ops) {}

    private function manager(User $u, string $org): void
    {
        abort_unless(in_array($this->access->role($u, $org)?->value, ['owner', 'admin', 'partner_lead'], true), 403);
    }

    public function context(User $u, string $org): array
    {
        $this->manager($u, $org);
        $p = DB::table('food_sops')->where('organization_id', $org)->first();

        return ['organization_id' => $org, 'policy' => $p ? ['organization_id' => $org, 'version' => (int) $p->version, 'enabled' => (bool) $p->enabled, 'block_expired' => (bool) $p->block_expired, 'fefo' => $p->fefo, 'ready_life_minutes' => $p->ready_life_minutes === null ? null : (int) $p->ready_life_minutes, 'reference' => $p->reference, 'approved_by' => $p->approved_by, 'approved_at' => $p->approved_at] : null, 'ready_batches' => InventoryBatch::where('organization_id', $org)->where('ready_pieces', '>', 0)->orderBy('id')->paginate(50, ['*'], 'ready_page')->toArray(), 'products' => Product::where('organization_id', $org)->orderBy('sku')->get(['id', 'sku', 'name'])->toArray(), 'recalls' => DB::table('batch_recalls')->where('organization_id', $org)->orderByDesc('id')->paginate(50)->toArray()];
    }

    public function save(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'food.policy:'.$org, $v, function () use ($u, $org, $v) {
            $this->manager($u, $org);
            Organization::whereKey($org)->lockForUpdate()->firstOrFail();
            $old = DB::table('food_sops')->where('organization_id', $org)->first();
            abort_unless((int) ($old?->version ?? 0) === (int) $v['version'], 409);
            $p = ['organization_id' => $org, 'version' => (int) $v['version'] + 1, 'enabled' => $v['enabled'], 'block_expired' => $v['block_expired'], 'fefo' => $v['fefo'], 'ready_life_minutes' => $v['ready_life_minutes'] ?? null, 'reference' => $v['reference'], 'approved_by' => $u->id, 'approved_at' => now()->format('Y-m-d H:i:s')];
            DB::table('food_sops')->updateOrInsert(['organization_id' => $org], $p);
            app(FoodSafety::class)->forget($org);
            Audit::record($u->id, $org, 'food.sop_approved', $org, null, $p);

            return $p;
        });
    }

    public function recall(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'food.recall:'.$org, $v, function () use ($u, $org, $v) {
            $this->manager($u, $org);
            Product::where('organization_id', $org)->lockForUpdate()->findOrFail($v['product_id']);
            abort_if(DB::table('batch_recalls')->where('organization_id', $org)->where('product_id', $v['product_id'])->where('batch_code', $v['batch_code'])->where('status', 'active')->exists(), 409, 'Recall aktif sudah ada.');
            abort_unless(InventoryBatch::where('organization_id', $org)->where('product_id', $v['product_id'])->where('batch_code', $v['batch_code'])->exists(), 422, 'Lot tidak ditemukan di organisasi ini.');
            InventoryBatch::where('organization_id', $org)->where('product_id', $v['product_id'])->where('batch_code', $v['batch_code'])->orderBy('id')->lockForUpdate()->get();
            $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'product_id' => $v['product_id'], 'batch_code' => $v['batch_code'], 'status' => 'active', 'version' => 1, 'reason' => $v['reason'], 'reference' => $v['reference'], 'issued_by' => $u->id, 'issued_at' => now()->format('Y-m-d H:i:s')];
            DB::table('batch_recalls')->insert($r);
            app(FoodSafety::class)->forget($org);
            Audit::record($u->id, $org, 'food.recalled', $r['id'], null, $r);

            return $r;
        });
    }

    public function withdraw(User $u, string $org, string $id, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'food.withdraw:'.$id, $v, function () use ($u, $org, $id, $v) {
            $this->manager($u, $org);
            $r = DB::table('batch_recalls')->where('organization_id', $org)->lockForUpdate()->find($id);
            abort_unless($r, 404);
            abort_unless($r->status === 'active' && (int) $r->version === (int) $v['version'], 409);
            $d = ['status' => 'withdrawn', 'version' => (int) $r->version + 1, 'withdrawn_by' => $u->id, 'withdrawn_at' => now()->format('Y-m-d H:i:s'), 'withdrawal_reason' => $v['reason']];
            DB::table('batch_recalls')->where('id', $id)->update($d);
            app(FoodSafety::class)->forget($org);
            $result = [...(array) $r, ...$d];
            Audit::record($u->id, $org, 'food.recall_withdrawn', $id, null, $result);

            return $result;
        });
    }

    public function ready(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'food.ready:'.$v['batch_id'], $v, function () use ($u, $org, $v) {
            $b = InventoryBatch::where('organization_id', $org)->lockForUpdate()->findOrFail($v['batch_id']);
            $p = DB::table('food_sops')->where('organization_id', $org)->where('enabled', true)->first();
            abort_unless($p && $p->ready_life_minutes !== null && $b->ready_pieces > 0 && $b->stock_revision === (int) $v['expected_revision'], 409, 'SOP atau revisi/biji siap tidak memenuhi.');
            $prepared = CarbonImmutable::parse($v['prepared_at']);
            abort_unless($prepared->lte(now()), 422, 'Waktu pengolahan tidak boleh di masa depan.');
            $expiry = $prepared->addMinutes((int) $p->ready_life_minutes);
            abort_if($b->ready_expires_at && $expiry->gt($b->ready_expires_at), 409, 'Tidak boleh memperpanjang masa simpan stok lama.');
            $b->forceFill(['ready_expires_at' => $expiry, 'stock_revision' => $b->stock_revision + 1])->save();
            $d = ['id' => $b->id, 'organization_id' => $org, 'stock_revision' => $b->stock_revision, 'ready_expires_at' => $b->ready_expires_at->toIso8601String(), 'reference' => $v['reference'], 'prepared_at' => $prepared->toIso8601String(), 'policy_version' => (int) $p->version, 'ready_life_minutes' => (int) $p->ready_life_minutes];
            Audit::record($u->id, $org, 'food.ready_reviewed', $b->id, null, $d);

            return $d;
        });
    }
}
