<?php

namespace App\Modules\Consumables\Presentation;

use App\Modules\Consumables\Application\ConsumableBook;
use App\Modules\Consumables\Application\ConsumableShipments;
use Illuminate\Http\Request;

final class ConsumableController
{
    public function __construct(private ConsumableBook $book, private ConsumableShipments $shipments) {}

    public function catalogue(Request $r, string $organization): mixed
    {
        return response()->json(['data' => $this->book->catalogue($r->user(), $organization)])->header('Cache-Control', 'private, no-store');
    }

    public function createItem(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'name' => 'required|string|max:120', 'unit' => 'required|string|max:30']);

        return response()->json(['data' => $this->book->createItem($r->user(), $organization, $v)], 201);
    }

    public function itemStatus(Request $r, string $organization, string $item): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'active' => 'required|boolean', 'reason' => 'required|string|max:2000']);

        return ['data' => $this->book->setItemActive($r->user(), $organization, $item, $v)];
    }

    public function rule(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'service_type' => 'required|in:'.implode(',', ConsumableBook::SERVICE_TYPES), 'consumable_item_id' => 'required|ulid', 'basis' => 'required|in:'.implode(',', ConsumableBook::BASES), 'quantity' => 'required|integer|min:0|max:1000']);

        return ['data' => $this->book->setRule($r->user(), $organization, $v)];
    }

    public function stock(Request $r, string $store): mixed
    {
        return response()->json(['data' => $this->book->stock($r->user(), $store)])->header('Cache-Control', 'private, no-store');
    }

    public function purchase(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'consumable_item_id' => 'required|ulid', 'quantity' => 'required|integer|min:1|max:1000000', 'amount' => 'nullable|integer|min:0|max:1000000000000', 'note' => 'nullable|string|max:400', 'shift_id' => 'nullable|ulid', 'shift_version' => 'nullable|integer|min:1']);

        return response()->json(['data' => $this->book->purchase($r->user(), $store, $v)], 201);
    }

    public function count(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'consumable_item_id' => 'required|ulid', 'counted' => 'required|integer|min:0|max:1000000', 'reason' => 'required|string|max:1000']);

        return response()->json(['data' => $this->book->count($r->user(), $store, $v)], 201);
    }

    public function shipments(Request $r, string $store): mixed
    {
        return response()->json($this->shipments->index($r->user(), $store))->header('Cache-Control', 'private, no-store');
    }

    public function dispatchShipment(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'destination_store_id' => 'required|ulid', 'note' => 'nullable|string|max:500', 'lines' => 'required|array|min:1|max:50', 'lines.*.consumable_item_id' => 'required|ulid', 'lines.*.quantity' => 'required|integer|min:1|max:1000000']);

        return response()->json(['data' => $this->shipments->dispatch($r->user(), $store, $v)], 201);
    }

    public function receiveShipment(Request $r, string $shipment): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'note' => 'nullable|string|max:1000', 'lines' => 'required|array|min:1|max:50', 'lines.*.line_id' => 'required|ulid', 'lines.*.destination_item_id' => 'required|ulid', 'lines.*.quantity_received' => 'required|integer|min:0|max:1000000']);

        return ['data' => $this->shipments->receive($r->user(), $shipment, $v)];
    }
}
