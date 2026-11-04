<?php

namespace App\Modules\Promotions\Presentation;

use App\Modules\Promotions\Application\VoucherBook;
use Illuminate\Http\Request;

final class VoucherController
{
    public function __construct(private VoucherBook $book) {}

    public function index(Request $r, string $organization): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->book->index($r->user(), $organization))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'code' => 'required|string|max:60|regex:/^[A-Z0-9_-]+$/', 'funding_organization_id' => 'required|ulid', 'kind' => 'required|in:fixed,percent', 'value' => 'required|integer|min:1|max:1000000000000', 'maximum_discount' => 'required|integer|min:1|max:1000000000000', 'minimum_subtotal' => 'required|integer|min:0|max:1000000000000', 'usage_limit' => 'required|integer|min:1|max:1000000', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'store_ids' => 'required|array|min:1|max:100', 'store_ids.*' => 'required|ulid|distinct', 'product_ids' => 'present|array|max:100', 'product_ids.*' => 'required|ulid|distinct', 'restore_policy' => 'required|in:never,on_full_refund', 'terms' => 'required|string|max:2000']);

        return response()->json(['data' => $this->book->create($r->user(), $organization, $v)], 201);
    }

    public function status(Request $r, string $organization, string $voucher): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'active' => 'required|boolean', 'reason' => 'required|string|max:2000']);

        return ['data' => $this->book->status($r->user(), $organization, $voucher, $v)];
    }
}
