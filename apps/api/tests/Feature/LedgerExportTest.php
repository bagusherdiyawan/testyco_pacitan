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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LedgerExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private Product $product;

    private User $lead;

    private InventoryBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'A', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'A1']);
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'OLD8', 'name' => 'Historical', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 18000]);
        $this->lead = $this->actor($this->org, 'partner_lead');
        $this->batch = $this->batch($this->store);
        Sanctum::actingAs($this->lead);
    }

    private function actor(Organization $org, string $role, bool $assigned = false): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        if ($assigned) {
            DB::table('store_user')->insert(['store_id' => $this->store->id, 'user_id' => $u->id]);
        }

        return $u;
    }

    private function batch(Store $s): InventoryBatch
    {
        return InventoryBatch::create(['organization_id' => $s->organization_id, 'store_id' => $s->id, 'product_id' => $this->product->id, 'sku' => 'OLD8', 'name' => 'Historical', 'condition' => 'frozen', 'pack_size' => 8, 'batch_code' => (string) Str::ulid(), 'expires_on' => '2030-01-01']);
    }

    private function row(string $time = '2026-10-29 17:00:00', ?InventoryBatch $batch = null): array
    {
        return ['id' => (string) Str::ulid(), 'organization_id' => $this->org->id, 'store_id' => $this->store->id, 'inventory_batch_id' => ($batch ?? $this->batch)->id, 'actor_id' => $this->lead->id, 'source_type' => 'inventory_open', 'source_id' => (string) Str::ulid(), 'bucket' => 'usable', 'quantity_pieces' => -8, 'note' => 'Physical opening', 'created_at' => $time];
    }

    private function url(array $query = []): string
    {
        return '/api/v1/stores/'.$this->store->id.'/inventory/ledger/export?'.http_build_query(['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-10-31T00:00:00+07:00', ...$query]);
    }

    public function test_staff_and_assignment_scope_no_central_onboarding_or_cashier_bypass(): void
    {
        DB::table('stock_entries')->insert($this->row());
        foreach (['cashier', 'warehouse', 'store_manager'] as $role) {
            Sanctum::actingAs($this->actor($this->org, $role));
            $this->getJson($this->url())->assertForbidden();
        }
        Sanctum::actingAs($this->actor($this->org, 'cashier', true));
        $this->getJson($this->url())->assertForbidden();
        foreach (['warehouse', 'store_manager'] as $role) {
            Sanctum::actingAs($this->actor($this->org, $role, true));
            $this->getJson($this->url())->assertOk()->assertJsonPath('row_count', 1);
        }
        $other = Organization::create(['name' => 'Other', 'type' => 'partner']);
        Sanctum::actingAs($this->actor($other, 'partner_lead'));
        $this->getJson($this->url())->assertForbidden();
        $center = Organization::create(['name' => 'Center', 'type' => 'center']);
        Sanctum::actingAs($this->actor($center, 'owner'));
        $this->getJson($this->url())->assertForbidden();
        Sanctum::actingAs($this->lead);
        $this->store->update(['active' => false]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('store.active', false);
        $this->org->update(['active' => false]);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_actual_signed_ledger_snapshots_are_exported_without_stock_or_audit_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29T17:00:00Z'));
        DB::transaction(function () {
            $this->batch = app(StockLedger::class)->apply($this->batch, $this->lead->id, ['usable' => 16], 'warehouse_intake', (string) Str::ulid());
            app(StockLedger::class)->apply($this->batch, $this->lead->id, ['usable' => -8, 'opened' => 8], 'inventory_open', 'PHYSICAL-REF', 'Opened pack');
        });
        $this->product->update(['sku' => 'CURRENT10', 'name' => 'Changed', 'pack_size' => 10, 'active' => false]);
        $before = $this->batch->fresh()->toArray();
        $ledger = DB::table('stock_entries')->orderBy('id')->get()->toJson();
        $rows = $this->getJson($this->url(['source_type' => 'inventory_open', 'q' => 'OLD8']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('row_count', 2)->assertJsonMissingPath('data.0.retail_price')->assertJsonMissingPath('data.0.wholesale_price')->json('data');
        $this->assertEqualsCanonicalizing([-8, 8], array_column($rows, 'quantity_pieces'));
        $this->assertSame('OLD8', $rows[0]['sku']);
        $this->assertSame(8, $rows[0]['pack_size']);
        $this->assertSame('2026-10-29T17:00:00+00:00', $rows[0]['occurred_at']);
        $this->assertSame('PHYSICAL-REF', $rows[0]['source_id']);
        $this->assertSame($before, $this->batch->fresh()->toArray());
        $this->assertSame($ledger, DB::table('stock_entries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_entries', 0);
        $this->assertDatabaseCount('operation_receipts', 0);
    }

    public function test_exact_batch_time_filters_and_search_or_are_scoped_across_all_pages(): void
    {
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = $this->row();
        }
        $elsewhere = Store::create(['organization_id' => $this->org->id, 'name' => 'Other store']);
        $foreign = $this->batch($elsewhere);
        DB::table('stock_entries')->insert([...$rows, $this->row('2026-10-29 16:59:59'), $this->row('2026-10-30 17:00:00'), $this->row(batch: $foreign)]);
        $query = ['batch_id' => $this->batch->id, 'source_type' => 'inventory_open', 'bucket' => 'usable', 'q' => 'Historical', 'page' => 999, 'store_id' => $elsewhere->id];
        $r = $this->getJson($this->url($query))->assertOk()->assertJsonCount(101, 'data')->json();
        $ids = array_column($rows, 'id');
        rsort($ids);
        $this->assertSame($ids, array_column($r['data'], 'id'));
        $this->getJson($this->url(['batch_id' => $foreign->id]))->assertNotFound();
        $this->getJson($this->url(['q' => 'Historical']))->assertJsonPath('row_count', 101);
    }

    public function test_row_and_byte_limits_reject_without_truncation_and_filter_can_narrow_result(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $rows = [];
            for ($j = 0; $j < 100; $j++) {
                $rows[] = $this->row();
            }
            DB::table('stock_entries')->insert($rows);
        }
        $this->getJson($this->url())->assertOk()->assertJsonPath('row_count', 10000);
        DB::table('stock_entries')->insert($this->row());
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('export')->assertJsonMissingPath('data');
        $this->getJson($this->url(['q' => 'NO-MATCH']))->assertOk()->assertJsonPath('row_count', 0);
        DB::table('stock_entries')->delete();
        DB::table('stock_entries')->insert([...$this->row(), 'note' => str_repeat('x', 8 * 1024 * 1024)]);
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('export')->assertJsonMissingPath('data');
        $this->assertSame(0, $this->batch->fresh()->stock_revision);
    }

    public function test_period_required_and_invalid_batch_source_bucket_and_range_rejected(): void
    {
        $this->getJson('/api/v1/stores/'.$this->store->id.'/inventory/ledger/export')->assertUnprocessable();
        foreach ([['from' => '2026-10-30'], ['to' => '2026-10-29T00:00:00Z'], ['from' => '2026-09-01T00:00:00Z'], ['batch_id' => 'bad'], ['bucket' => 'bogus'], ['source_type' => 'bogus'], ['q' => ['array']]] as $q) {
            $this->getJson($this->url($q))->assertUnprocessable();
        }
        $this->assertDatabaseCount('stock_entries', 0);
    }
}
