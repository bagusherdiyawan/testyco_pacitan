<?php

namespace App\Modules\Ordering\Application;

use App\Models\User;
use App\Modules\Catalog\Application\SupplyCatalog;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Ordering\Infrastructure\OrderChange;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Ordering\Infrastructure\SupplyOrderLine;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrderRevisionWorkflow
{
    public function __construct(private OrderAccess $access, private Access $identity, private OrderWorkflow $orders, private SupplyCatalog $catalog, private Operations $operations) {}

    private function party(User $user, SupplyOrder $order, ?string $desired = null): string
    {
        $visible = $this->access->view($user, $order);
        $supplier = in_array($this->identity->role($user, $order->supplier_organization_id)?->value, ['owner', 'admin'], true);
        $recipient = $this->identity->role($user, $order->organization_id)?->value === 'partner_lead';
        if ($desired !== null) {
            abort_unless($desired === 'supplier' ? $supplier : $recipient, 403);

            return $desired;
        }
        if ($visible === 'recipient' && $recipient) {
            return 'recipient';
        }
        abort_unless($supplier, 403);

        return 'supplier';
    }

    private function eligible(SupplyOrder $order): bool
    {
        return $order->kind === 'wholesale' && in_array($order->status, ['approved', 'processing', 'dispatched', 'partially_received', 'completed'], true);
    }

    private function snapshot(SupplyOrder $order): array
    {
        return ['lines' => $order->lines->sortBy('id')->values()->map(fn ($l) => $l->only(['id', 'supplier_product_id', 'local_product_id', 'sku', 'name', 'local_sku', 'local_name', 'condition', 'pack_size', 'quantity_packs', 'unit_price']))->all(), 'total_amount' => $order->total_amount];
    }

    private function sent(string $id): array
    {
        return DB::table('shipment_lines as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')->where('s.supply_order_id', $id)->groupBy('l.supply_order_line_id')->selectRaw('l.supply_order_line_id, SUM(l.quantity_packs) AS sent')->pluck('sent', 'supply_order_line_id')->map(fn ($v) => (int) $v)->all();
    }

    private function audit(User $user, SupplyOrder $order, OrderChange $r, string $action): void
    {
        foreach (array_unique([$order->organization_id, $order->supplier_organization_id]) as $org) {
            Audit::record($user->id, $org, 'order.revision_'.$action, $r->id, null, ['order_id' => $order->id, 'version' => $order->version, 'revision' => $r->toArray()]);
        }
    }

    public function context(User $user, string $id): array
    {
        $o = SupplyOrder::with('lines')->findOrFail($id);
        $side = $this->access->view($user, $o);
        $supplier = in_array($this->identity->role($user, $o->supplier_organization_id)?->value, ['owner', 'admin'], true);
        $recipient = $this->identity->role($user, $o->organization_id)?->value === 'partner_lead';
        $pending = OrderChange::where('supply_order_id', $id)->where('kind', 'revision')->whereNotNull('pending_order_id')->first();
        $requested = $pending?->before['requested_side'] ?? null;
        $canAct = $supplier || $recipient;

        return [...OrderChange::where('supply_order_id', $id)->where('kind', 'revision')->orderByDesc('id')->paginate(50)->toArray(), 'order' => $o->toArray(), 'sent_packs' => (object) $this->sent($id), 'pending' => $pending?->toArray(), 'change_pending' => OrderChange::pending($id), 'actions' => ['propose' => $canAct && $this->eligible($o) && ! OrderChange::pending($id), 'approve' => $pending && ($requested === 'supplier' ? $recipient : $supplier), 'reject' => $pending && ($requested === 'supplier' ? $recipient : $supplier), 'withdraw' => $pending && ($requested === 'supplier' ? $supplier : $recipient)],
            'supplier_products' => $canAct ? $this->catalog->available()->where('organization_id', $o->supplier_organization_id)->get(['id', 'sku', 'name', 'pack_size', 'condition'])->toArray() : [],
            'local_products' => $recipient ? Product::where('organization_id', $o->organization_id)->where('active', true)->get(['id', 'sku', 'name', 'pack_size', 'condition'])->toArray() : $o->lines->map(fn ($l) => ['id' => $l->local_product_id, 'sku' => $l->local_sku, 'name' => $l->local_name, 'pack_size' => $l->pack_size, 'condition' => $l->condition])->unique('id')->values()->all(), 'access_side' => $side];
    }

    private function candidate(SupplyOrder $o, string $side, array $v): array
    {
        $before = $this->snapshot($o);
        $sent = $this->sent($o->id);
        $requested = collect($v['existing'])->keyBy('order_line_id');
        abort_unless(count($v['existing']) === $o->lines->count() && $requested->count() === $o->lines->count(), 422, 'Isi setiap baris existing tepat sekali.');
        $after = $before;
        $changed = false;
        foreach ($after['lines'] as &$line) {
            $q = $requested->get($line['id']);
            abort_unless($q && (int) $q['quantity_packs'] >= (int) ($sent[$line['id']] ?? 0) && (int) $q['quantity_packs'] <= $line['quantity_packs'], 422, 'Jumlah tidak boleh lebih kecil dari yang sudah dikirim.');
            $changed = $changed || $line['quantity_packs'] !== (int) $q['quantity_packs'];
            $line['quantity_packs'] = (int) $q['quantity_packs'];
        }unset($line);
        foreach ($v['additions'] ?? [] as $add) {
            if ($side === 'supplier') {
                abort_unless($o->lines->contains('local_product_id', $add['local_product_id']), 403, 'SKU lokal baru harus dipetakan oleh pimpinan mitra.');
            }
            $line = $this->catalog->line($add['supplier_product_id'], $add['local_product_id'], $o->organization_id, (int) $add['quantity_packs'], false);
            abort_unless($line['supplier_organization_id'] === $o->supplier_organization_id, 422, 'Pemasok berbeda.');
            unset($line['supplier_organization_id']);
            $after['lines'][] = ['id' => (string) Str::ulid(), ...$line];
            $changed = true;
        }
        abort_unless($changed && array_sum(array_column($after['lines'], 'quantity_packs')) > 0 && count($after['lines']) <= 100, 422, 'Revisi harus mengubah isi dan menyisakan minimal satu barang.');
        $after['total_amount'] = array_sum(array_map(fn ($l) => $l['quantity_packs'] * $l['unit_price'], $after['lines']));

        return ['before' => $before, 'after' => $after];
    }

    private function reviewHash(SupplyOrder $o, string $side, array $v, array $candidate): string
    {
        $ids = array_column($candidate['before']['lines'], 'id');
        $candidate['after']['lines'] = array_map(function ($l) use ($ids) {
            if (! in_array($l['id'], $ids, true)) {
                $l['id'] = null;
            }

            return $l;
        }, $candidate['after']['lines']);

        return hash('sha256', json_encode(['order_id' => $o->id, 'version' => $o->version, 'side' => $side, 'reason' => $v['reason'], ...$candidate], JSON_THROW_ON_ERROR));
    }

    public function preview(User $user, string $id, array $v): array
    {
        return DB::transaction(function () use ($user, $id, $v) {
            $o = $this->orders->lock($id);
            $side = $this->party($user, $o);
            $this->access->targetActive($o);
            abort_unless($this->eligible($o) && $o->version === (int) $v['version'] && ! OrderChange::pending($id), 409, 'Versi/status berubah.');
            $candidate = $this->candidate($o, $side, $v);

            return ['order_id' => $id, 'version' => $o->version, ...$candidate, 'review_hash' => $this->reviewHash($o, $side, $v, $candidate)];
        });
    }

    public function propose(User $user, string $id, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $side = $this->party($user, $initial);
        $org = $side === 'supplier' ? $initial->supplier_organization_id : $initial->organization_id;

        return $this->operations->run($org, $user->id, $v['operation_id'], 'order.revision:'.$id, $v, function () use ($user, $id, $v) {
            $o = $this->orders->lock($id);
            $side = $this->party($user, $o);
            $this->access->targetActive($o);
            abort_unless($this->eligible($o) && $o->version === (int) $v['version'] && ! OrderChange::pending($id), 409, 'Versi/status berubah atau usulan masih menunggu.');
            $candidate = $this->candidate($o, $side, $v);
            if (isset($v['review_hash'])) {
                abort_unless(hash_equals($v['review_hash'], $this->reviewHash($o, $side, $v, $candidate)), 409, 'Harga atau isi review berubah. Tinjau ulang.');
            }
            $before = $candidate['before'];
            $after = $candidate['after'];
            $r = OrderChange::create(['kind' => 'revision', 'supply_order_id' => $id, 'pending_order_id' => $id, 'base_version' => $o->version, 'pending_version' => $o->version + 1, 'status' => 'pending', 'requested_by' => $user->id, 'reason' => $v['reason'], 'before' => [...$before, 'requested_side' => $side], 'after' => $after]);
            $o->increment('version');
            $this->audit($user, $o, $r, 'proposed');

            return ['order' => $o->toArray(), 'revision' => $r->toArray()];
        });
    }

    public function decide(User $user, string $id, string $revision, string $action, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->view($user, $initial);
        $initialRevision = OrderChange::where('supply_order_id', $id)->where('kind', 'revision')->findOrFail($revision);
        $desired = $action === 'withdraw' ? $initialRevision->before['requested_side'] : ($initialRevision->before['requested_side'] === 'supplier' ? 'recipient' : 'supplier');
        $side = $this->party($user, $initial, $desired);
        $org = $side === 'supplier' ? $initial->supplier_organization_id : $initial->organization_id;

        return $this->operations->run($org, $user->id, $v['operation_id'], 'order.revision_'.$action.':'.$revision, $v, function () use ($user, $id, $revision, $action, $v, $desired) {
            $o = $this->orders->lock($id);
            $side = $this->party($user, $o, $desired);
            $r = OrderChange::where('supply_order_id', $id)->where('kind', 'revision')->lockForUpdate()->findOrFail($revision);
            abort_unless($action === 'withdraw' ? $side === $r->before['requested_side'] : $side !== $r->before['requested_side'], 403);
            abort_unless($r->status === 'pending' && $r->pending_order_id === $id && $o->version === (int) $v['version'] && $r->pending_version === $o->version, 409, 'Review revisi berubah.');
            if ($action === 'approve') {
                $this->access->targetActive($o);
                abort_unless(Organization::whereKey($o->supplier_organization_id)->where('active', true)->exists(), 409, 'Pemasok nonaktif.');
                abort_unless($this->snapshot($o) === array_intersect_key($r->before, array_flip(['lines', 'total_amount'])), 409, 'Snapshot berubah.');
                foreach ($r->after['lines'] as $line) {
                    if ($o->lines->contains('id', $line['id'])) {
                        $o->lines()->whereKey($line['id'])->update(['quantity_packs' => $line['quantity_packs']]);
                    } else {
                        $item = new SupplyOrderLine(['supply_order_id' => $id, ...array_diff_key($line, ['id' => true])]);
                        $item->id = $line['id'];
                        $item->save();
                    }
                }
                $hasShipments = DB::table('shipments')->where('supply_order_id', $id)->exists();
                $state = $o->source_store_id ? 'processing' : 'approved';
                if ($hasShipments) {
                    $good = DB::table('shipment_lines as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')->where('s.supply_order_id', $id)->groupBy('l.supply_order_line_id')->selectRaw('l.supply_order_line_id, SUM(COALESCE(l.good_packs,0)) AS good')->pluck('good', 'supply_order_line_id');
                    $allGood = collect($r->after['lines'])->every(fn ($l) => (int) ($good[$l['id']] ?? 0) === $l['quantity_packs']);
                    $state = $allGood ? 'completed' : (DB::table('shipments')->where('supply_order_id', $id)->whereNotNull('received_at')->exists() ? 'partially_received' : 'dispatched');
                }
                $o->fill(['total_amount' => $r->after['total_amount'], 'approved_at' => now(), 'approved_by' => $user->id, 'status' => $state]);
                $o->save();
            }
            $o->increment('version');
            $r->update(['pending_order_id' => null, 'status' => ['approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action], 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $v['reason'] ?? null]);
            $o->load('lines');
            $this->audit($user, $o, $r, $action);

            return ['order' => $o->toArray(), 'revision' => $r->toArray()];
        });
    }
}
