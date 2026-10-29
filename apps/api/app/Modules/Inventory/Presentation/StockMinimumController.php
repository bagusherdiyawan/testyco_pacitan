<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\StockMinimums;
use Illuminate\Http\Request;

final class StockMinimumController
{
    public function __construct(private StockMinimums $minimums) {}

    public function index(Request $r, string $store): mixed
    {
        $v = $r->validate(['q' => 'sometimes|nullable|string|max:100', 'low_only' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1']);

        return $this->minimums->report($r->user(), $store, $v);
    }

    public function configure(Request $r, string $store, string $product): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:0|max:1000000000', 'minimum_pieces' => 'present|nullable|integer|min:0|max:1000000000', 'reason' => 'required|string|max:2000']);

        return ['data' => $this->minimums->configure($r->user(), $store, $product, $v)];
    }

    public function show(Request $r, string $store, string $product): mixed
    {
        return response()->json($this->minimums->detail($r->user(), $store, $product))->header('Cache-Control', 'private, no-store');
    }
}
