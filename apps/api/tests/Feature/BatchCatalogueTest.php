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

class BatchCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private Product $product;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Mitra', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'A1']);
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'OLD8', 'name' => 'Historis', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 18000]);
        $this->lead = $this->actor($this->org, 'partner_lead');
        Sanctum::actingAs($this->lead);
    }

    private function actor(Organization $org, string $role, bool $assigned = false): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        if ($assigned) {
            DB::table('store_user')->insert(['user_id' => $u->id, 'store_id' => $this->store->id]);
        }

        return $u;
    }

    private function credit(string $code, string $date, string $bucket = 'usable', ?Store $store = null): InventoryBatch
    {
        return DB::transaction(function () use ($code, $date, $bucket, $store) {
            $r = app(StockLedger::class)->credit($this->org->id, ($store ?? $this->store)->id, $this->product->only(['id', 'sku', 'name', 'condition', 'pack_size']), $code, $date, 1, $this->lead->id, 'shipment_receipt', (string) Str::ulid(), $bucket, 'Read fixture');

            return InventoryBatch::findOrFail($r['id']);
        });
    }

    private function path(array $query = []): string
    {
        return '/api/v1/stores/'.$this->store->id.'/inventory/batches?'.http_build_query($query);
    }

    public function test_inclusive_expiry_filters_balance_or_is_scoped_and_snapshots_are_read_only_price_free(): void
    {
        $this->credit('LOT-EARLY', '2026-10-31');
        $quarantine = $this->credit('LOT-EDGE', '2026-11-01', 'quarantined');
        $this->credit('LOT-LATE', '2026-11-02');
        $waste = $this->credit('LOT-WASTE', '2026-11-01');
        DB::transaction(fn () => app(StockLedger::class)->apply($waste, $this->lead->id, ['usable' => -8, 'waste' => 8], 'stock_waste', (string) Str::ulid(), 'Written off fixture'));
        $other = Store::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $this->credit('LOT-FOREIGN', '2026-11-01', 'quarantined', $other);
        $this->product->update(['sku' => 'NEW10', 'name' => 'Updated', 'pack_size' => 10, 'active' => false]);
        $before = DB::table('inventory_batches')->orderBy('id')->get()->toJson();
        $count = DB::table('stock_entries')->count();
        $r = $this->getJson($this->path(['expires_from' => '2026-11-01', 'expires_to' => '2026-11-01', 'q' => 'LOT-', 'with_balance' => 1]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $quarantine->id)->assertJsonPath('data.0.sku', 'OLD8')->assertJsonPath('data.0.pack_size', 8)->assertJsonPath('data.0.available_pieces', 0)->assertJsonPath('data.0.quarantined_pieces', 8)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertArrayNotHasKey('wholesale_price', $r->json('data.0'));
        $this->assertArrayNotHasKey('retail_price', $r->json('data.0'));
        $this->getJson($this->path(['expires_to' => '2026-11-01']))->assertJsonCount(3, 'data');
        $this->getJson($this->path(['q' => 'LOT-WASTE', 'with_balance' => 0]))->assertJsonCount(1, 'data');
        $this->assertSame($before, DB::table('inventory_batches')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('stock_entries', $count);
        $this->assertDatabaseCount('audit_entries', 0);
        $this->assertDatabaseCount('operation_receipts', 0);
    }

    public function test_existing_batch_permissions_include_assigned_cashier_but_deny_unassigned_and_other_tenants(): void
    {
        $this->credit('LOT', '2026-11-01');
        foreach (['cashier', 'warehouse', 'store_manager'] as $role) {
            Sanctum::actingAs($this->actor($this->org, $role, true));
            $this->getJson($this->path(['expires_to' => '2026-11-01']))->assertOk()->assertJsonCount(1, 'data');
        }
        $other = Organization::create(['name' => 'B', 'type' => 'partner']);
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        foreach ([$this->actor($this->org, 'cashier'), $this->actor($other, 'partner_lead'), $this->actor($center, 'owner')] as $u) {
            Sanctum::actingAs($u);
            $this->getJson($this->path())->assertForbidden();
        }
        Sanctum::actingAs($this->lead);
        $this->store->update(['active' => false]);
        $this->getJson($this->path())->assertOk()->assertJsonPath('store.active', false);
        $this->org->update(['active' => false]);
        $this->getJson($this->path())->assertForbidden();
    }

    public function test_legacy_order_pagination_one_hundred_plus_one_and_invalid_filters(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->credit('B'.$i, $i % 2 ? '2026-11-02' : '2026-11-01');
        }
        $a = $this->getJson($this->path())->assertJsonCount(100, 'data')->assertJsonPath('last_page', 2)->json('data');
        $b = $this->getJson($this->path(['page' => 2]))->assertJsonCount(1, 'data')->json('data');
        $rows = [...$a, ...$b];
        $this->assertCount(101, array_unique(array_column($rows, 'id')));
        $dates = array_column($rows, 'expires_on');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
        foreach ([['page' => 0], ['q' => str_repeat('a', 101)], ['q' => ['bad']], ['expires_to' => '2026-02-30'], ['expires_from' => '2026-11-02', 'expires_to' => '2026-11-01'], ['with_balance' => 'bad']] as $query) {
            $this->getJson($this->path($query))->assertUnprocessable();
        }
    }
}
