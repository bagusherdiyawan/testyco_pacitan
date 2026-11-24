<?php

namespace App\Modules\Fulfillment\Application;

use App\Models\User;
use App\Modules\Billing\Infrastructure\Invoice;
use App\Modules\Billing\Infrastructure\InvoiceCredit;
use App\Modules\Fulfillment\Infrastructure\ReplacementShipment;
use App\Modules\Fulfillment\Infrastructure\ReturnShipment;
use App\Modules\Fulfillment\Infrastructure\Shipment;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancy;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancyResolution;
use App\Modules\Ordering\Application\OrderWorkflow;
use App\Modules\Ordering\Infrastructure\OrderChange;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use Illuminate\Support\Facades\DB;

/**
 * Transitions a partially_received order to settled once every dispatched shipment
 * has no unsent order remainder and every discrepancy case is fully resolved.
 */
final class OrderSettlement
{
    public function evaluate(User $actor, SupplyOrder $order): void
    {
        if ($order->status !== 'partially_received' || OrderChange::pending($order->id)) {
            return;
        }
        $sent = DB::table('shipment_lines as l')
            ->join('shipments as s', 's.id', '=', 'l.shipment_id')
            ->where('s.supply_order_id', $order->id)
            ->groupBy('l.supply_order_line_id')
            ->selectRaw('l.supply_order_line_id, SUM(l.quantity_packs) AS sent')
            ->pluck('sent', 'supply_order_line_id');
        foreach ($order->lines as $line) {
            if ((int) ($sent[$line->id] ?? 0) < $line->quantity_packs) {
                return;
            }
        }
        $shipments = Shipment::where('supply_order_id', $order->id)->with('lines')->get();
        if ($shipments->isEmpty() || $shipments->contains(fn ($s) => $s->received_at === null)) {
            return;
        }
        foreach ($shipments as $shipment) {
            $hasProblems = $shipment->lines->contains(fn ($l) => ($l->quantity_packs - (int) $l->good_packs) > 0);
            if (! $hasProblems) {
                continue;
            }
            $case = ShipmentDiscrepancy::where('supply_order_id', $order->id)->where('shipment_id', $shipment->id)->first();
            $plan = $case && $case->status === 'plan_confirmed'
                ? ShipmentDiscrepancyResolution::where('shipment_discrepancy_id', $case->id)->where('status', 'confirmed')->first()
                : null;
            if (! $case || ! $plan || ! $this->caseSettled($order, $shipment, $case, $plan)) {
                return;
            }
        }
        app(OrderWorkflow::class)->fulfillmentState($actor, $order, 'settled');
    }

    private function caseSettled(SupplyOrder $order, Shipment $shipment, ShipmentDiscrepancy $case, ShipmentDiscrepancyResolution $plan): bool
    {
        return match ($plan->type) {
            'replacement' => $this->replacementSettled($case),
            'return' => $this->returnSettled($order, $shipment, $case),
            'credit' => $order->kind === 'wholesale' && InvoiceCredit::where('case_id', $case->id)->where('status', 'accepted')->exists(),
            'write_off' => $order->kind === 'internal_transfer',
            default => false,
        };
    }

    private function replacementSettled(ShipmentDiscrepancy $case): bool
    {
        if (ReplacementShipment::where('case_id', $case->id)->whereNull('received_at')->exists()) {
            return false;
        }
        $good = DB::table('replacement_lines as l')
            ->join('replacement_shipments as s', 's.id', '=', 'l.replacement_shipment_id')
            ->where('s.case_id', $case->id)
            ->groupBy('l.original_shipment_line_id')
            ->selectRaw('l.original_shipment_line_id, SUM(COALESCE(l.good_packs, 0)) AS good')
            ->pluck('good', 'original_shipment_line_id');
        foreach ($case->snapshot['lines'] as $line) {
            $limit = $line['damaged_packs'] + $line['missing_packs'];
            if ($limit > 0 && (int) ($good[$line['shipment_line_id']] ?? 0) !== $limit) {
                return false;
            }
        }

        return true;
    }

    private function returnSettled(SupplyOrder $order, Shipment $shipment, ShipmentDiscrepancy $case): bool
    {
        if (ReturnShipment::where('case_id', $case->id)->whereNull('received_at')->exists()) {
            return false;
        }
        $received = DB::table('return_lines as l')
            ->join('return_shipments as s', 's.id', '=', 'l.return_shipment_id')
            ->where('s.case_id', $case->id)
            ->groupBy('l.original_shipment_line_id')
            ->selectRaw('l.original_shipment_line_id, SUM(COALESCE(l.received_packs, 0)) AS received')
            ->pluck('received', 'original_shipment_line_id');
        foreach ($case->snapshot['lines'] as $line) {
            if ($line['damaged_packs'] > 0 && (int) ($received[$line['shipment_line_id']] ?? 0) !== $line['damaged_packs']) {
                return false;
            }
        }
        if ($order->kind !== 'wholesale') {
            return true;
        }
        if (InvoiceCredit::where('case_id', $case->id)->where('status', 'accepted')->exists()) {
            return true;
        }
        $lineIds = $shipment->lines->pluck('id')->all();
        $goodReceivedBilled = Invoice::where('supply_order_id', $order->id)
            ->where('status', 'accepted')
            ->where('basis', 'good_received')
            ->get()
            ->flatMap(fn ($i) => array_column($i->lines ?? [], 'shipment_line_id'))
            ->unique()
            ->all();

        return count(array_diff($lineIds, $goodReceivedBilled)) === 0;
    }
}
