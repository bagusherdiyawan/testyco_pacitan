<?php

namespace App\Modules\Fulfillment\Application;

use App\Models\User;
use App\Modules\Fulfillment\Infrastructure\Shipment;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancy;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancyNote;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancyResolution;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Application\OrderWorkflow;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Application\Operations;

final class ShipmentDiscrepancyWorkflow
{
    public function __construct(private OrderAccess $access, private OrderWorkflow $orders, private Operations $operations, private Access $identity) {}

    private function shipment(string $order, string $id, bool $lock = false): Shipment
    {
        $q = Shipment::where('supply_order_id', $order)->with('lines');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->findOrFail($id);
    }

    private function snapshot(SupplyOrder $order, Shipment $shipment): ?array
    {
        if (! $shipment->received_at) {
            return null;
        }
        $rows = [];
        $problems = 0;
        foreach ($shipment->lines->sortBy('id') as $line) {
            $item = $order->lines->firstWhere('id', $line->supply_order_line_id);
            abort_unless($item && $line->good_packs !== null && $line->damaged_packs !== null && $line->good_packs >= 0 && $line->damaged_packs >= 0 && $line->quantity_packs > 0 && $line->pack_size > 0 && $line->good_packs + $line->damaged_packs <= $line->quantity_packs, 409, 'Snapshot penerimaan tidak lengkap.');
            $missing = $line->quantity_packs - $line->good_packs - $line->damaged_packs;
            $problems += $missing + $line->damaged_packs;
            $rows[] = ['shipment_line_id' => $line->id, 'order_line_id' => $item->id, 'source_batch_id' => $line->inventory_batch_id,
                'sku' => $item->sku, 'name' => $item->name, 'local_sku' => $item->local_sku, 'local_name' => $item->local_name,
                'batch_code' => $line->batch_code, 'expires_on' => $line->expires_on->format('Y-m-d'), 'pack_size' => $line->pack_size,
                'sent_packs' => $line->quantity_packs, 'good_packs' => $line->good_packs, 'damaged_packs' => $line->damaged_packs, 'missing_packs' => $missing, 'damaged_pieces' => $line->damaged_packs * $line->pack_size, 'missing_pieces' => $missing * $line->pack_size, 'condition_note' => $line->condition_note];
        }

        return ['received_at' => $shipment->received_at->toIso8601String(), 'received_by' => $shipment->received_by, 'receipt_note' => $shipment->receipt_note, 'problem_packs' => $problems, 'lines' => $rows];
    }

    private function audit(User $actor, SupplyOrder $order, string $action, ShipmentDiscrepancy $case, array $details): void
    {
        foreach (array_unique([$order->organization_id, $order->supplier_organization_id]) as $org) {
            Audit::record($actor->id, $org, $action, $case->id, null, ['order_id' => $order->id, 'shipment_id' => $case->shipment_id, 'version' => $case->version, ...$details]);
        }
    }

    private function supplierDecision(User $user, SupplyOrder $order): bool
    {
        return $order->kind === 'wholesale' && Organization::whereKey($order->supplier_organization_id)->where('type', 'center')->exists() && in_array($this->identity->role($user, $order->supplier_organization_id)?->value, ['owner', 'admin'], true);
    }

    private function partnerDecision(User $user, SupplyOrder $order): bool
    {
        return $order->kind === 'wholesale' && Organization::whereKey($order->organization_id)->where('type', 'partner')->exists() && $this->identity->role($user, $order->organization_id)?->value === 'partner_lead';
    }

