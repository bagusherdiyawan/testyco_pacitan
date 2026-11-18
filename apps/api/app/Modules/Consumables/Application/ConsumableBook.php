<?php

namespace App\Modules\Consumables\Application;

use App\Models\User;
use App\Modules\Consumables\Infrastructure\ConsumableEntry;
use App\Modules\Consumables\Infrastructure\ConsumableItem;
use App\Modules\Consumables\Infrastructure\ConsumableRule;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Shifts\Application\PosAccess;
use App\Modules\Shifts\Application\ShiftBook;
use App\Modules\Shifts\Infrastructure\CashShift;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Non-food supplies (packaging, cutlery) for booths. Quantity-only ledger per store.
 * Sales never fail because a supply ran out: the sale is recorded, the balance goes
 * negative and the shortfall is reported so the shortage is visible and can be corrected.
 */
final class ConsumableBook
{
    public const SERVICE_TYPES = ['dine_in', 'takeaway'];

    public const BASES = ['transaction', 'portion'];

    public function __construct(private Access $access, private PosAccess $pos, private Operations $ops, private ShiftBook $shifts) {}

    private function organizationManager(User $u, string $org): void
    {
        abort_unless(in_array($this->access->role($u, $org)?->value, ['owner', 'admin', 'partner_lead'], true), 403, 'Tidak berwenang mengelola barang non-makanan.');
    }

    public function catalogue(User $u, string $org): array
    {
        $this->organizationManager($u, $org);

        return [
            'organization_id' => $org,
            'items' => ConsumableItem::where('organization_id', $org)->orderBy('name')->get()->toArray(),
            'rules' => ConsumableRule::where('organization_id', $org)->orderBy('service_type')->orderBy('id')->get()->toArray(),
        ];
    }

