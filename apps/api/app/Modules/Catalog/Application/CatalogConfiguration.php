<?php

namespace App\Modules\Catalog\Application;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CatalogConfiguration
{
    public function __construct(private Access $access, private Operations $operations) {}

    private function product(User $user, string $org, string $id): Product
    {
        abort_unless($this->access->role($user, $org)?->manages(), 403);

        return Product::where('organization_id', $org)->findOrFail($id);
    }

    private function recipe(string $id): ?array
    {
        $row = DB::table('product_recipes')->where('product_id', $id)->first();
        if (! $row) {
            return null;
        }$r = (array) $row;
        $r['version'] = (int) $r['version'];
        $r['active'] = (bool) $r['active'];
        $r['portion_equivalent'] = (int) ($r['portion_equivalent'] ?? 0);
        $r['components'] = json_decode($r['components'], true);

        return $r;
    }

    public function price(User $user, string $organization, string $product, array $v): array
    {
        $this->product($user, $organization, $product);

        return $this->operations->run($organization, $user->id, $v['operation_id'], 'catalog.price:'.$product, $v, function () use ($user, $organization, $product, $v) {
            $p = Product::where('organization_id', $organization)->lockForUpdate()->findOrFail($product);
            if ($v['partner_id'] ?? null) {
                abort_unless($v['channel'] === 'wholesale' && DB::table('organizations')->where('id', $organization)->where('type', 'center')->exists() && DB::table('organizations')->where('id', $v['partner_id'])->where('type', 'partner')->exists(), 422, 'Harga khusus hanya pusat untuk mitra.');
            }
            $q = DB::table('product_price_periods')->where('product_id', $product)->where('channel', $v['channel'])->where('active', true)->where('starts_on', '<=', $v['ends_on'])->where('ends_on', '>=', $v['starts_on']);
            isset($v['partner_id']) ? $q->where('partner_id', $v['partner_id']) : $q->whereNull('partner_id');
            abort_if($q->exists(), 409, 'Periode harga bertumpang tindih pada cakupan sama.');
            $id = (string) Str::ulid();
            DB::table('product_price_periods')->insert(['id' => $id, 'organization_id' => $organization, 'product_id' => $product, 'partner_id' => $v['partner_id'] ?? null, 'channel' => $v['channel'], 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'], 'price' => (int) $v['price'], 'reason' => $v['reason'], 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $row = (array) DB::table('product_price_periods')->find($id);
            Audit::record($user->id, $organization, 'product.price_scheduled', $product, null, $row);

            return $row;
        });
    }

    public function cancelPrice(User $user, string $organization, string $product, string $price, array $v): array
    {
        $this->product($user, $organization, $product);

        return $this->operations->run($organization, $user->id, $v['operation_id'], 'catalog.price_cancel:'.$price, $v, function () use ($user, $organization, $product, $price, $v) {
            Product::whereKey($product)->lockForUpdate()->firstOrFail();
            $q = DB::table('product_price_periods')->where('organization_id', $organization)->where('product_id', $product)->where('id', $price);
            $row = (clone $q)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->active && (int) $row->version === (int) $v['version'], 409);
            $q->update(['active' => false, 'version' => $row->version + 1, 'updated_at' => now()]);
            Audit::record($user->id, $organization, 'product.price_cancelled', $product, (array) $row, ['price_id' => $price, 'reason' => $v['reason']]);

            return (array) $q->first();
        });
    }

    public function saveRecipe(User $user, string $organization, string $product, array $v): array
    {
        $this->product($user, $organization, $product);

        return $this->operations->run($organization, $user->id, $v['operation_id'], 'catalog.recipe:'.$product, $v, function () use ($user, $organization, $product, $v) {
            $p = Product::whereKey($product)->lockForUpdate()->firstOrFail();
            abort_unless($p->pack_size === 1 && $p->portion_size === 1, 422, 'SKU paket memakai satuan satu paket (isi/porsi1).');
            $old = $this->recipe($product);
            abort_unless(($old['version'] ?? 0) === (int) $v['version'], 409, 'Versi resep berubah.');
            abort_if(DB::table('product_recipes')->where('active', true)->where('product_id', '!=', $product)->get()->contains(fn ($x) => collect(json_decode($x->components, true))->contains('product_id', $product)), 409, 'Paket yang menjadi komponen tidak boleh diubah menjadi resep.');
            $components = [];
            foreach ($v['components'] as $c) {
                abort_if($c['product_id'] === $product, 422, 'Resep tidak boleh memuat dirinya sendiri.');
                $item = Product::where('organization_id', $organization)->where('active', true)->lockForUpdate()->findOrFail($c['product_id']);
                abort_if(DB::table('product_recipes')->where('product_id', $item->id)->where('active', true)->exists(), 422, 'Paket bersarang belum diperbolehkan.');
                $components[] = ['product_id' => $item->id, 'sku' => $item->sku, 'name' => $item->name, 'condition' => $item->condition, 'pieces' => (int) $c['pieces']];
            }
            DB::table('product_recipes')->updateOrInsert(['product_id' => $product], ['id' => $old['id'] ?? (string) Str::ulid(), 'organization_id' => $organization, 'version' => ($old['version'] ?? 0) + 1, 'active' => $v['active'], 'portion_equivalent' => (int) ($v['portion_equivalent'] ?? 0), 'components' => json_encode($components), 'created_at' => $old['created_at'] ?? now(), 'updated_at' => now()]);
            $new = $this->recipe($product);
            Audit::record($user->id, $organization, 'product.recipe_updated', $product, $old, $new);

            return $new;
        });
    }

    public function image(User $user, string $organization, string $product, array $v): array
    {
        $this->product($user, $organization, $product);
        $bytes = base64_decode($v['image_base64'], true);
        abort_unless(is_string($bytes) && strlen($bytes) <= 5 * 1024 * 1024, 422, 'Gambar maksimal5MiB.');
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) && $info[0] <= 4096 && $info[1] <= 4096, 422, 'Gunakan PNG/JPEG/WebP raster maksimal4096px.');
        $hash = hash('sha256', $bytes);
        $path = 'catalog/'.$organization.'/'.$product.'/'.$hash;

