<?php

namespace App\Modules\Catalog\Presentation;

use App\Modules\Catalog\Application\CatalogConfiguration;
use App\Modules\Inventory\InventoryServiceProvider;
use Illuminate\Http\Request;

final class CatalogExtensionController
{
    public function __construct(private CatalogConfiguration $work) {}

    public function show(Request $r, string $organization, string $product): mixed
    {
        return response()->json($this->work->show($r->user(), $organization, $product))->header('Cache-Control', 'private, no-store');
    }

    public function price(Request $r, string $organization, string $product): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'channel' => 'required|in:retail,wholesale', 'partner_id' => 'nullable|ulid', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'price' => 'required|integer|min:0|max:1000000000', 'reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->work->price($r->user(), $organization, $product, $v)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function cancelPrice(Request $r, string $organization, string $product, string $price): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->work->cancelPrice($r->user(), $organization, $product, $price, $v)], 200)->header('Cache-Control', 'private, no-store');
    }

    public function saveRecipe(Request $r, string $organization, string $product): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:0', 'active' => 'required|boolean', 'components' => 'required|array|min:1|max:50', 'components.*.product_id' => 'required|ulid|distinct', 'components.*.pieces' => 'required|integer|min:1|max:10000']);

        return response()->json(['data' => $this->work->saveRecipe($r->user(), $organization, $product, $v)], 200)->header('Cache-Control', 'private, no-store');
    }

    public function image(Request $r, string $organization, string $product): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:0', 'image_base64' => 'required|string|max:7000000']);

        return response()->json(['data' => $this->work->image($r->user(), $organization, $product, $v)], 200)->header('Cache-Control', 'private, no-store');
    }

    private function imageResponse(array $file): mixed
    {
        return response($file['bytes'])->header('Content-Type', $file['mime'])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function readImage(Request $r, string $organization, string $product): mixed
    {
        return $this->imageResponse($this->work->managedImage($r->user(), $organization, $product));
    }

    public function storeImage(Request $r, string $store, string $product): mixed
    {
        return $this->imageResponse($this->work->storeImage($r->user(), $store, $product));
    }

    public function imageData(Request $r, string $store, string $product): mixed
    {
        $file = $this->work->storeImage($r->user(), $store, $product);

        return response()->json(['product_id' => $product, 'mime' => $file['mime'], 'image_base64' => base64_encode($file['bytes'])])->header('Cache-Control', 'private, no-store');
    }

    public function bundle(Request $r, string $store, string $product): mixed
    {
        abort_unless(app()->getProvider(InventoryServiceProvider::class), 404);

        return response()->json($this->work->bundle($r->user(), $store, $product))->header('Cache-Control', 'private, no-store');
    }
}
