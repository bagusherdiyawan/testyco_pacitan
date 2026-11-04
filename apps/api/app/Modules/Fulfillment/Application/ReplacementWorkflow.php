<?php

namespace App\Modules\Fulfillment\Application;

use App\Models\User;
use App\Modules\Fulfillment\Infrastructure\ReplacementLine;
use App\Modules\Fulfillment\Infrastructure\ReplacementShipment;
use App\Modules\Fulfillment\Infrastructure\Shipment;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancy;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancyResolution;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\FoodSafety;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Application\OrderWorkflow;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReplacementWorkflow
{
    public function __construct(private OrderAccess $access, private OrderWorkflow $orders, private Operations $operations, private StockLedger $stock) {}

    private function locked(User $user, string $orderId, string $shipmentId): array
    {
        $order = $this->orders->lock($orderId);
        $this->access->view($user, $order);
        Shipment::where('supply_order_id', $orderId)->lockForUpdate()->findOrFail($shipmentId);
        $case = ShipmentDiscrepancy::where('supply_order_id', $orderId)->where('shipment_id', $shipmentId)->lockForUpdate()->firstOrFail();
        $plan = ShipmentDiscrepancyResolution::where('shipment_discrepancy_id', $case->id)->where('status', 'confirmed')->lockForUpdate()->first();
        abort_unless($order->kind === 'wholesale' && $case->status === 'plan_confirmed' && $plan?->type === 'replacement', 409, 'Belum ada rencana pengganti yang dikonfirmasi.');

        return [$order, $case, $plan];
    }

    private function quota(ShipmentDiscrepancy $case): array
    {
        $totals = DB::table('replacement_lines as l')->join('replacement_shipments as s', 's.id', '=', 'l.replacement_shipment_id')->where('s.case_id', $case->id)->groupBy('l.original_shipment_line_id')
            ->selectRaw('l.original_shipment_line_id, SUM(l.quantity_packs) AS sent, SUM(COALESCE(l.good_packs,0)) AS good, SUM(CASE WHEN s.received_at IS NULL THEN l.quantity_packs ELSE 0 END) AS transit')->get()->keyBy('original_shipment_line_id');

        return array_values(array_map(function ($line) use ($totals) {
            $t = $totals->get($line['shipment_line_id']);
            $limit = $line['damaged_packs'] + $line['missing_packs'];

            return [...$line, 'limit_packs' => $limit, 'replacement_sent_packs' => (int) ($t?->sent ?? 0), 'replacement_good_packs' => (int) ($t?->good ?? 0), 'in_transit_packs' => (int) ($t?->transit ?? 0), 'remaining_packs' => max(0, $limit - (int) ($t?->sent ?? 0))];
        }, array_filter($case->snapshot['lines'], fn ($l) => $l['damaged_packs'] + $l['missing_packs'] > 0)));
    }

    private function state(array $quota): string
    {
        if (collect($quota)->every(fn ($l) => $l['replacement_good_packs'] === $l['limit_packs'])) {
            return 'fulfilled';
        }
        if (array_sum(array_column($quota, 'in_transit_packs')) > 0) {
            return 'in_transit';
        }
        if (collect($quota)->contains(fn ($l) => $l['replacement_sent_packs'] - $l['replacement_good_packs'] > 0)) {
            return 'requires_follow_up';
        }
        if (array_sum(array_column($quota, 'replacement_sent_packs')) > 0) {
            return 'partially_fulfilled';
        }

        return 'ready';
    }

    private function allowed(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (HttpException $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 409], true)) {
                throw $e;
            }

            return false;
        }
    }

    private function dispatchAccess(User $user, SupplyOrder $order): void
    {
        $this->access->supplier($user, $order);
        $this->access->source($user, $order, $order->source_store_id);
        $this->access->targetActive($order);
    }

    private function receiveAccess(User $user, SupplyOrder $order): void
    {
        $this->access->recipient($user, $order->store_id, true);
        abort_unless(Store::whereKey($order->source_store_id)->where('active', true)->exists() && Organization::whereKey($order->supplier_organization_id)->where('active', true)->exists(), 409, 'Gudang atau organisasi sumber nonaktif.');
    }

    public function context(User $user, string $id, string $shipmentId): array
    {
        return DB::transaction(function () use ($user, $id, $shipmentId) {
            [$order, $case, $plan] = $this->locked($user, $id, $shipmentId);
            $quota = $this->quota($case);
            $canDispatch = $this->allowed(fn () => $this->dispatchAccess($user, $order)) && array_sum(array_column($quota, 'remaining_packs')) > 0;
            $canReceive = $this->allowed(fn () => $this->receiveAccess($user, $order));
            $page = ReplacementShipment::where('case_id', $case->id)->with('lines')->orderByDesc('id')->paginate(50)->through(fn ($s) => [...$s->toArray(), 'can_receive' => $canReceive && ! $s->received_at])->toArray();
            $issueItems = $order->lines->whereIn('id', array_column($quota, 'order_line_id'));
            $batches = $canDispatch ? InventoryBatch::where('store_id', $order->source_store_id)->whereIn('product_id', $issueItems->pluck('supplier_product_id'))->where('usable_pieces', '>', 0)->orderBy('id')->get()
                ->filter(fn ($b) => $issueItems->contains(fn ($l) => $l->supplier_product_id === $b->product_id && $l->pack_size === $b->pack_size && $l->condition === $b->condition))
                ->map(fn ($b) => $b->only(['id', 'store_id', 'product_id', 'batch_code', 'expires_on', 'pack_size', 'condition', 'usable_pieces', 'stock_revision']))->values()->all() : [];
            $quota = array_map(fn ($l) => [...$l, 'supplier_product_id' => $order->lines->firstWhere('id', $l['order_line_id'])->supplier_product_id], $quota);

            return [...$page, 'order_id' => $id, 'shipment_id' => $shipmentId, 'case_id' => $case->id, 'case_version' => $case->version, 'resolution' => $plan->toArray(), 'source_store_id' => $order->source_store_id, 'store_id' => $order->store_id, 'execution_status' => $this->state($quota), 'quota' => $quota, 'batches' => $batches, 'can_dispatch' => $canDispatch];
        });
    }

    private function audit(User $user, SupplyOrder $order, ShipmentDiscrepancy $case, ReplacementShipment $shipment, string $action): void
    {
        foreach (array_unique([$order->organization_id, $order->supplier_organization_id]) as $org) {
            Audit::record($user->id, $org, 'replacement.'.$action, $shipment->id, null, ['order_id' => $order->id, 'original_shipment_id' => $case->shipment_id, 'case_id' => $case->id, 'case_version' => $case->version, 'shipment' => $shipment->toArray()]);
        }
    }

    public function dispatch(User $user, string $id, string $shipmentId, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->supplier($user, $initial);

        return $this->operations->run($initial->supplier_organization_id, $user->id, $v['operation_id'], 'replacement.dispatch:'.$shipmentId, $v, function () use ($user, $id, $shipmentId, $v) {
            [$order,$case,$plan] = $this->locked($user, $id, $shipmentId);
            $this->dispatchAccess($user, $order);
            abort_unless($case->version === (int) $v['version'], 409, 'Versi kasus berubah. Tinjau ulang.');
            $quota = collect($this->quota($case))->keyBy('shipment_line_id');
            $work = [];
            foreach ($v['lines'] as $row) {
                $q = $quota->get($row['original_shipment_line_id']);
                abort_unless($q && array_sum(array_column($row['allocations'], 'quantity_packs')) <= $q['remaining_packs'], 422, 'Jumlah pengganti melebihi selisih yang belum dikirim.');
                abort_unless(count(array_unique(array_column($row['allocations'], 'batch_id'))) === count($row['allocations']), 422, 'Batch tidak boleh diulang.');
                $item = $order->lines->firstWhere('id', $q['order_line_id']);
                foreach ($row['allocations'] as $a) {
                    $work[] = ['quota' => $q, 'item' => $item, ...$a];
                }
            }
            $safety = app(FoodSafety::class);
            $safety->lockCandidates($order->source_store_id, array_map(fn ($w) => $w['item']->supplier_product_id, $work));
            $safety->allocations($order->source_store_id, array_map(fn ($w) => ['batch_id' => $w['batch_id'], 'product_id' => $w['item']->supplier_product_id, 'bucket' => 'usable', 'pack_size' => $w['quota']['pack_size'], 'condition' => $w['item']->condition, 'pieces' => $w['quantity_packs'] * $w['quota']['pack_size']], $work));
            usort($work, fn ($a, $b) => strcmp($a['batch_id'], $b['batch_id']));
            $shipment = ReplacementShipment::create(['case_id' => $case->id, 'resolution_id' => $plan->id, 'source_store_id' => $order->source_store_id, 'dispatched_by' => $user->id, 'handler' => $v['handler'], 'note' => $v['note'] ?? null, 'dispatched_at' => now()]);
            foreach ($work as $w) {
                $lineId = (string) Str::ulid();
                $q = $w['quota'];
                $item = $w['item'];
                $batch = $this->stock->dispatch($w['batch_id'], $order->source_store_id, $item->supplier_product_id, $q['pack_size'], (int) $w['quantity_packs'], $user->id, $lineId, 'replacement_dispatch');
                abort_unless($batch['condition'] === $item->condition, 422, 'Kondisi batch pengganti tidak sesuai snapshot.');
                $line = new ReplacementLine(['replacement_shipment_id' => $shipment->id, 'original_shipment_line_id' => $q['shipment_line_id'], 'inventory_batch_id' => $w['batch_id'], 'local_product_id' => $item->local_product_id, 'sku' => $q['sku'], 'name' => $q['name'], 'local_sku' => $q['local_sku'], 'local_name' => $q['local_name'], 'condition' => $item->condition, 'batch_code' => $batch['batch_code'], 'expires_on' => $batch['expires_on'], 'pack_size' => $q['pack_size'], 'quantity_packs' => (int) $w['quantity_packs']]);
                $line->id = $lineId;
                $line->save();
            }
            $case->increment('version');
            $shipment->load('lines');
            $this->audit($user, $order, $case, $shipment, 'dispatched');

            return ['order_id' => $id, 'shipment_id' => $shipmentId, 'case_id' => $case->id, 'case_version' => $case->version, 'replacement' => $shipment->toArray(), 'execution_status' => $this->state($this->quota($case))];
        });
    }

    public function receive(User $user, string $id, string $shipmentId, string $replacementId, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->recipient($user, $initial->store_id);

        return $this->operations->run($initial->organization_id, $user->id, $v['operation_id'], 'replacement.receive:'.$replacementId, $v, function () use ($user, $id, $shipmentId, $replacementId, $v) {
            [$order,$case,$plan] = $this->locked($user, $id, $shipmentId);
            $this->receiveAccess($user, $order);
            abort_unless($case->version === (int) $v['version'], 409, 'Versi kasus berubah. Tinjau ulang.');
            $shipment = ReplacementShipment::where('case_id', $case->id)->where('resolution_id', $plan->id)->with('lines')->lockForUpdate()->findOrFail($replacementId);
            abort_if($shipment->received_at, 409, 'Pengganti sudah diterima.');
            $actual = collect($v['lines'])->keyBy('replacement_line_id');
            abort_unless(count($v['lines']) === $shipment->lines->count() && $actual->count() === $shipment->lines->count(), 422, 'Konfirmasikan setiap baris pengganti tepat sekali.');
            // Same deterministic destination batch order across partial deliveries.
            foreach ($shipment->lines->sortBy(fn ($l) => $l->local_product_id.'|'.$l->batch_code.'|'.$l->pack_size) as $line) {
                $a = $actual->get($line->id);
                abort_unless($a, 422, 'Baris bukan milik kiriman pengganti.');
                $good = (int) $a['good_packs'];
                $damage = (int) $a['damaged_packs'];
                abort_unless($good + $damage <= $line->quantity_packs, 422, 'Penerimaan melebihi jumlah dikirim.');
                abort_unless($good === $line->quantity_packs || trim((string) ($a['condition_note'] ?? '')) !== '', 422, 'Selisih penerimaan wajib disertai catatan.');
                $product = ['id' => $line->local_product_id, 'sku' => $line->local_sku, 'name' => $line->local_name, 'pack_size' => $line->pack_size, 'condition' => $line->condition];
                foreach (['usable' => $good, 'quarantined' => $damage] as $bucket => $packs) {
                    if ($packs > 0) {
                        $this->stock->credit($order->organization_id, $order->store_id, $product, $line->batch_code, $line->expires_on->format('Y-m-d'), $packs, $user->id, 'replacement_receipt', $line->id, $bucket, $a['condition_note'] ?? null);
                    }
                }
                $line->update(['good_packs' => $good, 'damaged_packs' => $damage, 'condition_note' => $a['condition_note'] ?? null]);
            }
            $shipment->update(['received_at' => now(), 'received_by' => $user->id, 'receipt_note' => $v['note'] ?? null]);
            $case->increment('version');
            $shipment->load('lines');
            $this->audit($user, $order, $case, $shipment, 'received');

            return ['order_id' => $id, 'shipment_id' => $shipmentId, 'case_id' => $case->id, 'case_version' => $case->version, 'replacement' => $shipment->toArray(), 'execution_status' => $this->state($this->quota($case))];
        });
    }
}