    public function context(User $user, string $id, string $shipmentId): array
    {
        $order = SupplyOrder::with('lines')->findOrFail($id);
        $side = $this->access->view($user, $order);
        $shipment = $this->shipment($id, $shipmentId);
        $case = ShipmentDiscrepancy::where('supply_order_id', $id)->where('shipment_id', $shipmentId)->first();
        $snapshot = $case?->snapshot ?? $this->snapshot($order, $shipment);
        $notes = $case ? ShipmentDiscrepancyNote::where('shipment_discrepancy_id', $case->id)->orderByDesc('id')->paginate(50)->toArray()
            : ['data' => [], 'current_page' => (int) request('page', 1), 'per_page' => 50, 'last_page' => 1, 'total' => 0];
        $pending = $case ? ShipmentDiscrepancyResolution::where('pending_case_id', $case->id)->first() : null;
        $resolutions = $case ? ShipmentDiscrepancyResolution::where('shipment_discrepancy_id', $case->id)->where('status', '!=', 'pending')->orderByDesc('id')->paginate(50, ['*'], 'resolutions_page')->toArray()
            : ['data' => [], 'current_page' => (int) request('resolutions_page', 1), 'per_page' => 50, 'last_page' => 1, 'total' => 0];
        $supplier = $this->supplierDecision($user, $order);
        $partner = $this->partnerDecision($user, $order);
        $recipientActive = Organization::whereKey($order->organization_id)->where('active', true)->exists();
        $supplierActive = Organization::whereKey($order->supplier_organization_id)->where('active', true)->exists();

        return [...$notes, 'order' => $order->only(['id', 'organization_id', 'supplier_organization_id', 'store_id', 'kind', 'status', 'version', 'destination_name']),
            'shipment' => $shipment->only(['id', 'supply_order_id', 'received_at']), 'case' => $case?->toArray(), 'snapshot' => $snapshot,
            'pending_resolution' => $pending?->toArray(), 'resolutions' => $resolutions,
            'actions' => ['report' => $side === 'recipient' && ! $case && ($snapshot['problem_packs'] ?? 0) > 0, 'note' => $case !== null,
                'propose_resolution' => $case?->status === 'reported' && ! $pending && $supplier && $recipientActive,
                'confirm_resolution' => $pending && $partner && $supplierActive, 'reject_resolution' => $pending && $partner, 'withdraw_resolution' => $pending && $supplier], 'access_side' => $side];
    }

    public function report(User $user, string $id, string $shipmentId, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->recipient($user, $initial->store_id);

        return $this->operations->run($initial->organization_id, $user->id, $v['operation_id'], 'shipment.discrepancy_report:'.$shipmentId, $v, function () use ($user, $id, $shipmentId, $v) {
            $order = $this->orders->lock($id);
            $this->access->recipient($user, $order->store_id);
            $shipment = $this->shipment($id, $shipmentId, true);
            abort_unless($order->version === (int) $v['order_version'], 409, 'Versi pesanan berubah. Tinjau ulang penerimaan.');
            abort_if(ShipmentDiscrepancy::where('shipment_id', $shipmentId)->exists(), 409, 'Kiriman sudah memiliki kasus selisih.');
            $snapshot = $this->snapshot($order, $shipment);
            abort_unless($snapshot && $snapshot['problem_packs'] > 0, 409, 'Kasus hanya untuk penerimaan terkonfirmasi dengan kerusakan atau kekurangan.');
            $case = ShipmentDiscrepancy::create(['shipment_id' => $shipmentId, 'supply_order_id' => $id, 'organization_id' => $order->organization_id, 'supplier_organization_id' => $order->supplier_organization_id, 'store_id' => $order->store_id,
                'reported_by' => $user->id, 'version' => 1, 'status' => 'reported', 'reason' => $v['reason'], 'reference' => $v['reference'] ?? null, 'snapshot' => $snapshot]);
            $this->audit($user, $order, 'shipment.discrepancy_reported', $case, ['reason' => $case->reason, 'reference' => $case->reference, 'snapshot' => $snapshot]);

            return ['order_id' => $id, 'shipment_id' => $shipmentId, 'case' => $case->toArray(), 'note' => null];
        });
    }

    public function note(User $user, string $id, string $shipmentId, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $side = $this->access->view($user, $initial);
        $org = $side === 'recipient' ? $initial->organization_id : $initial->supplier_organization_id;

        return $this->operations->run($org, $user->id, $v['operation_id'], 'shipment.discrepancy_note:'.$shipmentId, $v, function () use ($user, $id, $shipmentId, $v) {
            $order = $this->orders->lock($id);
            $side = $this->access->view($user, $order);
            $this->shipment($id, $shipmentId, true);
            $case = ShipmentDiscrepancy::where('supply_order_id', $id)->where('shipment_id', $shipmentId)->lockForUpdate()->firstOrFail();
            abort_unless($case->version === (int) $v['version'], 409, 'Kasus berubah. Muat ulang riwayat sebelum menambahkan catatan.');
            $case->version++;
            $case->save();
            $note = ShipmentDiscrepancyNote::create(['shipment_discrepancy_id' => $case->id, 'actor_id' => $user->id, 'organization_id' => $side === 'recipient' ? $order->organization_id : $order->supplier_organization_id,
                'side' => $side, 'case_version' => $case->version, 'message' => $v['message'], 'reference' => $v['reference'] ?? null, 'created_at' => now()]);
            $this->audit($user, $order, 'shipment.discrepancy_noted', $case, ['note' => $note->toArray()]);

            return ['order_id' => $id, 'shipment_id' => $shipmentId, 'case' => $case->toArray(), 'note' => $note->toArray()];
        });
    }

