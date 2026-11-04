<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\RefundWorkflow;
use Illuminate\Http\Request;

final class RefundController
{
    public function __construct(private RefundWorkflow $workflow) {}

    private function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'shift_id' => 'required|ulid', 'shift_version' => 'required|integer|min:1', 'stock_disposition' => 'required|in:no_stock,quarantine', 'reference' => 'required|string|max:160', 'reason' => 'required|string|max:1800', 'lines' => 'required|array|min:1|max:100', 'lines.*.sale_line_id' => 'required|ulid|distinct', 'lines.*.quantity' => 'required|integer|min:1|max:10000'];
    }

    public function context(Request $r, string $sale): mixed
    {
        return response()->json($this->workflow->context($r->user(), $sale))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $r, string $sale): mixed
    {
        $v = $r->validate($this->rules());

        return response()->json(['data' => $this->workflow->preview($r->user(), $sale, $v)])->header('Cache-Control', 'private, no-store');
    }

    public function refund(Request $r, string $sale): mixed
    {
        $v = $r->validate([...$this->rules(), 'operation_id' => 'required|uuid', 'review_hash' => 'required|string|size:64', 'verified' => 'required|boolean']);

        return response()->json(['data' => $this->workflow->refund($r->user(), $sale, $v)], 201);
    }
}
