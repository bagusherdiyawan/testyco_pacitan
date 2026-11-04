<?php

namespace App\Modules\Catalog\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;

final class CatalogExtensionController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    private function slot(string $id): string
    {
        return 'catalog_configuration_pending.'.$id;
    }

    private function path(string $org, string $product): string
    {
        return "organizations/$org/products/$product";
    }

    public function show(string $organization, string $product): mixed
    {
        $pending = session($this->slot($product));
        if ($pending) {
            if ($pending['organization_id'] !== $organization) {
                return redirect($pending['target']);
            }

            return response()->view('pages.product-configuration', compact('organization', 'product', 'pending'))->header('Cache-Control', 'private, no-store');
        }
        $c = $this->api->request('GET', $this->path($organization, $product).'/configuration')->json();
        abort_unless(data_get($c, 'product.id') === $product && data_get($c, 'product.organization_id') === $organization, 503, 'Cakupan konfigurasi produk tidak valid.');

        foreach ($c['prices'] ?? [] as $row) {
            abort_unless(($row['product_id'] ?? null) === $product && ($row['organization_id'] ?? null) === $organization, 503, 'Periode harga bukan milik produk ini.');
        }

        return response()->view('pages.product-configuration', compact('organization', 'product', 'pending', 'c'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $r, string $organization, string $product, string $action): mixed
    {
        abort_if(session()->has($this->slot($product)), 409, 'Selesaikan request belum pasti.');
        $base = ['operation_id' => 'required|uuid'];
        $rules = match ($action) {
            'prices' => ['channel' => 'required|in:retail,wholesale', 'partner_id' => 'nullable|ulid', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'price' => 'required|integer|min:0|max:1000000000', 'reason' => 'required|string|max:2000'],'recipe' => ['version' => 'required|integer|min:0', 'components' => 'required|array|min:1|max:50', 'components.*.product_id' => 'nullable|ulid', 'components.*.pieces' => 'nullable|integer|min:1|max:10000'],'image' => ['version' => 'required|integer|min:0', 'image' => 'required|file|max:5120'],default => abort(404)
        };
        $v = $r->validate([...$base, ...$rules]);
        if ($action === 'recipe') {
            $v['active'] = $r->boolean('active');
            $v['components'] = array_values(array_filter($v['components'], fn ($x) => isset($x['pieces'])));
            foreach ($v['components'] as $row) {
                abort_unless(isset($row['product_id']), 422, 'Pilih SKU tiap komponen.');
            }
        }
        if ($action === 'image') {
            $v['image_base64'] = base64_encode($r->file('image')->getContent());
            unset($v['image']);
        }

        return $this->send($product, ['organization_id' => $organization, 'product_id' => $product, 'path' => $this->path($organization, $product).'/'.$action, 'body' => $v, 'action' => $action, 'target' => "/app/organizations/$organization/products/$product/configuration", 'expected_version' => isset($v['version']) ? (int) $v['version'] + 1 : null]);
    }

    public function cancel(Request $r, string $organization, string $product, string $price): mixed
    {
        abort_if(session()->has($this->slot($product)), 409);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);

        return $this->send($product, ['organization_id' => $organization, 'product_id' => $product, 'path' => $this->path($organization, $product)."/prices/$price/cancel", 'body' => $v, 'action' => 'cancel', 'price_id' => $price, 'target' => "/app/organizations/$organization/products/$product/configuration", 'expected_version' => (int) $v['version'] + 1]);
    }

    public function retry(string $organization, string $product): mixed
    {
        $p = session($this->slot($product));
        abort_unless(is_array($p) && $p['organization_id'] === $organization, 409);

        return $this->send($product, $p);
    }

    private function send(string $product, array $p): mixed
    {
        return $this->mutations->send($this->slot($product), $p, function ($d, $p) use ($product) {
            if (($d['product_id'] ?? null) !== $product || ($p['expected_version'] !== null && ($d['version'] ?? null) !== $p['expected_version'])) {
                return false;
            }if ($p['action'] === 'cancel') {
                return ($d['id'] ?? null) === $p['price_id'] && ! $d['active'];
            }if ($p['action'] === 'prices') {
                return ($d['organization_id'] ?? null) === $p['organization_id'] && (int) ($d['price'] ?? -1) === (int) $p['body']['price'] && ($d['channel'] ?? null) === $p['body']['channel'] && ($d['starts_on'] ?? null) === $p['body']['starts_on'] && ($d['ends_on'] ?? null) === $p['body']['ends_on'] && ($d['partner_id'] ?? null) === ($p['body']['partner_id'] ?? null);
            }

            if ($p['action'] === 'image') {
                return ($d['sha256'] ?? null) === hash('sha256', base64_decode($p['body']['image_base64']));
            }
            if ($p['action'] === 'recipe') {
                $expected = array_map(fn ($x) => [$x['product_id'], (int) $x['pieces']], $p['body']['components']);
                $actual = array_map(fn ($x) => [$x['product_id'], (int) $x['pieces']], $d['components'] ?? []);
                sort($expected);
                sort($actual);

                return $expected === $actual && ($d['active'] ?? null) === $p['body']['active'];
            }

            return false;
        });
    }

    public function image(string $organization, string $product): mixed
    {
        $response = $this->api->request('GET', $this->path($organization, $product).'/image');
        $mime = $response->header('Content-Type');
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 503);

        return response($response->body())->header('Content-Type', $mime)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
