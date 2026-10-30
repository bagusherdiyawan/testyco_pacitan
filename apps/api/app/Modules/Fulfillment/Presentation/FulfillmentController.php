<?php

namespace App\Modules\Fulfillment\Presentation;

use App\Modules\Fulfillment\Application\FulfillmentWorkflow;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use Illuminate\Http\Request;

final class FulfillmentController
{
    public function __construct(private FulfillmentWorkflow $workflow) {}

    public function warehouseInbox(Request $r, string $store, \App\Modules\Fulfillment\Application\WarehouseReadModel $read): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($read->inbox($r->user(), $store))->header('Cache-Control', 'private, no-store');
    }

    public function warehouseContext(Request $r, string $store, string $order, \App\Modules\Fulfillment\Application\WarehouseReadModel $read): mixed
    {
        return response()->json(['data' => $read->context($r->user(), $store, $order)])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $r, string $order, OrderAccess $access): mixed
    {
        $access->view($r->user(), SupplyOrder::findOrFail($order));

        return ['data' => $this->workflow->shipments($order)];
    }

    public function dispatch(Request $r, string $order): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'handler' => 'required|string|max:120', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.order_line_id' => 'required|ulid|distinct', 'lines.*.allocations' => 'required|array|min:1|max:100', 'lines.*.allocations.*.batch_id' => 'required|ulid', 'lines.*.allocations.*.quantity_packs' => 'required|integer|min:1|max:10000']);
        foreach ($v['lines'] as $line) {
            abort_unless(count(array_unique(array_column($line['allocations'], 'batch_id'))) === count($line['allocations']), 422, 'Batch yang sama tidak boleh diulang dalam satu item.');
        }

        return response()->json(['data' => $this->workflow->dispatch($r->user(), $order, $v)], 201);
    }

    public function receive(Request $r, string $shipment): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1|max:10000', 'lines.*.shipment_line_id' => 'required|ulid|distinct', 'lines.*.good_packs' => 'required|integer|min:0|max:10000', 'lines.*.damaged_packs' => 'required|integer|min:0|max:10000', 'lines.*.condition_note' => 'nullable|string|max:2000']);

        return ['data' => $this->workflow->receive($r->user(), $shipment, $v)];
    }
}
