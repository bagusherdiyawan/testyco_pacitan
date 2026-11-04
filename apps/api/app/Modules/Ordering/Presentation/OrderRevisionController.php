<?php

namespace App\Modules\Ordering\Presentation;

use App\Modules\Ordering\Application\OrderRevisionWorkflow;
use Illuminate\Http\Request;

final class OrderRevisionController
{
    public function __construct(private OrderRevisionWorkflow $workflow) {}

    public function show(Request $r, string $order): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->workflow->context($r->user(), $order))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $r, string $order): mixed
    {
        $v = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'existing' => 'required|array|min:1|max:100', 'existing.*.order_line_id' => 'required|ulid|distinct', 'existing.*.quantity_packs' => 'required|integer|min:0|max:10000', 'additions' => 'sometimes|array|max:100', 'additions.*.supplier_product_id' => 'required|ulid', 'additions.*.local_product_id' => 'required|ulid', 'additions.*.quantity_packs' => 'required|integer|min:1|max:10000']);

        return response()->json(['data' => $this->workflow->preview($r->user(), $order, $v)])->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $r, string $order): mixed
    {
        $v = $r->validate(['review_hash' => 'sometimes|string|size:64', 'operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'existing' => 'required|array|min:1|max:100', 'existing.*.order_line_id' => 'required|ulid|distinct', 'existing.*.quantity_packs' => 'required|integer|min:0|max:10000', 'additions' => 'sometimes|array|max:100', 'additions.*.supplier_product_id' => 'required|ulid', 'additions.*.local_product_id' => 'required|ulid', 'additions.*.quantity_packs' => 'required|integer|min:1|max:10000']);

        return response()->json(['data' => $this->workflow->propose($r->user(), $order, $v)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function decide(Request $r, string $order, string $revision, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'withdraw'], true), 404);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => $action === 'approve' ? 'nullable|string|max:2000' : 'required|string|max:2000']);

        return response()->json(['data' => $this->workflow->decide($r->user(), $order, $revision, $action, $v)])->header('Cache-Control', 'private, no-store');
    }
}