        return $this->operations->run($organization, $user->id, $v['operation_id'], 'catalog.image:'.$product, $v, function () use ($user, $organization, $product, $v, $bytes, $info, $hash, $path) {
            Product::whereKey($product)->lockForUpdate()->firstOrFail();
            $old = DB::table('product_images')->where('product_id', $product)->first();
            abort_unless((int) ($old->version ?? 0) === (int) $v['version'], 409, 'Versi gambar berubah.');
            abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Penyimpanan gambar gagal.');
            DB::table('product_images')->updateOrInsert(['product_id' => $product], ['path' => $path, 'mime' => $info['mime'], 'sha256' => $hash, 'version' => ($old->version ?? 0) + 1, 'created_at' => $old->created_at ?? now(), 'updated_at' => now()]);
            $result = ['product_id' => $product, 'version' => ($old->version ?? 0) + 1, 'sha256' => $hash, 'mime' => $info['mime']];
            Audit::record($user->id, $organization, 'product.image_updated', $product, null, $result);

            return $result;
        });
    }

    public function show(User $user, string $organization, string $product): array
    {
        $p = $this->product($user, $organization, $product);

        return ['product' => $p, 'recipe' => $this->recipe($product), 'image' => DB::table('product_images')->where('product_id', $product)->first(['version', 'mime', 'sha256']), 'prices' => DB::table('product_price_periods')->where('product_id', $product)->orderBy('starts_on')->get(), 'components' => Product::where('organization_id', $organization)->where('active', true)->where('id', '!=', $product)->get(['id', 'sku', 'name', 'condition', 'pack_size']), 'partners' => DB::table('organizations')->where('id', $organization)->where('type', 'center')->exists() ? DB::table('organizations')->where('type', 'partner')->get(['id', 'name']) : []];
    }

    public function bundle(User $user, string $store, string $product): array
    {
        $s = $this->access->operatingStore($user, $store);
        $p = Product::where('organization_id', $s->organization_id)->where('active', true)->findOrFail($product);
        $recipe = $this->recipe($product);
        abort_unless($recipe && $recipe['active'], 404);

        return ['product' => [...$p->only(['id', 'sku', 'name', 'condition', 'pack_size', 'portion_size']), 'retail_price' => EffectivePrice::for($p, 'retail')], 'recipe' => $recipe, 'batches' => InventoryBatch::where('store_id', $store)->whereIn('product_id', array_column($recipe['components'], 'product_id'))->where(fn ($q) => $q->where('opened_pieces', '>', 0)->orWhere('ready_pieces', '>', 0))->get()];
    }

    private function file(string $product): array
    {
        $image = DB::table('product_images')->where('product_id', $product)->first();
        abort_unless($image && Storage::disk('local')->exists($image->path), 404);

        return ['bytes' => Storage::disk('local')->get($image->path), 'mime' => $image->mime];
    }

    public function managedImage(User $user, string $organization, string $product): array
    {
        $this->product($user, $organization, $product);

        return $this->file($product);
    }

    public function storeImage(User $user, string $store, string $product): array
    {
        $s = $this->access->operatingStore($user, $store);
        Product::where('organization_id', $s->organization_id)->findOrFail($product);

        return $this->file($product);
    }
}
