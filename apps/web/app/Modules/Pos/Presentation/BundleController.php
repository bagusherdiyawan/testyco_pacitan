<?php

namespace App\Modules\Pos\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;

final class BundleController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    private function slot(string $store): string
    {
        return 'bundle_pending.'.$store;
    }

    public function show(string $store, string $product): mixed
    {
        $pending = session($this->slot($store));
        if ($pending && $pending['product_id'] !== $product) {
            return redirect($pending['target']);
        }if (! $pending) {
            $c = $this->api->request('GET', "stores/$store/catalog/$product/bundle")->json();
            $pos = $this->api->request('GET', "stores/$store/pos/context")->json('data');
            abort_unless(data_get($c, 'product.id') === $product && data_get($pos, 'store.id') === $store && is_array($c['recipe']['components'] ?? null), 503, 'Cakupan resep paket tidak valid.');
            foreach ($c['batches'] as $b) {
                abort_unless(($b['store_id'] ?? null) === $store, 503, 'Batch komponen bukan milik toko.');
            }
        }

        return response()->view('pages.pos.bundle', compact('store', 'product', 'pending') + ($pending ? [] : compact('c', 'pos')))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $store, string $product): mixed
    {
        abort_if(session()->has($this->slot($store)), 409, 'Selesaikan draft yang belum pasti.');
        $v = $r->validate(['operation_id' => 'required|uuid', 'shift_id' => 'required|ulid', 'voucher_code' => 'nullable|string|max:60', 'quantity' => 'required|integer|min:1|max:10000', 'components' => 'required|array|min:1|max:50', 'components.*.product_id' => 'required|ulid|distinct', 'components.*.stock' => 'required|string|max:100']);
        $c = $this->api->request('GET', "stores/$store/catalog/$product/bundle")->json();
        abort_unless(data_get($c, 'product.id') === $product && count($v['components']) === count($c['recipe']['components']), 409, 'Resep paket berubah.');
        $allocations = [];
        foreach ($v['components'] as $row) {
            $component = collect($c['recipe']['components'])->firstWhere('product_id', $row['product_id']);
            abort_unless($component, 422, 'Komponen tidak sesuai resep.');
            $pair = explode(':', $row['stock'], 2);
            abort_unless(count($pair) === 2 && in_array($pair[1], ['opened', 'ready'], true), 422, 'Pilih stok terbuka/siap jual.');
            $batch = collect($c['batches'])->firstWhere('id', $pair[0]);
            $pieces = $component['pieces'] * (int) $v['quantity'];
            abort_unless($batch && $batch['product_id'] === $component['product_id'] && $batch[$pair[1].'_pieces'] >= $pieces, 422, 'Stok komponen tidak cukup.');
            $allocations[] = ['component_product_id' => $component['product_id'], 'batch_id' => $pair[0], 'bucket' => $pair[1], 'quantity' => $pieces];
        }
        $body = [...(isset($v['voucher_code']) ? ['voucher_code' => $v['voucher_code']] : []), 'operation_id' => $v['operation_id'], 'shift_id' => $v['shift_id'], 'lines' => [['product_id' => $product, 'unit' => 'piece', 'quantity' => (int) $v['quantity'], 'allocations' => $allocations]]];

        return $this->send($store, ['store_id' => $store, 'product_id' => $product, 'path' => "stores/$store/sales/intents", 'body' => $body, 'target' => "/app/stores/$store/pos/bundles/$product", 'review' => $c]);
    }

    public function retry(string $store, string $product): mixed
    {
        $p = session($this->slot($store));
        abort_unless(is_array($p) && $p['product_id'] === $product, 409);

        return $this->send($store, $p);
    }

    private function send(string $store, array $p): mixed
    {
        return $this->mutations->send($this->slot($store), $p, fn ($d, $p) => is_string($d['id'] ?? null) && ($d['store_id'] ?? null) === $store && ($d['cash_shift_id'] ?? null) === $p['body']['shift_id'] && ($d['status'] ?? null) === 'pending', fn ($d) => '/app/pos/intents/'.$d['id']);
    }
}
