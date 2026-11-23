<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Product;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;

final class BundlePricing
{
    public static function recipe(string $product): ?array
    {
        $r = DB::table('product_recipes')->where('product_id', $product)->where('active', true)->first();
        if (! $r) {
            return null;
        }

        return ['id' => $r->id, 'version' => (int) $r->version, 'portion_equivalent' => (int) ($r->portion_equivalent ?? 0), 'components' => json_decode($r->components, true)];
    }

    public static function item(Product $p, array $row, array $recipe): array
    {
        abort_unless($row['unit'] === 'piece', 422, 'Paket dijual sebagai satu unit paket.');
        $quantity = (int) $row['quantity'];
        $components = collect($recipe['components'])->keyBy('product_id');
        $totals = [];
        $allocations = [];
        $seen = [];
        foreach ($row['allocations'] as $a) {
            $id = $a['component_product_id'] ?? $a['product_id'] ?? null;
            $component = $components->get($id);
            abort_unless($component, 422, 'Pilih SKU komponen resep untuk tiap alokasi.');
            $key = $a['batch_id'].':'.$a['bucket'];
            abort_if(isset($seen[$key]), 422, 'Batch/bucket komponen diulang.');
            $seen[$key] = true;
            $pieces = (int) $a['quantity'];
            $totals[$id] = ($totals[$id] ?? 0) + $pieces;
            $allocations[] = ['batch_id' => $a['batch_id'], 'bucket' => $a['bucket'], 'quantity' => $pieces, 'pieces' => $pieces, 'component_product_id' => $id, 'product_id' => $id, 'condition' => $component['condition'], 'unit' => 'piece', 'pieces_per_unit' => 1];
        }
        foreach ($components as $id => $component) {
            $current = Product::where('organization_id', $p->organization_id)->where('active', true)->lockForUpdate()->findOrFail($id);
            abort_unless($current->condition === $component['condition'] && ($totals[$id] ?? 0) === $component['pieces'] * $quantity, 422, 'Alokasi wajib sesuai seluruh isi resep dan jumlah paket.');
        }
        usort($allocations, fn ($a, $b) => strcmp($a['batch_id'].':'.$a['bucket'], $b['batch_id'].':'.$b['bucket']));
        $price = EffectivePrice::for($p, 'retail');

        return ['product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'condition' => $p->condition, 'catalog_pack_size' => 1, 'unit' => 'piece', 'quantity' => $quantity, 'pieces_per_unit' => 1, 'unit_price' => $price, 'line_total' => Rupiah::multiply($price, $quantity), 'allocations' => $allocations, 'recipe_snapshot' => $recipe];
    }
}