    public function createItem(User $u, string $org, array $v): array
    {
        $this->organizationManager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'consumable.item:'.$org, $v, function () use ($u, $org, $v) {
            $this->organizationManager($u, $org);
            abort_if(ConsumableItem::where('organization_id', $org)->where('name', $v['name'])->exists(), 409, 'Nama barang sudah digunakan.');
            $item = ConsumableItem::create(['organization_id' => $org, 'name' => $v['name'], 'unit' => $v['unit'], 'active' => true, 'version' => 1]);
            Audit::record($u->id, $org, 'consumable.item_created', $item->id, null, $item->toArray());

            return $item->toArray();
        });
    }

    public function setItemActive(User $u, string $org, string $id, array $v): array
    {
        $this->organizationManager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'consumable.active:'.$id, $v, function () use ($u, $org, $id, $v) {
            $item = ConsumableItem::where('organization_id', $org)->lockForUpdate()->findOrFail($id);
            abort_unless($item->version === (int) $v['version'], 409, 'Versi barang berubah. Muat ulang.');
            $before = $item->toArray();
            $item->fill(['active' => $v['active'], 'version' => $item->version + 1])->save();
            Audit::record($u->id, $org, 'consumable.item_status_changed', $id, $before, [...$item->toArray(), 'reason' => $v['reason']]);

            return $item->toArray();
        });
    }

    /** Quantity 0 removes the rule. */
    public function setRule(User $u, string $org, array $v): array
    {
        $this->organizationManager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'consumable.rule:'.$org, $v, function () use ($u, $org, $v) {
            $this->organizationManager($u, $org);
            $item = ConsumableItem::where('organization_id', $org)->where('active', true)->find($v['consumable_item_id']);
            abort_unless($item, 422, 'Barang tidak tersedia atau tidak aktif.');
            $rule = ConsumableRule::where('organization_id', $org)->where('service_type', $v['service_type'])->where('consumable_item_id', $item->id)->first();
            $before = $rule?->toArray();
            if ((int) $v['quantity'] === 0) {
                $rule?->delete();
                Audit::record($u->id, $org, 'consumable.rule_removed', $rule?->id ?? $item->id, $before, null);

                return ['removed' => $rule !== null, 'service_type' => $v['service_type'], 'consumable_item_id' => $item->id];
            }
            $rule = $rule ?? new ConsumableRule(['organization_id' => $org, 'service_type' => $v['service_type'], 'consumable_item_id' => $item->id]);
            $rule->fill(['basis' => $v['basis'], 'quantity' => (int) $v['quantity']])->save();
            Audit::record($u->id, $org, 'consumable.rule_saved', $rule->id, $before, $rule->toArray());

            return $rule->toArray();
        });
    }

    private function balance(string $store, string $item): int
    {
        return (int) ConsumableEntry::where('store_id', $store)->where('consumable_item_id', $item)->sum('delta');
    }

    public function stock(User $u, string $store): array
    {
        $s = $this->pos->store($u, $store, false);
        $items = ConsumableItem::where('organization_id', $s->organization_id)->where('active', true)->orderBy('name')->get();
        $balances = ConsumableEntry::where('store_id', $store)->selectRaw('consumable_item_id, sum(delta) as balance')->groupBy('consumable_item_id')->pluck('balance', 'consumable_item_id');

        return [
            'store_id' => $store,
            'organization_id' => $s->organization_id,
            'items' => $items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'balance' => (int) ($balances[$i->id] ?? 0), 'short' => (int) ($balances[$i->id] ?? 0) < 0])->values()->all(),
            'recent' => ConsumableEntry::where('store_id', $store)->orderByDesc('id')->limit(30)->get()->toArray(),
        ];
    }

    private function storeItem(string $org, string $id): ConsumableItem
    {
        $item = ConsumableItem::where('organization_id', $org)->where('active', true)->find($id);
        abort_unless($item, 422, 'Barang tidak tersedia atau tidak aktif.');

        return $item;
    }

    /** A purchase adds stock and, when a shift is given, records the cash that was spent. */
    public function purchase(User $u, string $store, array $v): array
    {
        $s = $this->pos->manager($u, $store);

        return $this->ops->run($s->organization_id, $u->id, $v['operation_id'], 'consumable.purchase:'.$store, $v, function () use ($u, $store, $s, $v) {
            $item = $this->storeItem($s->organization_id, $v['consumable_item_id']);
            $movement = null;
            if (! empty($v['shift_id'])) {
                abort_unless(! empty($v['shift_version']) && (int) ($v['amount'] ?? 0) > 0, 422, 'Nominal dan versi shift wajib untuk mencatat kas keluar.');
                $shift = CashShift::where('store_id', $store)->find($v['shift_id']);
                abort_unless($shift, 422, 'Shift tidak ditemukan pada toko ini.');
                $movement = $this->shifts->movement($u, $shift->id, [
                    'operation_id' => Uuid::uuid5(Uuid::NAMESPACE_OID, 'consumable.purchase.cash:'.$v['operation_id'])->toString(),
                    'version' => (int) $v['shift_version'],
                    'kind' => 'out',
                    'amount' => (int) $v['amount'],
                    'category' => 'belanja_operasional',
                    'reason' => 'Belanja '.$item->name.' · '.($v['note'] ?? ''),
                ])['movement'];
            }
            $entry = ConsumableEntry::create(['organization_id' => $s->organization_id, 'store_id' => $store, 'consumable_item_id' => $item->id, 'delta' => (int) $v['quantity'], 'reason_type' => 'purchase', 'source_type' => 'purchase', 'source_id' => $v['operation_id'], 'amount' => $v['amount'] ?? null, 'actor_id' => $u->id, 'note' => $v['note'] ?? null, 'created_at' => now()]);
            Audit::record($u->id, $s->organization_id, 'consumable.purchased', $entry->id, null, $entry->toArray());

            return ['entry' => $entry->toArray(), 'balance' => $this->balance($store, $item->id), 'cash_movement' => $movement];
        });
    }

    /** Physical count: the balance becomes the counted quantity; the difference is the adjustment. */
    public function count(User $u, string $store, array $v): array
    {
        $s = $this->pos->manager($u, $store);

        return $this->ops->run($s->organization_id, $u->id, $v['operation_id'], 'consumable.count:'.$store, $v, function () use ($u, $store, $s, $v) {
            $item = $this->storeItem($s->organization_id, $v['consumable_item_id']);
            ConsumableItem::whereKey($item->id)->lockForUpdate()->first();
            $before = $this->balance($store, $item->id);
            $delta = (int) $v['counted'] - $before;
            $entry = ConsumableEntry::create(['organization_id' => $s->organization_id, 'store_id' => $store, 'consumable_item_id' => $item->id, 'delta' => $delta, 'reason_type' => 'count', 'source_type' => 'count', 'source_id' => $v['operation_id'], 'actor_id' => $u->id, 'note' => $v['reason'], 'created_at' => now()]);
            Audit::record($u->id, $s->organization_id, 'consumable.counted', $entry->id, ['balance' => $before], ['balance' => (int) $v['counted'], 'reason' => $v['reason']]);

            return ['entry' => $entry->toArray(), 'balance_before' => $before, 'balance' => (int) $v['counted']];
        });
    }

    /**
     * Deduct supplies for a completed sale inside the checkout transaction. Never blocks the sale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function consume(string $organization, string $store, string $saleId, array $items, ?string $serviceType, int $actor): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        if (! $serviceType) {
            return [];
        }
        $rules = ConsumableRule::where('organization_id', $organization)->where('service_type', $serviceType)->orderBy('id')->get();
        if ($rules->isEmpty()) {
            return [];
        }
        $portions = array_sum(array_map(fn ($i) => $i['unit'] === 'portion' ? (int) $i['quantity'] : 0, $items));
        $catalogue = ConsumableItem::whereIn('id', $rules->pluck('consumable_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $result = [];
        foreach ($rules as $rule) {
            $item = $catalogue[$rule->consumable_item_id] ?? null;
            $quantity = $rule->basis === 'portion' ? $rule->quantity * $portions : $rule->quantity;
            if (! $item || ! $item->active || $quantity <= 0) {
                continue;
            }
            $before = $this->balance($store, $item->id);
            ConsumableEntry::create(['organization_id' => $organization, 'store_id' => $store, 'consumable_item_id' => $item->id, 'delta' => -$quantity, 'reason_type' => 'sale', 'source_type' => 'sale', 'source_id' => $saleId, 'actor_id' => $actor, 'note' => $serviceType, 'created_at' => now()]);
            $result[] = ['consumable_item_id' => $item->id, 'name' => $item->name, 'unit' => $item->unit, 'quantity' => $quantity, 'shortfall' => max(0, $quantity - max($before, 0)), 'balance_after' => $before - $quantity];
        }

        return $result;
    }
}
