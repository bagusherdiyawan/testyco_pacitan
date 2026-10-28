<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;

final class SupplyCatalog
{
    public function available()
    {
        return Product::where('active', true)->whereIn('organization_id', Organization::where('type', 'center')->where('active', true)->select('id'));
    }

    public function line(string $supplierProduct, string $localProduct, string $organization, int $quantity, bool $internal): array
    {
        $supplier = $this->available()->lockForUpdate()->findOrFail($supplierProduct);
        $local = Product::where('organization_id', $organization)->where('active', true)->lockForUpdate()->findOrFail($localProduct);
        abort_unless($supplier->pack_size === $local->pack_size && $supplier->condition === $local->condition, 422, 'SKU toko dan pusat harus mempunyai isi kemasan dan kondisi yang sama.');
        abort_if($internal && $supplier->id !== $local->id, 422, 'Transfer internal harus menggunakan SKU yang sama.');

        return ['supplier_product_id' => $supplier->id, 'local_product_id' => $local->id, 'sku' => $supplier->sku, 'name' => $supplier->name, 'local_sku' => $local->sku, 'local_name' => $local->name, 'condition' => $supplier->condition, 'pack_size' => $supplier->pack_size, 'quantity_packs' => $quantity, 'unit_price' => $internal ? 0 : $supplier->wholesale_price, 'supplier_organization_id' => $supplier->organization_id];
    }

    public function intakeProduct(string $id, string $organization): array
    {
        return Product::where('organization_id', $organization)->where('active', true)->lockForUpdate()->findOrFail($id)->only(['id', 'sku', 'name', 'condition', 'pack_size']);
    }
}
