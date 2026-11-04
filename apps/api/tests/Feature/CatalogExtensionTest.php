<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogExtensionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private Product $product;

    private InventoryBatch $batch;

    private User $cashier;

    private User $manager;

    private User $other;

    private User $outside;

    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'A1', 'address' => 'Pacitan']);
        $otherOrg = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $this->cashier = $this->user($this->org, 'cashier', [$this->store]);
        $this->other = $this->user($this->org, 'cashier', [$this->store]);
        $this->manager = $this->user($this->org, 'partner_lead');
        $this->warehouse = $this->user($this->org, 'warehouse', [$this->store]);
        $this->outside = $this->user($otherOrg, 'partner_lead');
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'AYAM10', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 20000]);
        $this->batch = $this->seedStock($this->product);
    }

    private function user(Organization $org, string $role, array $stores = []): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        foreach ($stores as $s) {
            DB::table('store_user')->insert(['user_id' => $u->id, 'store_id' => $s->id]);
        }

        return $u;
    }

    private function act(User $u): self
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function seedStock(Product $p): InventoryBatch
    {
        return DB::transaction(function () use ($p) {
            $ledger = app(StockLedger::class);
            $v = $ledger->credit($this->org->id, $this->store->id, $p->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'LOT-'.$p->sku, '2030-01-01', 10, $this->manager->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Receipt fixture');
            $b = InventoryBatch::find($v['id']);
            $ledger->apply($b, $this->manager->id, ['usable' => -40, 'opened' => 10, 'ready' => 30], 'inventory_cook', (string) Str::ulid(), 'Prepared fixture');

            return $b->fresh();
        });
    }

    private function open(?User $actor = null, int $cash = 100000): array
    {
        return $this->act($actor ?? $this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/shifts', ['operation_id' => (string) Str::uuid(), 'device_name' => 'Kasir 1', 'opening_cash' => $cash])->assertCreated()->json('data');
    }

    private function cart(array $shift, int $qty = 4, string $unit = 'piece', string $bucket = 'ready'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift['id'], 'lines' => [['product_id' => $this->product->id, 'unit' => $unit, 'quantity' => $qty, 'allocations' => [['batch_id' => $this->batch->id, 'bucket' => $bucket, 'quantity' => $qty]]]]];
    }

    private function intent(array $shift, int $qty = 4, string $unit = 'piece', string $bucket = 'ready', ?User $actor = null): array
    {
        return $this->act($actor ?? $this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($shift, $qty, $unit, $bucket))->assertCreated()->json('data');
    }

    private function pay(array $intent, int $cash = 20000, ?User $actor = null): array
    {
        return $this->act($actor ?? $this->cashier)->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => $cash])->assertOk()->json('data');
    }

    private function configPath(?string $id = null): string
    {
        return '/api/v1/organizations/'.$this->org->id.'/products/'.($id ?? $this->product->id);
    }

    public function test_effective_prices_wib_boundaries_overlap_and_historical_receipts(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03T01:00:00Z'));
        $path = $this->configPath().'/prices';
        $body = ['operation_id' => (string) Str::uuid(), 'channel' => 'retail', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03', 'price' => 5000, 'reason' => 'Reviewed price period'];
        $r = $this->act($this->manager)->postJson($path, $body)->assertCreated()->json('data');
        $this->postJson($path, $body)->assertCreated();
        $this->postJson($path, [...$body, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->store->id.'/catalog')->assertOk()->assertJsonPath('data.0.retail_price', 5000)->assertJsonMissing(['wholesale_price' => 20000]);
        $shift = $this->open();
        $intent = $this->intent($shift);
        $this->assertSame(20000, $intent['total_amount']);
        $sale = $this->pay($intent, 20000);
        $this->act($this->manager)->postJson($path.'/'.$r['id'].'/cancel', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Period withdrawn'])->assertOk()->assertJsonPath('data.active', 0);
        $this->act($this->cashier)->getJson('/api/v1/sales/'.$sale['id'])->assertOk()->assertJsonPath('data.total_amount', 20000);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/catalog')->assertJsonPath('data.0.retail_price', 3000);
        CarbonImmutable::setTestNow();
    }

    public function test_image_is_raster_only_private_scoped_versioned_and_not_exported_as_storage_path(): void
    {
        Storage::fake('local');
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jmWQAAAAASUVORK5CYII=';
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 0, 'image_base64' => $png];
        $path = $this->configPath().'/image';
        $this->act($this->manager)->postJson($path, $body)->assertOk()->assertJsonPath('data.version', 1);
        $this->postJson($path, $body)->assertOk();
        $this->postJson($path, [...$body, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson($path, [...$body, 'version' => 1, 'operation_id' => (string) Str::uuid(), 'image_base64' => base64_encode('<svg onload="alert(1)"></svg>')])->assertUnprocessable();
        $this->act($this->cashier)->get($path)->assertForbidden();
        $this->get('/api/v1/stores/'.$this->store->id.'/catalog/'.$this->product->id.'/image')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->act($this->outside)->get($path)->assertForbidden();
        $this->get('/api/v1/stores/'.$this->store->id.'/catalog/'.$this->product->id.'/image')->assertForbidden();
    }

    public function test_bundle_two_ayam_one_udang_posts_components_once_and_preserves_recipe_snapshot(): void
    {
        $data = $this->product->only(['organization_id', 'condition', 'pack_size', 'portion_size', 'retail_price', 'wholesale_price']);
        $shrimp = Product::create([...$data, 'sku' => 'UDANG10', 'name' => 'Udang']);
        $shrimpBatch = $this->seedStock($shrimp);
        $bundle = Product::create([...$data, 'sku' => 'PAKET', 'name' => 'Paket 2 ayam 1 udang', 'pack_size' => 1, 'portion_size' => 1, 'retail_price' => 12000]);
        $config = $this->configPath($bundle->id).'/recipe';
        $recipe = ['operation_id' => (string) Str::uuid(), 'version' => 0, 'active' => true, 'components' => [['product_id' => $this->product->id, 'pieces' => 2], ['product_id' => $shrimp->id, 'pieces' => 1]]];
        $this->act($this->manager)->postJson($config, $recipe)->assertOk()->assertJsonPath('data.version', 1);
        $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->store->id.'/catalog/'.$bundle->id.'/bundle')->assertOk()->assertJsonMissingPath('product.wholesale_price');
        $shift = $this->open();
        $body = ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift['id'], 'lines' => [['product_id' => $bundle->id, 'unit' => 'piece', 'quantity' => 1, 'allocations' => [['component_product_id' => $this->product->id, 'batch_id' => $this->batch->id, 'bucket' => 'ready', 'quantity' => 2], ['component_product_id' => $shrimp->id, 'batch_id' => $shrimpBatch->id, 'bucket' => 'ready', 'quantity' => 1]]]]];
        $intent = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $body)->assertCreated()->assertJsonPath('data.total_amount', 12000)->json('data');
        $payment = ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 12000];
        $url = '/api/v1/sales/intents/'.$intent['id'].'/pay';
        $sale = $this->postJson($url, $payment)->assertOk()->assertJsonPath('data.lines.0.recipe_snapshot.version', 1)->json('data');
        $this->postJson($url, $payment)->assertOk();
        $this->assertSame(28, $this->batch->fresh()->ready_pieces);
        $this->assertSame(29, $shrimpBatch->fresh()->ready_pieces);
        $this->assertSame(2, DB::table('stock_entries')->where('source_type', 'pos_sale')->count());
        $this->act($this->manager)->postJson($config, [...$recipe, 'operation_id' => (string) Str::uuid(), 'version' => 1, 'components' => [['product_id' => $this->product->id, 'pieces' => 3]]])->assertOk();
        $this->act($this->cashier)->getJson('/api/v1/sales/'.$sale['id'])->assertOk()->assertJsonCount(2, 'data.lines.0.recipe_snapshot.components');
    }

    public function test_recipe_changes_invalidate_pending_price_hash_and_invalid_components_do_not_touch_stock(): void
    {
        $bundle = Product::create(['organization_id' => $this->org->id, 'sku' => 'B1', 'name' => 'Bundle', 'condition' => 'frozen', 'pack_size' => 1, 'portion_size' => 1, 'retail_price' => 7000, 'wholesale_price' => 5000]);
        $path = $this->configPath($bundle->id).'/recipe';
        $recipe = ['operation_id' => (string) Str::uuid(), 'version' => 0, 'active' => true, 'components' => [['product_id' => $this->product->id, 'pieces' => 2]]];
        $this->act($this->cashier)->postJson($path, $recipe)->assertForbidden();
        $this->act($this->manager)->postJson($path, $recipe)->assertOk();
        $this->postJson($path, [...$recipe, 'operation_id' => (string) Str::uuid(), 'version' => 1, 'components' => [['product_id' => $bundle->id, 'pieces' => 1]]])->assertUnprocessable();
        $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->store->id.'/catalog/'.$bundle->id.'/bundle')->assertOk()->assertJsonMissingPath('product.wholesale_price');
        $shift = $this->open();
        $body = ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift['id'], 'lines' => [['product_id' => $bundle->id, 'unit' => 'piece', 'quantity' => 1, 'allocations' => [['component_product_id' => $this->product->id, 'batch_id' => $this->batch->id, 'bucket' => 'ready', 'quantity' => 2]]]]];
        $intent = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $body)->assertCreated()->json('data');
        $this->act($this->manager)->postJson($path, [...$recipe, 'operation_id' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 7000])->assertConflict();
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
    }

    public function test_partner_price_priority_is_scoped_and_other_partner_does_not_see_special_price(): void
    {
        $center = Organization::create(['name' => 'Center', 'type' => 'center']);
        $owner = $this->user($center, 'owner');
        $source = Product::create(['organization_id' => $center->id, 'sku' => 'SOURCE10', 'name' => 'Source', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 20000]);
        $url = '/api/v1/organizations/'.$center->id.'/products/'.$source->id.'/prices';
        $day = now('Asia/Jakarta')->format('Y-m-d');
        $common = ['operation_id' => (string) Str::uuid(), 'channel' => 'wholesale', 'starts_on' => $day, 'ends_on' => $day, 'price' => 18000, 'reason' => 'General rate'];
        $this->act($owner)->postJson($url, $common)->assertCreated();
        $this->postJson($url, [...$common, 'operation_id' => (string) Str::uuid(), 'partner_id' => $this->org->id, 'price' => 12000])->assertCreated();
        $this->act($this->manager)->getJson('/api/v1/stores/'.$this->store->id.'/supply-catalog')->assertOk()->assertJsonPath('data.0.wholesale_price', 12000);
        $otherOrg = Organization::where('name', 'Mitra B')->firstOrFail();
        $otherStore = Store::create(['organization_id' => $otherOrg->id, 'name' => 'B1']);
        $this->act($this->outside)->getJson('/api/v1/stores/'.$otherStore->id.'/supply-catalog')->assertOk()->assertJsonPath('data.0.wholesale_price', 18000)->assertJsonMissing(['partner_id' => $this->org->id]);
        $this->getJson('/api/v1/organizations/'.$center->id.'/products/'.$source->id.'/configuration')->assertForbidden();
        $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->store->id.'/supply-catalog')->assertForbidden();
    }
}
