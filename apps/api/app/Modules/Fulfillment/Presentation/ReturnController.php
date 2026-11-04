<?php

namespace App\Modules\Fulfillment\Presentation;

use App\Modules\Fulfillment\Application\ReturnWorkflow;
use Illuminate\Http\Request;

final class ReturnController
{
    public function __construct(private ReturnWorkflow $workflow) {}

    public function show(Request $r, string $order, string $shipment): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->workflow->context($r->user(), $order, $shipment))->header('Cache-Control', 'private, no-store');
    }

    public function dispatch(Request $r, string $order, string $shipment): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'destination_bucket' => 'required|in:quarantined', 'handler' => 'required|string|max:120', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.original_shipment_line_id' => 'required|ulid|distinct', 'lines.*.allocations' => 'required|array|min:1|max:100', 'lines.*.allocations.*.batch_id' => 'required|ulid', 'lines.*.allocations.*.quantity_packs' => 'required|integer|min:1|max:10000']);

        return response()->json(['data' => $this->workflow->dispatch($r->user(), $order, $shipment, $v)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function receive(Request $r, string $order, string $shipment, string $returnId): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1|max:10000', 'lines.*.return_line_id' => 'required|ulid|distinct', 'lines.*.received_packs' => 'required|integer|min:0|max:10000', 'lines.*.condition_note' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->workflow->receive($r->user(), $order, $shipment, $returnId, $v)])->header('Cache-Control', 'private, no-store');
    }
}
