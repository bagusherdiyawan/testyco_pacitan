<?php

namespace App\Modules\Fulfillment\Presentation;

use App\Modules\Fulfillment\Application\ShipmentDiscrepancyWorkflow;
use Illuminate\Http\Request;

final class ShipmentDiscrepancyController
{
    public function __construct(private ShipmentDiscrepancyWorkflow $cases) {}

    public function show(Request $r, string $order, string $shipment): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'resolutions_page' => 'sometimes|integer|min:1']);

        return response()->json($this->cases->context($r->user(), $order, $shipment))->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $r, string $order, string $shipment): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'order_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'reference' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->cases->report($r->user(), $order, $shipment, $v)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function note(Request $r, string $order, string $shipment): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'message' => 'required|string|max:2000', 'reference' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->cases->note($r->user(), $order, $shipment, $v)])->header('Cache-Control', 'private, no-store');
    }

    public function proposeResolution(Request $r, string $order, string $shipment): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'type' => 'required|in:return,replacement,credit,write_off', 'proposal' => 'required|string|max:2000', 'reference' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->cases->proposeResolution($r->user(), $order, $shipment, $v)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function decideResolution(Request $r, string $order, string $shipment, string $resolution, string $action): mixed
    {
        abort_unless(in_array($action, ['confirm', 'reject', 'withdraw'], true), 404);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => $action === 'confirm' ? 'nullable|string|max:2000' : 'required|string|max:2000']);

        return response()->json(['data' => $this->cases->decideResolution($r->user(), $order, $shipment, $resolution, $action, $v)])->header('Cache-Control', 'private, no-store');
    }
}
