<?php

namespace App\Modules\Sales\Application;

use App\Modules\Catalog\Application\BundlePricing;
use App\Modules\Catalog\Application\EffectivePrice;
use App\Modules\Catalog\Infrastructure\Product;
use App\Shared\Domain\Rupiah;

final class OfflinePricing
{
    public function catalogue(string $org): array
    {
        return Product::where('organization_id', $org)->where('active', true)->orderBy('id')->limit(1001)->get()->map(fn ($p) => [...$p->only(['id', 'sku', 'name', 'condition', 'pack_size', 'portion_size']), 'retail_price' => EffectivePrice::for($p, 'retail'), 'recipe' => BundlePricing::recipe($p->id)])->all();
    }

    public function snapshot(array $catalogue, array $rows): array
    {
        $products = collect($catalogue)->keyBy('id');
        $items = [];
        $total = 0;
        usort($rows, fn ($a, $b) => strcmp($a['product_id'], $b['product_id']));
        foreach ($rows as $row) {
            $p = $products->get($row['product_id']);
            abort_unless($p, 422, 'Produk tidak termasuk katalog izin offline.');
            $quantity = (int) $row['quantity'];
            $recipe = $p['recipe'];
            $per = $recipe ? 1 : match ($row['unit']) {
                'piece' => 1,'portion' => $p['portion_size'],'pack' => $p['pack_size']
            };
            abort_unless(! $recipe || $row['unit'] === 'piece', 422, 'Paket offline memakai unit paket.');
            $price = Rupiah::multiply($p['retail_price'], $per);
            $allocations = [];
            $seen = [];
            $n = 0;
            $components = [];
            foreach ($row['allocations'] as $a) {
                $key = $a['batch_id'].':'.$a['bucket'];
                abort_if(isset($seen[$key]), 422, 'Alokasi batch diulang.');
                $seen[$key] = true;
                $q = (int) $a['quantity'];
                if ($recipe) {
                    $component = collect($recipe['components'])->firstWhere('product_id', $a['component_product_id'] ?? null);
                    abort_unless($component, 422, 'Komponen bukan bagian resep izin.');
                    $components[$component['product_id']] = ($components[$component['product_id']] ?? 0) + $q;
                    $allocations[] = ['batch_id' => $a['batch_id'], 'bucket' => $a['bucket'], 'quantity' => $q, 'pieces' => $q, 'component_product_id' => $component['product_id'], 'product_id' => $component['product_id'], 'condition' => $component['condition'], 'unit' => 'piece', 'pieces_per_unit' => 1];
                } else {
                    $n += $q;
                    $allocations[] = ['batch_id' => $a['batch_id'], 'bucket' => $a['bucket'], 'quantity' => $q, 'pieces' => $q * $per];
                }
            }if ($recipe) {
                foreach ($recipe['components'] as $c) {
                    abort_unless(($components[$c['product_id']] ?? 0) === $c['pieces'] * $quantity, 422, 'Jumlah komponen tidak sama dengan resep izin.');
                }
            } else {
                abort_unless($n === $quantity, 422, 'Jumlah alokasi tidak sama dengan unit jual.');
            }usort($allocations, fn ($a, $b) => strcmp($a['batch_id'].':'.$a['bucket'], $b['batch_id'].':'.$b['bucket']));
            $line = Rupiah::multiply($price, $quantity);
            $total = Rupiah::add($total, $line);
            $items[] = ['product_id' => $p['id'], 'sku' => $p['sku'], 'name' => $p['name'], 'condition' => $p['condition'], 'catalog_pack_size' => $p['pack_size'], 'unit' => $row['unit'], 'quantity' => $quantity, 'pieces_per_unit' => $per, 'unit_price' => $price, 'line_total' => $line, 'allocations' => $allocations, ...($recipe ? ['recipe_snapshot' => $recipe] : [])];
        }

        return ['items' => $items, 'total_amount' => $total, 'price_hash' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR))];
    }
}
