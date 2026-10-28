<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;

final class StockMinimumController
{
    public function __construct(private Backend $api) {}

    public function index(Request $r, string $store): mixed
    {
        $v = $r->validate(['q' => 'sometimes|nullable|string|max:100', 'low_only' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1']);

        return view('pages.operations.minimums', ['report' => $this->api->request('GET', "stores/$store/inventory/minimums", $v)->json(), 'filters' => $v]);
    }

    public function configure(Request $r, string $store, string $product): mixed
    {
        $this->api->request('POST', "stores/$store/inventory/minimums/$product", $r->only('operation_id', 'version', 'minimum_pieces', 'reason'));

        return redirect('/app/stores/'.$store.'/stock-minimums')->with('status', 'Ambang stok disimpan.');
    }
}
