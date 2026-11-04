<?php

namespace App\Modules\Fulfillment\Application;

use App\Models\User;
use App\Modules\Fulfillment\Infrastructure\ReturnLine;
use App\Modules\Fulfillment\Infrastructure\ReturnShipment;
use App\Modules\Fulfillment\Infrastructure\Shipment;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancy;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancyResolution;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Application\OrderWorkflow;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReturnWorkflow
{
    public function __construct(private OrderAccess $access, private OrderWorkflow $orders, private Operations $operations, private StockLedger $stock) {}

    private function locked(User $u, string $id, string $shipment): array
    {
        $o = $this->orders->lock($id);
        $this->access->view($u, $o);
        Shipment::where('supply_order_id', $id)->lockForUpdate()->findOrFail($shipment);
        $case = ShipmentDiscrepancy::where('supply_order_id', $id)->where('shipment_id', $shipment)->lockForUpdate()->firstOrFail();
        $plan = ShipmentDiscrepancyResolution::where('shipment_discrepancy_id', $case->id)->where('status', 'confirmed')->lockForUpdate()->first();
        abort_unless($o->kind === 'wholesale' && $case->status === 'plan_confirmed' && $plan?->type === 'return', 409, 'Rencana retur belum dikonfirmasi.');

        return [$o, $case, $plan];
    }

    private function quota(SupplyOrder $o, ShipmentDiscrepancy $case): array
    {
        $totals = DB::table('return_lines as l')->join('return_shipments as s', 's.id', '=', 'l.return_shipment_id')->where('s.case_id', $case->id)->groupBy('l.original_shipment_line_id')->selectRaw('l.original_shipment_line_id, SUM(l.quantity_packs) AS sent, SUM(COALESCE(l.received_packs,0)) AS received, SUM(CASE WHEN s.received_at IS NULL THEN l.quantity_packs ELSE 0 END) AS transit')->get()->keyBy('original_shipment_line_id');

        return array_values(array_map(function ($l) use ($o, $totals) {
            $t = $totals->get($l['shipment_line_id']);
            $item = $o->lines->firstWhere('id', $l['order_line_id']);

            return [...$l, 'allocation_product_id' => $item->local_product_id, 'limit_packs' => $l['damaged_packs'], 'return_sent_packs' => (int) ($t?->sent ?? 0), 'return_received_packs' => (int) ($t?->received ?? 0), 'in_transit_packs' => (int) ($t?->transit ?? 0), 'remaining_packs' => max(0, $l['damaged_packs'] - (int) ($t?->sent ?? 0))];
        }, array_filter($case->snapshot['lines'], fn ($l) => $l['damaged_packs'] > 0)));
    }

    private function state(array $q): string
    {
        if ($q && collect($q)->every(fn ($l) => $l['return_received_packs'] === $l['limit_packs'])) {
            return 'fulfilled';
        }if (array_sum(array_column($q, 'in_transit_packs')) > 0) {
            return 'in_transit';
        }if (collect($q)->contains(fn ($l) => $l['return_sent_packs'] > $l['return_received_packs'])) {
            return 'requires_follow_up';
        }

        return array_sum(array_column($q, 'return_sent_packs')) > 0 ? 'partially_fulfilled' : 'ready';
    }

    private function active(SupplyOrder $o): void
    {
        $this->access->targetActive($o);
        abort_unless(Organization::whereKey($o->supplier_organization_id)->where('active', true)->exists() && Store::whereKey($o->source_store_id)->where('active', true)->exists(), 409, 'Gudang atau organisasi sumber nonaktif.');
    }

    private function allowed(callable $f): bool
    {
        try {
            $f();

            return true;
        } catch (HttpException $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 409], true)) {
                throw $e;
            }

            return false;
        }
    }

    public function context(User $u, string $id, string $shipment): array
    {
        return DB::transaction(function () use ($u, $id, $shipment) {
            [$o,$case,$plan] = $this->locked($u, $id, $shipment);
            $q = $this->quota($o, $case);
            $active = $this->allowed(fn () => $this->active($o));
            $dispatch = $active && $q && $this->allowed(fn () => $this->access->recipient($u, $o->store_id, true)) && array_sum(array_column($q, 'remaining_packs')) > 0;
            $receive = $active && $this->allowed(fn () => $this->access->source($u, $o, $o->source_store_id));
            $batches = $dispatch ? InventoryBatch::where('store_id', $o->store_id)->where('quarantined_pieces', '>', 0)->whereIn('product_id', array_column($q, 'allocation_product_id'))->orderBy('id')->get()->filter(fn ($b) => collect($q)->contains(fn ($l) => $l['allocation_product_id'] === $b->product_id && $l['batch_code'] === $b->batch_code && $l['pack_size'] === $b->pack_size && $l['expires_on'] === $b->expires_on->format('Y-m-d')))->map(fn ($b) => [...$b->only(['id', 'store_id', 'product_id', 'batch_code', 'expires_on', 'pack_size', 'quarantined_pieces', 'condition', 'stock_revision'])])->values()->all() : [];

            return [...ReturnShipment::where('case_id', $case->id)->with('lines')->orderByDesc('id')->paginate(50)->through(fn ($r) => [...$r->toArray(), 'can_receive' => $receive && ! $r->received_at])->toArray(), 'order_id' => $id, 'shipment_id' => $shipment, 'case_id' => $case->id, 'case_version' => $case->version, 'source_store_id' => $o->store_id, 'target_store_id' => $o->source_store_id, 'resolution' => $plan->toArray(), 'quota' => $q, 'batches' => $batches, 'can_dispatch' => (bool) $dispatch, 'execution_status' => $this->state($q), 'destination_bucket' => 'quarantined'];
        });
    }

    private function audit(User $u, SupplyOrder $o, ShipmentDiscrepancy $c, ReturnShipment $s, string $action): void
    {
        foreach (array_unique([$o->organization_id, $o->supplier_organization_id]) as $org) {
            Audit::record($u->id, $org, 'return.'.$action, $s->id, null, ['order_id' => $o->id, 'shipment_id' => $c->shipment_id, 'case_id' => $c->id, 'case_version' => $c->version, 'return' => $s->toArray(), 'destination_bucket' => 'quarantined']);
        }
    }

    public function dispatch(User $u, string $id, string $shipment, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->recipient($u, $initial->store_id);

        return $this->operations->run($initial->organization_id, $u->id, $v['operation_id'], 'return.dispatch:'.$shipment, $v, function () use ($u, $id, $shipment, $v) {
            [$o,$case,$plan] = $this->locked($u, $id, $shipment);
            $this->access->recipient($u, $o->store_id, true);
            $this->active($o);
            abort_unless($case->version === (int) $v['version'] && ($v['destination_bucket'] ?? null) === 'quarantined', 409, 'Review retur/tujuan berubah.');
            $quota = collect($this->quota($o, $case))->keyBy('shipment_line_id');
            $work = [];
            foreach ($v['lines'] as $row) {
                $q = $quota->get($row['original_shipment_line_id']);
                abort_unless($q && array_sum(array_column($row['allocations'], 'quantity_packs')) <= $q['remaining_packs'], 422, 'Jumlah retur melebihi barang rusak yang diterima.');
                abort_unless(count(array_unique(array_column($row['allocations'], 'batch_id'))) === count($row['allocations']), 422, 'Batch diulang.');
                foreach ($row['allocations'] as $a) {
                    $work[] = ['quota' => $q, ...$a];
                }
            }usort($work, fn ($a, $b) => strcmp($a['batch_id'], $b['batch_id']));
            $s = ReturnShipment::create(['case_id' => $case->id, 'resolution_id' => $plan->id, 'source_store_id' => $o->store_id, 'target_store_id' => $o->source_store_id, 'dispatched_by' => $u->id, 'handler' => $v['handler'], 'note' => $v['note'] ?? null, 'dispatched_at' => now()]);
            foreach ($work as $w) {
                $q = $w['quota'];
                $item = $o->lines->firstWhere('id', $q['order_line_id']);
                $b = InventoryBatch::where('store_id', $o->store_id)->where('product_id', $item->local_product_id)->where('batch_code', $q['batch_code'])->where('pack_size', $q['pack_size'])->lockForUpdate()->findOrFail($w['batch_id']);
                abort_unless($b->expires_on->format('Y-m-d') === $q['expires_on'] && $b->condition === $item->condition, 409, 'Identitas batch retur berbeda.');
                $line = new ReturnLine(['return_shipment_id' => $s->id, 'original_shipment_line_id' => $q['shipment_line_id'], 'inventory_batch_id' => $b->id, 'supplier_product_id' => $item->supplier_product_id, 'sku' => $q['sku'], 'name' => $q['name'], 'local_sku' => $q['local_sku'], 'local_name' => $q['local_name'], 'condition' => $item->condition, 'batch_code' => $q['batch_code'], 'expires_on' => $q['expires_on'], 'pack_size' => $q['pack_size'], 'quantity_packs' => (int) $w['quantity_packs']]);
                $line->id = (string) Str::ulid();
                $line->save();
                $this->stock->apply($b, $u->id, ['quarantined' => -(int) $w['quantity_packs'] * $q['pack_size']], 'return_dispatch', $line->id, $v['note'] ?? null);
            }
            $case->increment('version');
            $s->load('lines');
            $this->audit($u, $o, $case, $s, 'dispatched');

            return ['order_id' => $id, 'shipment_id' => $shipment, 'case_id' => $case->id, 'case_version' => $case->version, 'return' => $s->toArray(), 'execution_status' => $this->state($this->quota($o, $case))];
        });
    }

    public function receive(User $u, string $id, string $shipment, string $returnId, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->supplier($u, $initial);

        return $this->operations->run($initial->supplier_organization_id, $u->id, $v['operation_id'], 'return.receive:'.$returnId, $v, function () use ($u, $id, $shipment, $returnId, $v) {
            [$o,$case,$plan] = $this->locked($u, $id, $shipment);
            $this->access->source($u, $o, $o->source_store_id);
            $this->active($o);
            abort_unless($case->version === (int) $v['version'], 409, 'Versi kasus berubah.');
            $s = ReturnShipment::where('case_id', $case->id)->where('resolution_id', $plan->id)->with('lines')->lockForUpdate()->findOrFail($returnId);
            abort_if($s->received_at, 409, 'Retur sudah diterima.');
            $actual = collect($v['lines'])->keyBy('return_line_id');
            abort_unless(count($v['lines']) === $s->lines->count() && $actual->count() === $s->lines->count(), 422, 'Isi seluruh baris retur tepat sekali.');
            foreach ($s->lines->sortBy(fn ($l) => $l->supplier_product_id.'|'.$l->batch_code.'|'.$l->pack_size) as $l) {
                $a = $actual->get($l->id);
                abort_unless($a && (int) $a['received_packs'] <= $l->quantity_packs, 422, 'Jumlah diterima melebihi retur.');
                abort_unless((int) $a['received_packs'] === $l->quantity_packs || trim((string) ($a['condition_note'] ?? '')) !== '', 422, 'Kekurangan retur wajib alasan.');
                if ($a['received_packs'] > 0) {
                    $this->stock->credit($o->supplier_organization_id, $o->source_store_id, ['id' => $l->supplier_product_id, 'sku' => $l->sku, 'name' => $l->name, 'condition' => $l->condition, 'pack_size' => $l->pack_size], $l->batch_code, $l->expires_on->format('Y-m-d'), (int) $a['received_packs'], $u->id, 'return_receipt', $l->id, 'quarantined', $a['condition_note'] ?? null);
                }$l->update(['received_packs' => (int) $a['received_packs'], 'condition_note' => $a['condition_note'] ?? null]);
            }
            $s->update(['received_by' => $u->id, 'received_at' => now(), 'receipt_note' => $v['note'] ?? null]);
            $case->increment('version');
            $s->load('lines');
            $this->audit($u, $o, $case, $s, 'received');

            return ['order_id' => $id, 'shipment_id' => $shipment, 'case_id' => $case->id, 'case_version' => $case->version, 'return' => $s->toArray(), 'execution_status' => $this->state($this->quota($o, $case))];
        });
    }
}
