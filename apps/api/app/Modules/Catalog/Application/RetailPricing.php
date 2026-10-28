<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Product;
use App\Shared\Domain\Rupiah;

final class RetailPricing
{
    public function snapshot(string $organization, array $requested, bool $lock = false): array
    {
        $ids = array_column($requested, 'product_id');
        $query = Product::where('organization_id', $organization)->where('active', true)->whereIn('id', $ids)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $products = $query->get()->keyBy('id');
        usort($requested, fn ($a, $b) => strcmp($a['product_id'], $b['product_id']));
        $items = [];
        $total = 0;
        foreach ($requested as $row) {
            $product = $products->get($row['product_id']);
            abort_unless($product, 404, 'Produk aktif tidak ditemukan pada organisasi toko.');
            $quantity = (int) $row['quantity'];
            $pieces = match ($row['unit']) {
                'piece' => 1,'portion' => $product->portion_size,'pack' => $product->pack_size
            };
            $price = Rupiah::multiply($product->retail_price, $pieces);
            $amount = Rupiah::multiply($price, $quantity);
            $total = Rupiah::add($total, $amount);
            $allocations = [];
            $allocated = 0;
            $seen = [];
            foreach ($row['allocations'] as $allocation) {
                $key = $allocation['batch_id'].':'.$allocation['bucket'];
                abort_if(isset($seen[$key]), 422, 'Batch/bucket tidak boleh diulang dalam item yang sama.');
                $seen[$key] = true;
                $q = (int) $allocation['quantity'];
                $allocated += $q;
                $allocations[] = ['batch_id' => $allocation['batch_id'], 'bucket' => $allocation['bucket'], 'quantity' => $q, 'pieces' => $q * $pieces];
            }
            abort_unless($allocated === $quantity, 422, 'Alokasi stok harus sama dengan jumlah unit dalam keranjang.');
            usort($allocations, fn ($a, $b) => strcmp($a['batch_id'].':'.$a['bucket'], $b['batch_id'].':'.$b['bucket']));
            $items[] = ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'condition' => $product->condition, 'catalog_pack_size' => $product->pack_size, 'unit' => $row['unit'], 'quantity' => $quantity, 'pieces_per_unit' => $pieces, 'unit_price' => $price, 'line_total' => $amount, 'allocations' => $allocations];
        }

        return ['items' => $items, 'total_amount' => $total, 'price_hash' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR))];
    }
}