    public function proposeResolution(User $user, string $id, string $shipmentId, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->view($user, $initial);
        abort_unless($this->supplierDecision($user, $initial), 403);

        return $this->operations->run($initial->supplier_organization_id, $user->id, $v['operation_id'], 'shipment.resolution_propose:'.$shipmentId, $v, function () use ($user, $id, $shipmentId, $v) {
            $order = $this->orders->lock($id);
            $this->access->view($user, $order);
            abort_unless($this->supplierDecision($user, $order), 403);
            $this->shipment($id, $shipmentId, true);
            $case = ShipmentDiscrepancy::where('supply_order_id', $id)->where('shipment_id', $shipmentId)->lockForUpdate()->firstOrFail();
            abort_unless($case->version === (int) $v['version'] && $case->status === 'reported', 409, 'Versi/status kasus berubah.');
            abort_unless(Organization::whereKey($order->organization_id)->where('active', true)->exists(), 409, 'Organisasi penerima nonaktif.');
            abort_if(ShipmentDiscrepancyResolution::where('pending_case_id', $case->id)->exists(), 409, 'Masih ada usulan menunggu konfirmasi.');
            $resolution = ShipmentDiscrepancyResolution::create(['shipment_discrepancy_id' => $case->id, 'pending_case_id' => $case->id, 'proposed_by' => $user->id, 'type' => $v['type'], 'status' => 'pending', 'proposal' => $v['proposal'], 'reference' => $v['reference'] ?? null]);
            $case->version++;
            $case->status = 'awaiting_confirmation';
            $case->save();
            $this->audit($user, $order, 'shipment.discrepancy_resolution_proposed', $case, ['resolution' => $resolution->toArray()]);

            return ['order_id' => $id, 'shipment_id' => $shipmentId, 'case' => $case->toArray(), 'resolution' => $resolution->toArray()];
        });
    }

    public function decideResolution(User $user, string $id, string $shipmentId, string $resolutionId, string $action, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->view($user, $initial);
        abort_unless($action === 'withdraw' ? $this->supplierDecision($user, $initial) : $this->partnerDecision($user, $initial), 403);
        $org = $action === 'withdraw' ? $initial->supplier_organization_id : $initial->organization_id;

        return $this->operations->run($org, $user->id, $v['operation_id'], 'shipment.resolution_'.$action.':'.$resolutionId, $v, function () use ($user, $id, $shipmentId, $resolutionId, $action, $v) {
            $order = $this->orders->lock($id);
            $this->access->view($user, $order);
            abort_unless($action === 'withdraw' ? $this->supplierDecision($user, $order) : $this->partnerDecision($user, $order), 403);
            $this->shipment($id, $shipmentId, true);
            $case = ShipmentDiscrepancy::where('supply_order_id', $id)->where('shipment_id', $shipmentId)->lockForUpdate()->firstOrFail();
            $resolution = ShipmentDiscrepancyResolution::where('shipment_discrepancy_id', $case->id)->lockForUpdate()->findOrFail($resolutionId);
            abort_unless($case->version === (int) $v['version'] && $case->status === 'awaiting_confirmation' && $resolution->pending_case_id === $case->id && $resolution->status === 'pending', 409, 'Usulan atau versi kasus berubah.');
            if ($action === 'confirm') {
                abort_unless(Organization::whereKey($order->supplier_organization_id)->where('active', true)->exists(), 409, 'Organisasi pemasok nonaktif.');
            }
            $resolution->update(['pending_case_id' => null, 'status' => ['confirm' => 'confirmed', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action], 'decided_by' => $user->id, 'decision_note' => $v['reason'] ?? null, 'decided_at' => now()]);
            $case->version++;
            $case->status = $action === 'confirm' ? 'plan_confirmed' : 'reported';
            $case->save();
            $this->audit($user, $order, 'shipment.discrepancy_resolution_'.$resolution->status, $case, ['resolution' => $resolution->toArray()]);

            return ['order_id' => $id, 'shipment_id' => $shipmentId, 'case' => $case->toArray(), 'resolution' => $resolution->toArray()];
        });
    }
}
