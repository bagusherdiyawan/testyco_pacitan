<?php

namespace App\Modules\Sales\Application;

use App\Models\User;
use App\Modules\Catalog\Application\RetailPricing;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Application\FoodSafety;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Sales\Infrastructure\OfflinePermit;
use App\Modules\Sales\Infrastructure\OfflineSale;
use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBook;
use App\Modules\Shifts\Infrastructure\CashShift;
use App\Shared\Application\Operations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OfflineCash
{
    public function __construct(private PosAccess $access, private ShiftBook $shifts, private CashCheckout $checkout, private OfflinePricing $pricing, private RetailPricing $current, private Operations $ops) {}

    public function permit(User $u, string $store, array $v): array
    {
        $s = $this->access->store($u, $store);

        return $this->ops->run($s->organization_id, $u->id, $v['operation_id'], 'offline.permit:'.$store, $v, function () use ($u, $s, $store, $v) {
            $shift = $this->shifts->lockOwned($u, $store, $v['shift_id']);
            $this->shifts->assertOpen($shift);
            abort_if(OfflinePermit::where('cash_shift_id', $shift->id)->whereNull('closed_at')->exists(), 409, 'Akhiri izin offline sebelumnya.');
            $catalogue = $this->pricing->catalogue($s->organization_id);
            $batches = InventoryBatch::where('store_id', $store)->where(fn ($q) => $q->where('usable_pieces', '>', 0)->orWhere('opened_pieces', '>', 0)->orWhere('ready_pieces', '>', 0))->orderBy('id')->limit(2001)->get()->map(function ($b) {
                return [...$b->toArray(), 'blocked_buckets' => array_values(array_filter(['usable', 'opened', 'ready'], fn ($bucket) => ! app(FoodSafety::class)->eligible($b, $bucket)))];
            })->all();
            abort_if(count($catalogue) > 1000 || count($batches) > 2000, 422, 'Snapshot offline melebihi kapasitas perangkat; persempit data aktif.');
            $policy = app(FoodSafety::class)->policy($s->organization_id);
            $safety = ['block_expired' => (bool) ($policy?->block_expired ?? false), 'ready_life_minutes' => $policy?->ready_life_minutes === null ? null : (int) $policy->ready_life_minutes, 'fefo' => $policy?->fefo ?? 'off'];
            $snapshot = ['safety' => $safety, 'user_id' => $u->id, 'store' => $s->only(['id', 'organization_id', 'name', 'address']), 'cashier_name' => $u->name, 'products' => $catalogue, 'batches' => $batches, 'payment_methods' => ['cash'], 'vouchers_allowed' => false];
            $p = OfflinePermit::create(['organization_id' => $s->organization_id, 'store_id' => $store, 'cash_shift_id' => $shift->id, 'cashier_id' => $u->id, 'access_version' => (int) User::whereKey($u->id)->value('access_version'), 'snapshot' => $snapshot, 'issued_at' => now(), 'expires_at' => now()->addMinutes((int) $v['duration_minutes'])]);
            Audit::record($u->id, $s->organization_id, 'offline.permit_issued', $p->id, null, $p->only(['id', 'store_id', 'cash_shift_id', 'issued_at', 'expires_at']));

            return $p->toArray();
        });
    }

    public function end(User $u, string $id, array $v): array
    {
        $p = OfflinePermit::findOrFail($id);
        $s = $this->access->store($u, $p->store_id);
        abort_unless((int) $p->cashier_id === $u->id, 403);

        return $this->ops->run($s->organization_id, $u->id, $v['operation_id'], 'offline.end:'.$id, $v, function () use ($u, $id, $v) {
            $p = OfflinePermit::lockForUpdate()->findOrFail($id);
            abort_unless($v['queue_empty'] === true && ! OfflineSale::where('offline_permit_id', $id)->where('status', 'conflict')->exists(), 409, 'Sinkronkan dan selesaikan konflik sebelum mengakhiri izin.');
            if (! $p->closed_at) {
                $p->closed_at = now();
                $p->save();
                Audit::record($u->id, $p->organization_id, 'offline.permit_closed', $id, null, ['queue_empty_declared' => true]);
            }

            return $p->only(['id', 'closed_at']);
        });
    }

    private function view(User $u, OfflineSale $r): void
    {
        $s = $this->access->store($u, $r->store_id, false);
        abort_unless((int) $r->cashier_id === $u->id || $this->access->canManage($u, $s->organization_id), 403);
    }

    public function context(User $u, string $store): array
    {
        $s = $this->access->store($u, $store, false);
        $manager = $this->access->canManage($u, $s->organization_id);
        $q = OfflineSale::where('store_id', $store);
        if (! $manager) {
            $q->where('cashier_id', $u->id);
        }

        return [...$q->orderByDesc('id')->paginate(50)->toArray(), 'store_id' => $store, 'can_review' => $manager, 'permits' => OfflinePermit::where('store_id', $store)->where('cashier_id', $u->id)->whereNull('closed_at')->get()->toArray(), 'open_shifts' => $manager ? CashShift::where('store_id', $store)->where('status', 'open')->get()->toArray() : []];
    }

    public function show(User $u, string $id): array
    {
        $r = OfflineSale::findOrFail($id);
        $this->view($u, $r);

        return [...$r->toArray(), 'receipt' => $r->sale_id ? $this->checkout->receipt($r->sale_id) : null];
    }

    public function sync(User $u, string $store, array $v): array
    {
        $s = $this->access->store($u, $store);
        $p = OfflinePermit::where('store_id', $store)->where('cashier_id', $u->id)->findOrFail($v['permit_id']);

        return $this->ops->run($s->organization_id, $u->id, $v['operation_id'], 'offline.sync:'.$store, $v, function () use ($u, $store, $s, $p, $v) {
            $shift = CashShift::where('store_id', $store)->lockForUpdate()->findOrFail($p->cash_shift_id);
            $r = OfflineSale::create(['offline_permit_id' => $p->id, 'organization_id' => $s->organization_id, 'store_id' => $store, 'cash_shift_id' => $shift->id, 'cashier_id' => $u->id, 'operation_id' => $v['operation_id'], 'status' => 'received', 'version' => 1, 'payload' => $v, 'captured_at' => $v['captured_at'], 'received_at' => now()]);
            try {
                $posted = DB::transaction(function () use ($u, $p, $r, $v, $shift) {
                    $at = CarbonImmutable::parse($v['captured_at']);
                    abort_unless($p->access_version === (int) User::whereKey($u->id)->value('access_version') && $at->gte($p->issued_at) && $at->lte($p->expires_at) && $at->lte(now()->addMinutes(2)) && (! $p->closed_at || $at->lte($p->closed_at)), 409, 'Izin/waktu/versi akun perlu review.');
                    $snapshot = $this->pricing->snapshot($p->snapshot['products'], $v['lines']);
                    $r->snapshot = $snapshot;
                    $r->save();
                    abort_unless((int) $v['client_total'] === $snapshot['total_amount'] && (int) $v['cash_received'] >= $snapshot['total_amount'], 409, 'Nominal offline memerlukan review.');
                    $current = $this->current->snapshot($p->organization_id, $v['lines'], true);
                    abort_unless(hash_equals($snapshot['price_hash'], $current['price_hash']), 409, 'Harga/konversi/resep berubah; perlu review.');

                    return $this->checkout->postOffline($u, $r, $shift, $snapshot);
                });
                $r->refresh();
                $r->fill(['status' => 'accepted', 'sale_id' => $posted['id']])->save();
            } catch (\Throwable $e) {
                $known = $e instanceof \DomainException || $e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [403, 404, 409, 422], true);
                if (! $known) {
                    throw $e;
                }$r->refresh();
                try {
                    $snapshot = $this->pricing->snapshot($p->snapshot['products'], $v['lines']);
                } catch (\Throwable) {
                    $snapshot = null;
                }$r->fill(['status' => 'conflict', 'snapshot' => $snapshot, 'conflict_code' => 'review_required', 'conflict_message' => $e->getMessage()])->save();
            }Audit::record($u->id, $s->organization_id, 'offline.'.$r->status, $r->id, null, $r->only(['id', 'store_id', 'status', 'conflict_code']));

            return $r->toArray();
        });
    }

    public function resolve(User $u, string $id, string $action, array $v): array
    {
        $initial = OfflineSale::findOrFail($id);
        $s = $this->access->manager($u, $initial->store_id);

        return $this->ops->run($s->organization_id, $u->id, $v['operation_id'], 'offline.'.$action.':'.$id, $v, function () use ($u, $s, $id, $action, $v) {
            $shift = $action === 'accept' ? $this->shifts->refundShift($u, $s->id, $v['shift_id'], (int) $v['shift_version']) : null;
            $r = OfflineSale::where('store_id', $s->id)->lockForUpdate()->findOrFail($id);
            abort_unless($r->status === 'conflict' && $r->version === (int) $v['version'], 409);
            abort_unless($v['verified'] === true, 422, 'Verifikasi rekonsiliasi uang/kejadian fisik wajib.');
            if ($action === 'accept') {
                $p = OfflinePermit::findOrFail($r->offline_permit_id);
                $lines = $v['lines'] ?? $r->payload['lines'];
                $snapshot = $this->pricing->snapshot($p->snapshot['products'], $lines);
                abort_unless($r->snapshot && $snapshot['total_amount'] === $r->snapshot['total_amount'], 409, 'Review tidak boleh mengubah harga/jumlah offline.');
                $original = collect($r->snapshot['items'])->map(fn ($i) => [$i['product_id'], $i['unit'], $i['quantity']])->all();
                $changed = collect($snapshot['items'])->map(fn ($i) => [$i['product_id'], $i['unit'], $i['quantity']])->all();
                abort_unless($original === $changed, 422, 'Review hanya dapat mengubah alokasi batch, bukan barang atau jumlah.');
                $sale = $this->checkout->postOffline($u, $r, $shift, $snapshot);
                $r->sale_id = $sale['id'];
                $r->snapshot = $snapshot;
                $r->status = 'accepted';
            } else {
                $r->status = 'voided';
            }$r->version++;
            $r->reviewed_by = $u->id;
            $r->reviewed_at = now();
            $r->review_note = $v['reason'];
            $r->reconciliation_reference = $v['reconciliation_reference'];
            $r->save();
            Audit::record($u->id, $s->organization_id, 'offline.reviewed', $id, null, $r->only(['id', 'status', 'sale_id', 'review_note', 'reconciliation_reference']));

            return $r->toArray();
        });
    }
}
