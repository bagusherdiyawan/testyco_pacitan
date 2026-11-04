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

class StockLedgerHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private Product $product;

    private User $lead;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $this->store = Store::create(['name' => 'A1', 'organization_id' => $this->org->id]);
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'OLD8', 'name' => 'Nama historis', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 18000]);
        $this->lead = $this->user($this->org, 'partner_lead');
        $this->url = '/api/v1/stores/'.$this->store->id.'/inventory/ledger';
        Sanctum::actingAs($this->lead);
    }

    private function user(Organization $org, string $role, bool $assigned = false): User
    {
        $user = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);
        if ($assigned) {
            DB::table('store_user')->insert(['store_id' => $this->store->id, 'user_id' => $user->id]);
        }

        return $user;
    }

    private function credit(?Store $store = null, string $code = 'LOT-A'): InventoryBatch
    {
        return DB::transaction(function () use ($store, $code) {
            $r = app(StockLedger::class)->credit($this->org->id, ($store ?? $this->store)->id, $this->product->only(['id', 'sku', 'name', 'condition', 'pack_size']), $code, '2030-01-01', 2, $this->lead->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Pemeriksaan fisik');

            return InventoryBatch::findOrFail($r['id']);
        });
    }

    public function test_history_is_read_only_price_free_and_preserves_batch_snapshots_and_signed_deltas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29T17:00:00Z'));
        $batch = $this->credit();
        DB::transaction(fn () => app(StockLedger::class)->apply($batch, $this->lead->id, ['usable' => -8, 'opened' => 8], 'inventory_open', (string) Str::ulid(), 'Buka fisik'));
        $this->product->update(['sku' => 'NEW10', 'name' => 'Nama baru', 'pack_size' => 10, 'active' => false]);
        $before = $batch->fresh()->toArray();
        $r = $this->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('store.id', $this->store->id)->assertJsonCount(3, 'data');
        $rows = $r->json('data');
        $this->assertSame([-8, 8, 16], collect($rows)->pluck('quantity_pieces')->sort()->values()->all());
        foreach ($rows as $row) {
            $this->assertSame('OLD8', $row['sku']);
            $this->assertSame('Nama historis', $row['name']);
            $this->assertSame(8, $row['pack_size']);
            $this->assertSame('2026-10-29T17:00:00+00:00', $row['occurred_at']);
            $this->assertArrayHasKey('created_at', $row);
            $this->assertArrayNotHasKey('retail_price', $row);
            $this->assertArrayNotHasKey('wholesale_price', $row);
        }
        $this->getJson($this->url)->assertOk();
        $this->assertSame($before, $batch->fresh()->toArray());
        $this->assertDatabaseCount('stock_entries', 3);
        $this->assertDatabaseCount('operation_receipts', 0);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_history_enforces_role_assignment_tenant_and_inactive_history_scope(): void
    {
        $this->credit();
        foreach (['store_manager', 'warehouse'] as $role) {
            Sanctum::actingAs($this->user($this->org, $role, true));
            $this->getJson($this->url)->assertOk();
        }
        $other = Organization::create(['name' => 'B', 'type' => 'partner']);
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        foreach ([$this->user($this->org, 'cashier', true), $this->user($this->org, 'store_manager'), $this->user($other, 'partner_lead'), $this->user($center, 'owner')] as $user) {
            Sanctum::actingAs($user);
            $this->getJson($this->url)->assertForbidden();
        }
        Sanctum::actingAs($this->lead);
        $this->store->update(['active' => false]);
        $this->getJson($this->url)->assertOk()->assertJsonPath('store.active', false)->assertJsonCount(1, 'data');
        $this->org->update(['active' => false]);
        $this->getJson($this->url)->assertForbidden();
    }

    public function test_filters_use_batch_source_bucket_and_historical_names_without_cross_store_leakage(): void
    {
        $a = $this->credit();
        $this->credit(null, 'LOT-B');
        $other = Store::create(['organization_id' => $this->org->id, 'name' => 'A2']);
        $foreign = $this->credit($other, 'LOT-A');
        DB::transaction(fn () => app(StockLedger::class)->apply($a, $this->lead->id, ['usable' => -8, 'opened' => 8], 'inventory_open', (string) Str::ulid(), 'Open fixture'));
        $this->getJson($this->url.'?'.http_build_query(['batch_id' => $a->id, 'source_type' => 'inventory_open', 'bucket' => 'usable', 'q' => 'OLD8']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity_pieces', -8)->assertJsonPath('data.0.batch_code', 'LOT-A');
        $this->getJson($this->url.'?q=LOT-B')->assertJsonCount(1, 'data');
        $this->getJson($this->url.'?batch_id='.$foreign->id)->assertNotFound();
        $this->getJson($this->url)->assertJsonCount(4, 'data');
    }

    public function test_explicit_time_bounds_match_wib_calendar_and_exclusive_end(): void
    {
        foreach (['2026-10-29T16:59:59Z', '2026-10-29T17:00:00Z', '2026-10-30T16:59:59Z', '2026-10-30T17:00:00Z'] as $instant) {
            $this->travelTo(CarbonImmutable::parse($instant));
            $this->credit();
        }
        $query = http_build_query(['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-10-31T00:00:00+07:00']);
        $this->getJson($this->url.'?'.$query)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('filters.from', '2026-10-29T17:00:00+00:00')->assertJsonPath('filters.to', '2026-10-30T17:00:00+00:00');
    }

    public function test_pagination_is_scoped_and_stable_on_unchanged_data_and_invalid_filters_are_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30T01:00:00Z'));
        for ($i = 0; $i < 101; $i++) {
            $this->credit();
        }
        $first = $this->getJson($this->url)->assertJsonCount(100, 'data')->assertJsonPath('last_page', 2)->json('data');
        $second = $this->getJson($this->url.'?page=2')->assertJsonCount(1, 'data')->json('data');
        $this->assertCount(101, collect([...$first, ...$second])->pluck('id')->unique()->all());
        foreach ([['page' => 0], ['bucket' => 'money'], ['source_type' => 'unknown'], ['batch_id' => 'bad'], ['from' => '2026-10-01T00:00:00Z'], ['to' => '2026-10-31T00:00:00Z'], ['from' => '2026-10-01', 'to' => '2026-10-02'], ['from' => '2026-10-01T00:00:00Z', 'to' => '2026-11-02T00:00:00Z'], ['q' => str_repeat('X', 101)]] as $bad) {
            $this->getJson($this->url.'?'.http_build_query($bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('stock_entries', 101);
    }
}
