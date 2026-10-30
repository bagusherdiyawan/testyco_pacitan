<?php

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Catalog\Application\SupplyCatalog;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WarehouseIntake
{
    public function __construct(private Access $access, private SupplyCatalog $catalog, private StockLedger $stock, private Operations $operations) {}

    private function source(User $user, string $id): Store
    {
        $store = $this->access->operatingStore($user, $id, ['owner', 'admin', 'warehouse']);
        abort_unless(Organization::findOrFail($store->organization_id)->type === 'center', 403, 'Penerimaan awal gudang hanya untuk pusat; toko mitra melalui pesanan yang disetujui.');

        return $store;
    }

    public function products(User $user, string $id): array
    {
        $store = $this->source($user, $id);
        $page = Product::where('organization_id', $store->organization_id)->where('active', true)->orderBy('name')->orderBy('id')->paginate(100, ['id', 'sku', 'name', 'condition', 'pack_size']);

        return [...$page->toArray(), 'store' => $store->only(['id', 'name', 'active']), 'can_intake' => true, 'observed_at' => now()->toIso8601String()];
    }

    private function snapshot(Store $initial, array $v): array
    {
        $store = Store::whereKey($initial->id)->lockForUpdate()->firstOrFail();
        abort_unless($store->active, 409, 'Toko nonaktif.');
        $product = $this->catalog->intakeProduct($v['product_id'], $store->organization_id);
        $existing = InventoryBatch::where('store_id', $store->id)->where('product_id', $product['id'])->where('batch_code', $v['batch_code'])->where('pack_size', $product['pack_size'])->first();
        if ($existing) {
            abort_unless($existing->expires_on->format('Y-m-d') === $v['expires_on'] && $existing->condition === $product['condition'], 409, 'Identitas batch sudah ada dengan tanggal/kondisi berbeda.');
        }

        return ['store_id' => $store->id, 'store_name' => $store->name, 'product_id' => $product['id'], 'sku' => $product['sku'], 'name' => $product['name'], 'condition' => $product['condition'], 'pack_size' => $product['pack_size'], 'batch_code' => $v['batch_code'], 'expires_on' => $v['expires_on'], 'quantity_packs' => (int) $v['quantity_packs'], 'quantity_pieces' => (int) $v['quantity_packs'] * $product['pack_size'], 'note' => $v['note']];
    }

    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function preview(User $user, string $id, array $v): array
    {
        $source = $this->source($user, $id);

        return DB::transaction(function () use ($source, $v) {
            $snapshot = $this->snapshot($source, $v);

            return [...$snapshot, 'review_hash' => $this->hash($snapshot), 'observed_at' => now()->toIso8601String()];
        });
    }

    public function record(User $user, string $id, array $v): array
    {
        $source = $this->source($user, $id);

        return $this->operations->run($source->organization_id, $user->id, $v['operation_id'], 'inventory.intake:'.$id, $v, function () use ($user, $source, $v) {
            $snapshot = $this->snapshot($source, $v);
            if (isset($v['review_hash'])) {
                abort_unless(hash_equals($this->hash($snapshot), $v['review_hash']), 409, 'Produk, konversi atau tinjauan penerimaan berubah. Tinjau kembali barang fisik.');
            }
            $product = ['id' => $snapshot['product_id'], ...collect($snapshot)->only(['sku', 'name', 'condition', 'pack_size'])->all()];
            $sourceId = (string) Str::ulid();
            $batch = $this->stock->credit($source->organization_id, $source->id, $product, $v['batch_code'], $v['expires_on'], (int) $v['quantity_packs'], $user->id, 'warehouse_intake', $sourceId, 'usable', $v['note']);
            Audit::record($user->id, $source->organization_id, 'inventory.intake', $batch['id'], null, ['source_id' => $sourceId, 'quantity_packs' => (int) $v['quantity_packs'], 'note' => $v['note']]);

            return $batch;
        });
    }
}
