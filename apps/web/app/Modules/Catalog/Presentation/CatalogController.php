<?php

namespace App\Modules\Catalog\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;

final class CatalogController
{
    public function __construct(private Backend $api) {}

    public function index(string $organization): mixed
    {
        return view('pages.catalog', ['organization' => $organization, 'products' => $this->api->page("organizations/$organization/products")]);
    }

    public function save(Request $r, string $organization, ?string $product = null): mixed
    {
        $data = $r->only('sku', 'name', 'variant', 'condition', 'pack_size', 'portion_size', 'retail_price', 'wholesale_price');
        $data['active'] = $r->boolean('active');
        $this->api->request($product ? 'PUT' : 'POST', "organizations/$organization/products".($product ? "/$product" : ''), $data);

        return back()->with('status', 'Produk disimpan.');
    }
}
