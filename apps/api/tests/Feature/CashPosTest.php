<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Shifts\Infrastructure\CashShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashPosTest extends TestCase
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

    public function test_four_pieces_cost_12000_cash_change_8000_and_stock_debited_once(): void
    {
        $shift = $this->open();
        $intent = $this->intent($shift);
        $this->assertSame(12000, $intent['total_amount']);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
        $sale = $this->pay($intent);
        $this->assertSame(12000, $sale['total_amount']);
        $this->assertSame(8000, $sale['change_amount']);
        $this->assertSame(26, $this->batch->fresh()->ready_pieces);
        $this->assertDatabaseHas('cash_shifts', ['id' => $shift['id'], 'cash_sales' => 12000, 'expected_cash' => 112000]);
        $this->assertDatabaseHas('stock_entries', ['source_type' => 'pos_sale', 'quantity_pieces' => -4]);
    }

    public function test_portion_and_pack_prices_are_retail_conversions_not_b2b_wholesale(): void
    {
        $s = $this->open();
        $portion = $this->intent($s, 2, 'portion');
        $this->assertSame(24000, $portion['total_amount']);
        $this->pay($portion, 30000);
        $pack = $this->intent($s, 2, 'pack', 'usable');
        $this->assertSame(60000, $pack['total_amount']);
        $sale = $this->pay($pack, 60000);
        $this->assertSame(10, $sale['lines'][0]['pieces_per_unit']);
        $this->assertSame(40, $this->batch->fresh()->usable_pieces);
        $this->assertSame(22, $this->batch->fresh()->ready_pieces);
    }

    public function test_pending_server_draft_can_be_recovered_without_creating_sale_or_reserving_stock(): void
    {
        $s = $this->open();
        $i = $this->intent($s);
        $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->store->id.'/pos/context')->assertJsonPath('data.pending_intents.0.id', $i['id']);
        $this->getJson('/api/v1/sales/intents/'.$i['id'])->assertJsonPath('data.intent.status', 'pending')->assertJsonPath('data.sale', null);
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
    }

    public function test_same_operation_replay_and_new_operation_on_same_paid_intent_never_double_charge(): void
    {
        $s = $this->open();
        $i = $this->intent($s);
        $v = ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000];
        $url = '/api/v1/sales/intents/'.$i['id'].'/pay';
        $this->act($this->cashier);
        $id = $this->postJson($url, $v)->assertOk()->json('data.id');
        $this->postJson($url, $v)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid(), 'cash_received' => 30000])->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(26, $this->batch->fresh()->ready_pieces);
        $this->assertDatabaseHas('cash_shifts', ['id' => $s['id'], 'cash_sales' => 12000]);
    }

    public function test_insufficient_cash_and_qris_selection_cannot_mark_intent_paid(): void
    {
        $i = $this->intent($this->open());
        $this->act($this->cashier);
        foreach ([['payment_method' => 'cash', 'cash_received' => 1000], ['payment_method' => 'qris', 'cash_received' => 20000]] as $v) {
            $this->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', ['operation_id' => (string) Str::uuid(), ...$v])->assertUnprocessable();
        }$this->assertDatabaseCount('sales', 0);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
        $this->assertDatabaseHas('sale_intents', ['id' => $i['id'], 'status' => 'pending']);
    }

    public function test_price_change_between_review_and_payment_requires_fresh_draft(): void
    {
        $s = $this->open();
        $i = $this->intent($s);
        $this->product->update(['retail_price' => 4000]);
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertConflict();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
    }

    public function test_receipt_keeps_names_prices_and_conversion_after_master_data_changes_and_reprints_do_not_write_stock(): void
    {
        $i = $this->intent($this->open(), 1, 'portion');
        $sale = $this->pay($i);
        $this->product->update(['name' => 'Baru', 'retail_price' => 5000, 'portion_size' => 5]);
        $this->store->update(['name' => 'Toko baru']);
        $entries = DB::table('stock_entries')->count();
        for ($n = 0; $n < 2; $n++) {
            $this->getJson('/api/v1/sales/'.$sale['id'])->assertJsonPath('data.store_name', 'A1')->assertJsonPath('data.lines.0.name', 'Ayam')->assertJsonPath('data.lines.0.pieces_per_unit', 4)->assertJsonPath('data.total_amount', 12000);
        }$this->assertDatabaseCount('stock_entries', $entries);
    }

    public function test_one_active_shift_per_cashier_store_but_other_cashier_can_open_and_replay_is_safe(): void
    {
        $v = ['operation_id' => (string) Str::uuid(), 'device_name' => 'Web', 'opening_cash' => 0];
        $url = '/api/v1/stores/'.$this->store->id.'/shifts';
        $this->act($this->cashier);
        $id = $this->postJson($url, $v)->assertCreated()->json('data.id');
        $this->postJson($url, $v)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->open($this->other);
        $this->assertDatabaseCount('cash_shifts', 2);
        $this->assertDatabaseCount('active_shift_locks', 2);
    }

    public function test_no_shift_other_cashier_shift_and_closed_shift_cannot_accept_new_intent(): void
    {
        $s = $this->open();
        $this->act($this->other)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($s))->assertNotFound();
        $this->act($this->cashier)->postJson('/api/v1/shifts/'.$s['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'counted_cash' => 100000])->assertOk();
        $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($s))->assertConflict();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_pending_intents_block_closing_until_cancelled_and_cancel_moves_no_money_or_stock(): void
    {
        $s = $this->open();
        $i = $this->intent($s);
        $close = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'counted_cash' => 100000];
        $this->postJson('/api/v1/shifts/'.$s['id'].'/close', $close)->assertConflict();
        $this->postJson('/api/v1/sales/intents/'.$i['id'].'/cancel', ['operation_id' => (string) Str::uuid(), 'reason' => 'Pembeli batal'])->assertOk();
        $this->postJson('/api/v1/shifts/'.$s['id'].'/close', [...$close, 'operation_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.difference_cash', 0);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('active_shift_locks', 0);
    }

    public function test_shift_cash_out_changes_drawer_not_revenue_and_physical_difference_is_recorded(): void
    {
        $this->product->update(['retail_price' => 100000]);
        $s = $this->open();
        $this->pay($this->intent($s, 2), 200000);
        $version = CashShift::find($s['id'])->version;
        $result = $this->act($this->manager)->postJson('/api/v1/shifts/'.$s['id'].'/cash-movements', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'kind' => 'out', 'amount' => 20000, 'category' => 'Operasional', 'reason' => 'Pengeluaran fisik'])->assertCreated()->assertJsonPath('data.shift.expected_cash', 280000)->assertJsonPath('data.shift.cash_sales', 200000)->json('data.shift');
        $this->act($this->cashier)->postJson('/api/v1/shifts/'.$s['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => $result['version'], 'counted_cash' => 275000, 'closing_note' => 'Selisih hitungan lima ribu'])->assertOk()->assertJsonPath('data.difference_cash', -5000);
        $this->assertDatabaseHas('cash_shifts', ['id' => $s['id'], 'cash_sales' => 200000, 'cash_out' => 20000]);
    }

    public function test_cashier_cannot_make_cash_movements_or_close_other_cashiers_shift(): void
    {
        $s = $this->open();
        $this->postJson('/api/v1/shifts/'.$s['id'].'/cash-movements', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'kind' => 'out', 'amount' => 1, 'category' => 'Lain', 'reason' => 'Bukan kewenangan'])->assertForbidden();
        $this->act($this->manager)->postJson('/api/v1/shifts/'.$s['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'counted_cash' => 100000])->assertForbidden();
    }

    public function test_overdraw_and_stale_cash_movement_are_denied_without_partial_records(): void
    {
        $s = $this->open();
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'kind' => 'out', 'amount' => 100001, 'category' => 'Lain', 'reason' => 'Overdraw'];
        $this->act($this->manager)->postJson('/api/v1/shifts/'.$s['id'].'/cash-movements', $v)->assertConflict();
        $this->postJson('/api/v1/shifts/'.$s['id'].'/cash-movements', [...$v, 'version' => 999, 'amount' => 1])->assertConflict();
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('cash_shifts', ['id' => $s['id'], 'expected_cash' => 100000]);
    }

    public function test_sale_after_cash_count_invalidates_close_version_and_difference_requires_reason(): void
    {
        $s = $this->open();
        $this->pay($this->intent($s));
        $this->postJson('/api/v1/shifts/'.$s['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'counted_cash' => 112000])->assertConflict();
        $version = CashShift::find($s['id'])->version;
        $this->postJson('/api/v1/shifts/'.$s['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'counted_cash' => 0])->assertUnprocessable();
        $this->assertDatabaseHas('cash_shifts', ['id' => $s['id'], 'status' => 'open']);
    }

    public function test_cross_partner_central_onboarding_admin_and_warehouse_cannot_use_or_read_partner_pos(): void
    {
        $s = $this->open();
        $sale = $this->pay($this->intent($s));
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $owner = $this->user($center, 'owner');
        foreach ([$this->outside, $owner, $this->warehouse] as $user) {
            $this->act($user)->getJson('/api/v1/stores/'.$this->store->id.'/pos/context')->assertForbidden();
            $this->getJson('/api/v1/sales/'.$sale['id'])->assertForbidden();
            $this->getJson('/api/v1/shifts/'.$s['id'])->assertForbidden();
        }
    }

    public function test_cashier_cannot_pay_or_read_another_cashiers_receipt_but_manager_can_read(): void
    {
        $s = $this->open();
        $i = $this->intent($s);
        $this->act($this->other)->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertForbidden();
        $sale = $this->pay($i);
        $this->act($this->other)->getJson('/api/v1/sales/'.$sale['id'])->assertForbidden();
        $this->act($this->manager)->getJson('/api/v1/sales/'.$sale['id'])->assertOk();
    }

    public function test_loose_sales_require_opened_or_ready_and_quarantine_is_never_eligible(): void
    {
        $s = $this->open();
        $this->act($this->cashier);
        foreach ([['piece', 'usable'], ['piece', 'quarantined'], ['pack', 'ready']] as [$unit,$bucket]) {
            $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($s, 1, $unit, $bucket))->assertUnprocessable();
        }$this->assertDatabaseCount('sale_intents', 0);
    }

    public function test_price_and_money_overflow_fractional_inputs_and_forged_totals_are_not_trusted(): void
    {
        $s = $this->open();
        $v = $this->cart($s);
        $i = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', [...$v, 'total_amount' => 1, 'discount_amount' => 999999])->assertCreated()->assertJsonPath('data.total_amount', 12000)->json('data');
        $this->product->update(['retail_price' => 1000000000, 'pack_size' => 10000]);
        $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($s, 1, 'pack', 'usable'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_stock_sold_by_other_cashier_before_confirmation_does_not_create_sale_or_debit_partial_stock(): void
    {
        $s = $this->open();
        $first = $this->intent($s);
        $other = $this->open($this->other);
        $this->pay($this->intent($other, 30, 'piece', 'ready', $this->other), 90000, $this->other);
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$first['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('cash_shifts', ['id' => $s['id'], 'expected_cash' => 100000, 'cash_sales' => 0]);
        $this->assertDatabaseHas('sale_intents', ['id' => $first['id'], 'status' => 'pending']);
    }

    public function test_disabled_store_and_product_cannot_create_new_sales(): void
    {
        $s = $this->open();
        $this->product->update(['active' => false]);
        $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($s))->assertNotFound();
        $this->product->update(['active' => true]);
        $this->store->update(['active' => false]);
        $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($s))->assertConflict();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_summary_uses_explicit_time_boundaries_and_store_manager_scope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28T16:59:59Z'));
        $s = $this->open();
        $this->pay($this->intent($s));
        $this->travelTo(CarbonImmutable::parse('2026-10-28T17:00:00Z'));
        $this->pay($this->intent($s));
        $url = '/api/v1/stores/'.$this->store->id.'/sales/summary?'.http_build_query(['from' => '2026-10-28T00:00:00+07:00', 'to' => '2026-10-29T00:00:00+07:00']);
        $this->getJson($url)->assertForbidden();
        $this->act($this->manager)->getJson($url)->assertOk()->assertJsonPath('data.transaction_count', 1)->assertJsonPath('data.net_sales', 12000);
    }

    public function test_paid_intent_recovery_still_returns_same_receipt_after_shift_closed(): void
    {
        $s = $this->open();
        $i = $this->intent($s);
        $sale = $this->pay($i);
        $version = CashShift::find($s['id'])->version;
        $this->postJson('/api/v1/shifts/'.$s['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'counted_cash' => 112000])->assertOk();
        $recovered = $this->pay($i);
        $this->assertSame($sale['id'], $recovered['id']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(26, $this->batch->fresh()->ready_pieces);
    }

    public function test_shift_movement_metadata_respects_role_status_and_inactive_store(): void
    {
        $s = $this->open();
        $url = '/api/v1/shifts/'.$s['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_record_movement', false);
        $this->act($this->manager)->getJson($url)->assertOk()->assertJsonPath('data.can_record_movement', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->store->update(['active' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_record_movement', false);
        $this->postJson($url.'/cash-movements', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'kind' => 'in', 'amount' => 100, 'category' => 'Modal', 'reason' => 'Tambah kas'])->assertConflict();
        $this->store->update(['active' => true]);
        $this->act($this->cashier)->postJson($url.'/close', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'counted_cash' => 100000])->assertOk();
        $this->act($this->manager)->getJson($url)->assertOk()->assertJsonPath('data.can_record_movement', false);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_movement_replay_after_close_preserves_original_record_and_never_changes_revenue_or_stock(): void
    {
        $s = $this->open();
        $url = '/api/v1/shifts/'.$s['id'].'/cash-movements';
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'kind' => 'out', 'amount' => 20000, 'category' => 'Operasional', 'reason' => 'Uang fisik keluar'];
        $entries = DB::table('stock_entries')->count();
        $record = $this->act($this->manager)->postJson($url, $v)->assertCreated()->assertJsonPath('data.shift.version', 2)->json('data');
        $this->act($this->cashier)->postJson('/api/v1/shifts/'.$s['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'counted_cash' => 80000])->assertOk();
        $this->act($this->manager)->postJson($url, $v)->assertCreated()->assertJsonPath('data.movement.id', $record['movement']['id'])->assertJsonPath('data.shift.version', 2);
        $this->postJson($url, [...$v, 'amount' => 1])->assertConflict();
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/v1/shifts/'.$s['id'])->assertJsonPath('data.status', 'closed')->assertJsonPath('data.version', 3)->assertJsonCount(1, 'data.movements');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_entries', $entries);
        $this->assertDatabaseHas('cash_shifts', ['id' => $s['id'], 'expected_cash' => 80000, 'cash_sales' => 0, 'cash_out' => 20000]);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'shift.cash_movement')->count());
    }

    public function test_shift_history_and_movements_enforce_assignment_and_tenant_scope(): void
    {
        $s = $this->open();
        $unassigned = $this->user($this->org, 'store_manager');
        $assigned = $this->user($this->org, 'store_manager', [$this->store]);
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $owner = $this->user($center, 'owner');
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'kind' => 'in', 'amount' => 10000, 'category' => 'Modal', 'reason' => 'Uang fisik diterima'];
        foreach ([$this->outside, $this->warehouse, $unassigned, $owner] as $actor) {
            $this->act($actor)->getJson('/api/v1/stores/'.$this->store->id.'/shifts')->assertForbidden();
            $this->getJson('/api/v1/shifts/'.$s['id'])->assertForbidden();
            $this->postJson('/api/v1/shifts/'.$s['id'].'/cash-movements', $v)->assertForbidden();
        }
        $this->act($this->other)->getJson('/api/v1/stores/'.$this->store->id.'/shifts')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/shifts/'.$s['id'])->assertForbidden();
        $this->act($assigned)->getJson('/api/v1/stores/'.$this->store->id.'/shifts')->assertJsonPath('data.0.id', $s['id']);
        $this->getJson('/api/v1/shifts/'.$s['id'])->assertJsonPath('data.can_record_movement', true);
        $this->postJson('/api/v1/shifts/'.$s['id'].'/cash-movements', $v)->assertCreated()->assertJsonPath('data.shift.expected_cash', 110000);
    }

    public function test_used_financial_schema_cannot_be_rolled_back(): void
    {
        $this->open();
        $migration = require database_path('migrations/2026_10_28_000004_create_cash_pos.php');
        try {
            $migration->down();
            $this->fail('Used financial schema must not be erased.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback POS ditolak', $e->getMessage());
        }$this->assertDatabaseCount('cash_shifts', 1);
    }

    public function test_two_sku_checkout_rolls_back_every_financial_and_stock_write_if_one_batch_is_no_longer_available(): void
    {
        $other = Product::create([...$this->product->only(['name', 'condition', 'pack_size', 'portion_size', 'retail_price', 'wholesale_price']), 'organization_id' => $this->org->id, 'sku' => 'UDANG10']);
        $batch = $this->seedStock($other);
        $s = $this->open();
        $v = $this->cart($s);
        $v['lines'][] = ['product_id' => $other->id, 'unit' => 'piece', 'quantity' => 4, 'allocations' => [['batch_id' => $batch->id, 'bucket' => 'ready', 'quantity' => 4]]];
        $intent = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $v)->assertCreated()->json('data');
        DB::transaction(fn () => app(StockLedger::class)->apply($batch, $this->manager->id, ['ready' => -30, 'waste' => 30], 'stock_waste', (string) Str::ulid(), 'Damaged after quote'));
        $entries = DB::table('stock_entries')->count();
        $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 30000])->assertConflict();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_lines', 0);
        $this->assertDatabaseCount('sale_allocations', 0);
        $this->assertDatabaseCount('stock_entries', $entries);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
        $this->assertDatabaseHas('cash_shifts', ['id' => $s['id'], 'cash_sales' => 0, 'expected_cash' => 100000]);
    }

    public function test_foreign_batch_and_forged_deep_prices_or_status_cannot_bypass_server_snapshot(): void
    {
        $s = $this->open();
        $v = $this->cart($s);
        $v['lines'][0]['unit_price'] = 1;
        $v['lines'][0]['allocations'][0]['pieces'] = 1;
        $v['status'] = 'paid';
        $intent = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $v)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.items.0.unit_price', 3000)->assertJsonPath('data.items.0.allocations.0.pieces', 4)->json('data');
        $otherStore = Store::create(['organization_id' => $this->org->id, 'name' => 'A2']);
        $foreign = InventoryBatch::create([...$this->batch->only(['organization_id', 'product_id', 'sku', 'name', 'batch_code', 'expires_on', 'condition', 'pack_size']), 'store_id' => $otherStore->id, 'ready_pieces' => 100]);
        $v = $this->cart($s);
        $v['lines'][0]['allocations'][0]['batch_id'] = $foreign->id;
        $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $v)->assertNotFound();
        $this->assertDatabaseCount('sale_intents', 1);
    }

    public function test_zero_price_catalog_item_is_recorded_explicitly_and_paid_intent_cannot_be_cancelled_as_a_refund(): void
    {
        $this->product->update(['retail_price' => 0]);
        $i = $this->intent($this->open());
        $sale = $this->pay($i, 0);
        $this->assertSame(0, $sale['total_amount']);
        $this->postJson('/api/v1/sales/intents/'.$i['id'].'/cancel', ['operation_id' => (string) Str::uuid(), 'reason' => 'Not a refund shortcut'])->assertConflict();
        $this->assertSame(26, $this->batch->fresh()->ready_pieces);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_pos_sale_updates_stock_revision_and_invalidates_pending_physical_count(): void
    {
        $manager = $this->manager;
        $secondLead = $this->user($this->org, 'partner_lead');
        $count = $this->act($manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/counts', ['operation_id' => (string) Str::uuid(), 'batch_id' => $this->batch->id, 'expected_revision' => $this->batch->stock_revision, 'purpose' => 'stocktake', 'bucket' => 'ready', 'counted_pieces' => 29, 'reason' => 'Physical snapshot'])->assertCreated()->json('data');
        $this->pay($this->intent($this->open()));
        $this->act($secondLead)->postJson('/api/v1/inventory/counts/'.$count['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Snapshot before checkout'])->assertConflict();
        $this->assertSame(26, $this->batch->fresh()->ready_pieces);
    }

    public function test_filtered_receipt_history_retains_ownership_and_store_tenant_guards(): void
    {
        $mine = $this->pay($this->intent($this->open()));
        $theirs = $this->pay($this->intent($this->open($this->other), actor: $this->other), actor: $this->other);
        $path = '/api/v1/stores/'.$this->store->id.'/sales';
        $this->act($this->cashier)->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id'])->assertJsonPath('scope', 'own')->assertJsonPath('store.id', $this->store->id)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($path.'?q='.urlencode($theirs['number']))->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sales/'.$theirs['id'])->assertForbidden();
        $assigned = $this->user($this->org, 'store_manager', [$this->store]);
        $this->act($assigned)->getJson($path)->assertJsonCount(2, 'data')->assertJsonPath('scope', 'store');
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        foreach ([$this->outside, $this->warehouse, $this->user($this->org, 'store_manager'), $this->user($center, 'owner')] as $actor) {
            $this->act($actor)->getJson($path)->assertForbidden();
            $this->getJson('/api/v1/sales/'.$mine['id'])->assertForbidden();
        }
        $this->store->update(['active' => false]);
        $this->act($this->cashier)->getJson($path)->assertOk();
        $this->org->update(['active' => false]);
        $this->getJson($path)->assertForbidden();
    }

    public function test_receipt_filters_use_half_open_wib_period_and_historical_snapshots_without_writes(): void
    {
        $shift = $this->open();
        $times = ['2026-10-29 16:59:59', '2026-10-29 17:00:00', '2026-10-30 16:59:59', '2026-10-30 17:00:00'];
        $ids = [];
        foreach ($times as $time) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'UTC'));
            $ids[] = $this->pay($this->intent($shift))['id'];
        }
        CarbonImmutable::setTestNow();
        $this->product->update(['name' => 'Nama baru', 'pack_size' => 8, 'retail_price' => 9000, 'active' => false]);
        $this->store->update(['name' => 'Toko baru']);
        $this->cashier->update(['name' => 'Kasir baru']);
        $tables = ['sales', 'sale_lines', 'stock_entries', 'operation_receipts', 'audit_entries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $batch = $this->batch->fresh()->toArray();
        $cash = CashShift::findOrFail($shift['id'])->toArray();
        $url = '/api/v1/stores/'.$this->store->id.'/sales?'.http_build_query(['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-10-31T00:00:00+07:00']);
        $rows = $this->act($this->cashier)->getJson($url)->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing([$ids[1], $ids[2]], array_column($rows, 'id'));
        $this->assertSame('A1', $rows[0]['store_name']);
        $this->getJson($url.'&q='.urlencode($rows[0]['number']))->assertJsonCount(1, 'data');
        $receipt = $this->getJson('/api/v1/sales/'.$ids[1])->assertOk()->json('data');
        $this->assertSame('Ayam', $receipt['lines'][0]['name']);
        $this->assertSame(3000, $receipt['lines'][0]['unit_price']);
        foreach ($before as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame($batch, $this->batch->fresh()->toArray());
        $this->assertSame($cash, CashShift::findOrFail($shift['id'])->toArray());
    }

    public function test_receipt_history_paginates_beyond_fifty_and_rejects_invalid_filters(): void
    {
        $sale = $this->pay($this->intent($this->open()));
        // Read-model fixtures only: no checkout or stock/cash accounting is inferred from these copies.
        for ($i = 0; $i < 50; $i++) {
            $intent = SaleIntent::findOrFail($sale['sale_intent_id'])->replicate();
            $intent->save();
            $copy = Sale::findOrFail($sale['id'])->replicate();
            $copy->sale_intent_id = $intent->id;
            $copy->number = 'HISTORY-FIXTURE-'.$i;
            $copy->save();
        }
        $url = '/api/v1/stores/'.$this->store->id.'/sales';
        $first = $this->getJson($url)->assertJsonCount(50, 'data')->assertJsonPath('last_page', 2)->json('data');
        $second = $this->getJson($url.'?page=2')->assertJsonCount(1, 'data')->json('data');
        $this->assertCount(51, array_unique(array_column([...$first, ...$second], 'id')));
        foreach ([['page' => 0], ['page' => 'bad'], ['q' => str_repeat('a', 101)], ['from' => '2026-10-01T00:00:00Z'], ['to' => '2026-10-02T00:00:00Z'], ['from' => '2026-10-01', 'to' => '2026-10-02'], ['from' => '2026-10-02T00:00:00Z', 'to' => '2026-10-01T00:00:00Z'], ['from' => '2026-10-01T00:00:00Z', 'to' => '2026-11-02T00:00:00Z']] as $params) {
            $this->getJson($url.'?'.http_build_query($params))->assertUnprocessable();
        }
    }
}
