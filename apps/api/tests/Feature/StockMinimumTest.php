<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockMinimumTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private Product $product;

    private User $manager;

    private User $cashier;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'Toko A']);
        $this->manager = $this->user($this->org, 'partner_lead');
        $this->cashier = $this->user($this->org, 'cashier', $this->store);
        $this->product = $this->product('AYAM');
        $this->url = '/api/v1/stores/'.$this->store->id.'/inventory/minimums';
        Sanctum::actingAs($this->manager);
    }

    private function user(Organization $org, string $role, ?Store $store = null): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        if ($store) {
            DB::table('store_user')->insert(['store_id' => $store->id, 'user_id' => $u->id]);
        }

        return $u;
    }

    private function product(string $sku, ?Organization $org = null): Product
    {
        return Product::create(['organization_id' => ($org ?? $this->org)->id, 'sku' => $sku, 'name' => 'Dimsum '.$sku, 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 21000]);
    }

    private function payload(?int $amount = 20, int $version = 0): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'minimum_pieces' => $amount, 'reason' => 'Cadangan untuk kebutuhan toko'];
    }

    private function seedStock(Store $store, string $code, int $packs): InventoryBatch
    {
        return DB::transaction(function () use ($store, $code, $packs) {
            $data = app(StockLedger::class)->credit($this->org->id, $store->id, $this->product->only(['id', 'sku', 'name', 'condition', 'pack_size']), $code, '2030-01-01', $packs, $this->manager->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Fixture');

            return InventoryBatch::findOrFail($data['id']);
        });
    }

    public function test_unconfigured_and_empty_sku_are_not_silently_given_a_threshold(): void
    {
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.available_pieces', 0)->assertJsonPath('data.0.minimum_pieces', null)->assertJsonPath('data.0.version', 0)->assertJsonPath('data.0.is_low', false)->assertJsonPath('can_manage', true);
        $this->getJson($this->url.'?low_only=1')->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('inventory_minimums', 0);
    }

    public function test_zero_stock_with_enabled_threshold_warns_without_creating_orders_or_movements(): void
    {
        $this->postJson($this->url.'/'.$this->product->id, $this->payload())->assertOk()->assertJsonPath('data.version', 1);
        $r = $this->getJson($this->url.'?low_only=1')->assertOk()->assertJsonPath('data.0.shortfall_pieces', 20)->json();
        $this->assertArrayHasKey('observed_at', $r);
        $this->assertArrayNotHasKey('wholesale_price', $r['data'][0]);
        $this->assertArrayNotHasKey('retail_price', $r['data'][0]);
        $this->assertDatabaseCount('supply_orders', 0);
        $this->assertDatabaseCount('stock_entries', 0);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_sums_all_local_batches_but_excludes_other_store_quarantine_and_waste(): void
    {
        $a = $this->seedStock($this->store, 'A', 3);
        $this->seedStock($this->store, 'B', 1);
        $other = Store::create(['organization_id' => $this->org->id, 'name' => 'Toko lain']);
        $this->seedStock($other, 'C', 100);
        DB::transaction(fn () => app(StockLedger::class)->apply($a, $this->manager->id, ['usable' => -20, 'opened' => 5, 'ready' => 3, 'quarantined' => 10, 'waste' => 2], 'inventory_cook', (string) Str::ulid(), 'Fixture'));
        $this->postJson($this->url.'/'.$this->product->id, $this->payload(30))->assertOk();
        $this->getJson($this->url.'?low_only=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.usable_pieces', 20)->assertJsonPath('data.0.opened_pieces', 5)->assertJsonPath('data.0.ready_pieces', 3)->assertJsonPath('data.0.available_pieces', 28)->assertJsonPath('data.0.shortfall_pieces', 2);
    }

    public function test_equal_threshold_is_not_low_and_live_debit_and_restock_change_warning(): void
    {
        $b = $this->seedStock($this->store, 'A', 2);
        $this->postJson($this->url.'/'.$this->product->id, $this->payload(20))->assertOk();
        $this->getJson($this->url.'?low_only=1')->assertJsonCount(0, 'data');
        DB::transaction(fn () => app(StockLedger::class)->apply($b, $this->manager->id, ['usable' => -10], 'shipment_dispatch', (string) Str::ulid(), 'Fixture debit'));
        $this->getJson($this->url.'?low_only=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.available_pieces', 10);
        $this->seedStock($this->store, 'B', 1);
        $this->getJson($this->url.'?low_only=1')->assertJsonCount(0, 'data');
    }

    public function test_replay_changes_once_and_stale_or_conflicting_payload_does_not_overwrite(): void
    {
        $v = $this->payload();
        $path = $this->url.'/'.$this->product->id;
        $first = $this->postJson($path, $v)->assertOk()->json('data');
        $this->postJson($path, $v)->assertOk()->assertExactJson(['data' => $first]);
        $this->postJson($path, [...$v, 'minimum_pieces' => 50])->assertConflict();
        $this->postJson($path, $this->payload(50))->assertConflict();
        $this->assertDatabaseCount('inventory_minimums', 1);
        $this->assertDatabaseCount('audit_entries', 1);
        $this->assertDatabaseHas('inventory_minimums', ['minimum_pieces' => 20, 'version' => 1]);
        $this->postJson($path, $this->payload(30, 1))->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_null_disables_without_deleting_history_and_zero_is_an_enabled_threshold(): void
    {
        $path = $this->url.'/'.$this->product->id;
        $this->postJson($path, $this->payload())->assertOk();
        $this->postJson($path, $this->payload(null, 1))->assertOk();
        $this->getJson($this->url)->assertJsonPath('data.0.minimum_pieces', null)->assertJsonPath('data.0.version', 2)->assertJsonPath('data.0.is_low', false);
        $this->postJson($path, $this->payload(0, 2))->assertOk();
        $this->getJson($this->url)->assertJsonPath('data.0.minimum_pieces', 0)->assertJsonPath('data.0.is_low', false);
        $this->assertDatabaseCount('audit_entries', 3);
    }

    public function test_cashier_and_warehouse_read_but_cannot_configure(): void
    {
        foreach ([$this->cashier, $this->user($this->org, 'warehouse', $this->store)] as $u) {
            Sanctum::actingAs($u);
            $this->getJson($this->url)->assertOk()->assertJsonPath('can_manage', false);
            $this->postJson($this->url.'/'.$this->product->id, $this->payload())->assertForbidden();
        }
    }

    public function test_cross_tenant_unassigned_and_central_onboarding_privileges_are_denied(): void
    {
        $foreign = Organization::create(['name' => 'Lain', 'type' => 'partner']);
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        foreach ([$this->user($foreign, 'partner_lead'), $this->user($center, 'owner'), $this->user($this->org, 'store_manager')] as $u) {
            Sanctum::actingAs($u);
            $this->getJson($this->url)->assertForbidden();
            $this->postJson($this->url.'/'.$this->product->id, $this->payload())->assertForbidden();
        }
        Sanctum::actingAs($this->manager);
        $p = $this->product('FOREIGN', $foreign);
        $this->postJson($this->url.'/'.$p->id, $this->payload())->assertNotFound();
        $this->assertDatabaseCount('inventory_minimums', 0);
    }

    public function test_assigned_store_manager_configures_only_own_store(): void
    {
        Sanctum::actingAs($this->user($this->org, 'store_manager', $this->store));
        $this->postJson($this->url.'/'.$this->product->id, $this->payload())->assertOk();
        $other = Store::create(['organization_id' => $this->org->id, 'name' => 'Lain']);
        $this->postJson('/api/v1/stores/'.$other->id.'/inventory/minimums/'.$this->product->id, $this->payload())->assertForbidden();
    }

    public function test_inactive_products_are_hidden_and_inactive_store_cannot_be_used(): void
    {
        $this->postJson($this->url.'/'.$this->product->id, $this->payload())->assertOk();
        $this->product->update(['active' => false]);
        $this->getJson($this->url)->assertJsonCount(0, 'data');
        $this->postJson($this->url.'/'.$this->product->id, $this->payload(50, 1))->assertNotFound();
        $this->store->update(['active' => false]);
        $this->getJson($this->url)->assertConflict();
        $this->postJson($this->url.'/'.$this->product->id, $this->payload())->assertConflict();
    }

    public function test_rejects_invalid_threshold_version_reason_and_query(): void
    {
        foreach ([['minimum_pieces' => -1], ['minimum_pieces' => 1.5], ['minimum_pieces' => 1000000001], ['version' => -1], ['reason' => ' ']] as $bad) {
            $this->postJson($this->url.'/'.$this->product->id, [...$this->payload(), ...$bad])->assertUnprocessable();
        }
        $v = $this->payload();
        unset($v['minimum_pieces']);
        $this->postJson($this->url.'/'.$this->product->id, $v)->assertUnprocessable();
        $this->getJson($this->url.'?low_only=invalid')->assertUnprocessable();
        $this->getJson($this->url.'?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('inventory_minimums', 0);
    }

    public function test_exact_detail_is_read_only_price_free_and_ignores_list_filters_or_page(): void
    {
        $this->seedStock($this->store, 'A', 2);
        $path = $this->url.'/'.$this->product->id;
        $before = DB::table('stock_entries')->count();
        $response = $this->getJson($path.'?page=2&low_only=1&q=no-match')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('store.id', $this->store->id)->assertJsonPath('can_manage', true)
            ->assertJsonPath('data.product_id', $this->product->id)->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.minimum_pieces', null)->assertJsonPath('data.available_pieces', 20);
        $this->assertArrayNotHasKey('wholesale_price', $response->json('data'));
        $this->assertArrayNotHasKey('retail_price', $response->json('data'));
        $this->product->update(['sku' => 'RENAMED']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.sku', 'RENAMED');
        $this->assertDatabaseCount('inventory_minimums', 0);
        $this->assertDatabaseCount('operation_receipts', 0);
        $this->assertDatabaseCount('audit_entries', 0);
        $this->assertDatabaseCount('stock_entries', $before);
    }

    public function test_detail_rejects_tenant_and_unassigned_access_but_read_only_roles_can_read(): void
    {
        $path = $this->url.'/'.$this->product->id;
        foreach ([$this->cashier, $this->user($this->org, 'warehouse', $this->store)] as $u) {
            Sanctum::actingAs($u);
            $this->getJson($path)->assertOk()->assertJsonPath('can_manage', false);
        }
        $foreign = Organization::create(['name' => 'Lain', 'type' => 'partner']);
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        foreach ([$this->user($foreign, 'partner_lead'), $this->user($center, 'owner'), $this->user($this->org, 'store_manager')] as $u) {
            Sanctum::actingAs($u);
            $this->getJson($path)->assertForbidden();
        }
        Sanctum::actingAs($this->user($this->org, 'store_manager', $this->store));
        $this->getJson($path)->assertOk()->assertJsonPath('can_manage', true);
        $this->getJson($this->url.'/'.$this->product('FOREIGN', $foreign)->id)->assertNotFound();
        $this->product->update(['active' => false]);
        $this->getJson($path)->assertNotFound();
        $this->store->update(['active' => false]);
        $this->getJson($path)->assertConflict();
    }

    public function test_detail_after_replay_reads_latest_configuration_instead_of_reverting_it(): void
    {
        $path = $this->url.'/'.$this->product->id;
        $first = $this->payload(0);
        $this->postJson($path, $first)->assertOk()->assertJsonPath('data.version', 1);
        $this->postJson($path, $this->payload(null, 1))->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($path, $first)->assertOk()->assertJsonPath('data.version', 1);
        $this->getJson($path)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.minimum_pieces', null)->assertJsonPath('data.is_low', false);
        $this->assertDatabaseCount('inventory_minimums', 1);
        $this->assertDatabaseCount('audit_entries', 2);
        $this->assertDatabaseCount('stock_entries', 0);
        $this->assertDatabaseCount('supply_orders', 0);
    }

    public function test_filters_and_paginates_active_skus_without_losing_unstocked_products(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->product('SKU'.sprintf('%03d', $i));
        }
        $this->getJson($this->url)->assertJsonCount(50, 'data')->assertJsonPath('total', 52)->assertJsonPath('last_page', 2);
        $this->getJson($this->url.'?page=2')->assertJsonCount(2, 'data');
        $this->getJson($this->url.'?q=AYAM')->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_id', $this->product->id);
    }
}
