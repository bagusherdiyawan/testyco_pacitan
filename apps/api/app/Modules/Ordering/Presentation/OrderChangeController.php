<?php

namespace App\Modules\Ordering\Presentation;

use App\Modules\Ordering\Application\OrderChangeWorkflow;
use Illuminate\Http\Request;

final class OrderChangeController
{
    public function __construct(private OrderChangeWorkflow $workflow) {}

    public function index(Request $r, string $order): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->workflow->context($r->user(), $order))->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $r, string $order): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array:order_line_id,quantity_packs', 'lines.*.order_line_id' => 'required|ulid|distinct', 'lines.*.quantity_packs' => 'required|integer|min:1|max:10000']);

        return response()->json(['data' => $this->workflow->propose($r->user(), $order, $v)], 201);
    }

    public function decide(Request $r, string $order, string $change, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'withdraw'], true), 404);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => $action === 'approve' ? 'nullable|string|max:2000' : 'required|string|max:2000']);

        return ['data' => $this->workflow->decide($r->user(), $order, $change, $action, $v)];
    }
}
