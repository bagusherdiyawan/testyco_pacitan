<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Shifts\Infrastructure\CashShift;
use App\Shared\Domain\Proportion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosAdjustmentsTest extends TestCase
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

    private function voucher(array $extra = []): array
    {
        $body = ['operation_id' => (string) Str::uuid(), 'code' => 'SAVE1001', 'funding_organization_id' => $this->org->id, 'kind' => 'fixed', 'value' => 1001, 'maximum_discount' => 5000, 'minimum_subtotal' => 0, 'usage_limit' => 1, 'starts_on' => now('Asia/Jakarta')->toDateString(), 'ends_on' => now('Asia/Jakarta')->addDay()->toDateString(), 'store_ids' => [$this->store->id], 'product_ids' => [], 'restore_policy' => 'on_full_refund', 'terms' => 'Issuer funds this discount; restore only on full refund', ...$extra];

        return $this->act($this->manager)->postJson('/api/v1/organizations/'.$this->org->id.'/vouchers', $body)->assertCreated()->json('data');
    }

    private function noncash(array $i, string $ref = 'PAY-1'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'payment_method' => 'qris_manual', 'payment_reference' => $ref, 'verified' => true, 'verified_amount' => $i['total_amount']];
    }

    private function refundBody(array $sale, int $qty = 1, array $extra = []): array
    {
        $shift = CashShift::find($sale['cash_shift_id']);

        return ['version' => $sale['version'], 'shift_id' => $shift->id, 'shift_version' => $shift->version, 'stock_disposition' => 'quarantine', 'reference' => 'REF-'.Str::ulid(), 'reason' => 'Actual returned goods and money verified', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => $qty]], ...$extra];
    }

    private function refund(array $sale, array $body): array
    {
        $url = '/api/v1/sales/'.$sale['id'].'/refunds';
        $p = $this->act($this->manager)->postJson($url.'/preview', $body)->assertOk()->json('data');
        $v = [...$body, 'operation_id' => (string) Str::uuid(), 'verified' => true, 'review_hash' => $p['review_hash']];
        $r = $this->postJson($url, $v)->assertCreated()->json('data');
        $this->postJson($url, $v)->assertCreated()->assertJsonPath('data.refund.id', $r['refund']['id']);

        return $r;
    }

    public function test_manual_noncash_requires_verification_exact_amount_and_unique_reference_without_cash_drawer_credit(): void
    {
        $shift = $this->open();
        $i = $this->intent($shift);
        $url = '/api/v1/sales/intents/'.$i['id'].'/pay';
        $v = $this->noncash($i);
        foreach ([array_diff_key($v, ['verified' => 1]), [...$v, 'verified' => false], [...$v, 'verified_amount' => 1]] as $bad) {
            $this->act($this->cashier)->postJson($url, $bad)->assertUnprocessable();
        }
        $sale = $this->postJson($url, $v)->assertOk()->assertJsonPath('data.payment_method', 'qris_manual')->assertJsonPath('data.cash_received', 0)->assertJsonPath('data.change_amount', 0)->json('data');
        $this->postJson($url, $v)->assertOk();
        $this->assertDatabaseHas('cash_shifts', ['id' => $shift['id'], 'cash_sales' => 0, 'expected_cash' => 100000, 'noncash_sales' => 12000]);
        $this->assertSame(26, $this->batch->fresh()->ready_pieces);
        $j = $this->intent($shift);
        $this->postJson('/api/v1/sales/intents/'.$j['id'].'/pay', $this->noncash($j))->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->act($this->manager)->getJson('/api/v1/stores/'.$this->store->id.'/sales/summary?from=2020-01-01T00:00:00Z&to=2020-01-02T00:00:00Z')->assertOk()->assertJsonPath('data.cash_sales', 0);
    }

    public function test_voucher_drafts_do_not_reserve_last_use_payment_revalidates_and_refund_restores_once(): void
    {
        $voucher = $this->voucher();
        $s = $this->open();
        $a = $this->cart($s, 3);
        $a['voucher_code'] = $voucher['code'];
        $i = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $a)->assertCreated()->assertJsonPath('data.subtotal', 9000)->assertJsonPath('data.discount_amount', 1001)->assertJsonPath('data.total_amount', 7999)->json('data');
        $a['operation_id'] = (string) Str::uuid();
        $j = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $a)->assertCreated()->json('data');
        $this->assertDatabaseHas('vouchers', ['id' => $voucher['id'], 'used_count' => 0]);
        $sale = $this->pay($i);
        $this->assertSame(1001, $sale['lines'][0]['discount_amount']);
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$j['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertConflict();
        $this->assertDatabaseHas('vouchers', ['id' => $voucher['id'], 'used_count' => 1]);
        $r = $this->refund($sale, $this->refundBody($sale));
        $this->assertSame(2666, $r['refund']['amount']);
        $this->assertDatabaseHas('vouchers', ['id' => $voucher['id'], 'used_count' => 1]);
        $sale['version'] = 2;
        $r2 = $this->refund($sale, $this->refundBody($sale, 2));
        $this->assertSame(5333, $r2['refund']['amount']);
        $this->assertDatabaseHas('vouchers', ['id' => $voucher['id'], 'used_count' => 0]);
        $this->assertSame(3, $this->batch->fresh()->quarantined_pieces);
        $this->assertSame(27, $this->batch->fresh()->ready_pieces);
        $this->assertSame(100000, CashShift::find($s['id'])->expected_cash);
        $this->assertSame(7999, CashShift::find($s['id'])->cash_out);
        $sale['version'] = 3;
        $this->act($this->manager)->postJson('/api/v1/sales/'.$sale['id'].'/refunds/preview', $this->refundBody($sale))->assertUnprocessable();
    }

    public function test_noncash_partial_refund_preserves_original_and_records_only_noncash_refund_counter(): void
    {
        $i = $this->intent($this->open());
        $sale = $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', $this->noncash($i))->assertOk()->json('data');
        $original = Sale::with('lines')->find($sale['id'])->toArray();
        $r = $this->refund($sale, $this->refundBody($sale, 2, ['stock_disposition' => 'no_stock']));
        $this->assertSame(6000, $r['refund']['amount']);
        $this->assertSame('qris_manual', $r['refund']['payment_method']);
        $this->assertSame(0, DB::table('cash_movements')->count());
        $this->assertDatabaseHas('cash_shifts', ['id' => $sale['cash_shift_id'], 'expected_cash' => 100000, 'noncash_refunds' => 6000]);
        $this->assertSame(0, $this->batch->fresh()->quarantined_pieces);
        $after = Sale::with('lines')->find($sale['id'])->toArray();
        unset($original['version'],$after['version']);
        $this->assertSame($original, $after);
    }

    public function test_refund_scopes_stale_review_closed_shift_and_cash_shortage_rollback(): void
    {
        $sale = $this->pay($this->intent($this->open(cash: 0)));
        $v = $this->refundBody($sale);
        $url = '/api/v1/sales/'.$sale['id'].'/refunds';
        foreach ([$this->cashier, $this->warehouse, $this->outside] as $u) {
            $this->act($u)->getJson($url)->assertForbidden();
            $this->postJson($url.'/preview', $v)->assertForbidden();
        }
        $p = $this->act($this->manager)->postJson($url.'/preview', $v)->assertOk()->json('data');
        $bad = [...$v, 'operation_id' => (string) Str::uuid(), 'review_hash' => $p['review_hash'], 'verified' => true, 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => 2]]];
        $this->postJson($url, $bad)->assertConflict();
        $this->assertDatabaseCount('sale_refunds', 0);
        CashShift::whereKey($sale['cash_shift_id'])->update(['expected_cash' => 0]);
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid(), 'review_hash' => $p['review_hash'], 'verified' => true])->assertConflict();
        $this->assertDatabaseCount('sale_refunds', 0);
        $this->assertSame(0, $this->batch->fresh()->quarantined_pieces);
        CashShift::whereKey($sale['cash_shift_id'])->update(['status' => 'closed']);
        $this->postJson($url.'/preview', $v)->assertConflict();
    }

    public function test_voucher_tenant_funding_product_scope_dates_status_and_money_ratio(): void
    {
        $v = $this->voucher(['kind' => 'percent', 'value' => 2500, 'maximum_discount' => 2000, 'product_ids' => [$this->product->id], 'restore_policy' => 'never']);
        foreach ([$this->cashier, $this->warehouse, $this->outside] as $u) {
            $this->act($u)->getJson('/api/v1/organizations/'.$this->org->id.'/vouchers')->assertForbidden();
        }
        $s = $this->open();
        $cart = [...$this->cart($s), 'voucher_code' => $v['code']];
        $i = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $cart)->assertCreated()->assertJsonPath('data.discount_amount', 2000)->json('data');
        $this->act($this->manager)->postJson('/api/v1/organizations/'.$this->org->id.'/vouchers/'.$v['id'].'/status', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'active' => false, 'reason' => 'Suspend campaign'])->assertOk();
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertConflict();
        $this->assertSame(999999999998, Proportion::floor(999999999999, 999999999999, 1000000000000));
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_bundle_partial_refund_restores_frozen_components_across_original_batches_not_parent_stock(): void
    {
        $shrimp = Product::create(['organization_id' => $this->org->id, 'sku' => 'SHRIMP', 'name' => 'Shrimp', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 4000, 'wholesale_price' => 20000]);
        $shrimpBatch = $this->seedStock($shrimp);
        $second = DB::transaction(function () {
            $l = app(StockLedger::class);
            $b = $l->credit($this->org->id, $this->store->id, $this->product->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'SECOND', '2030-01-01', 1, $this->manager->id, 'shipment_receipt', (string) Str::ulid(), 'usable');
            $batch = InventoryBatch::find($b['id']);
            $l->apply($batch, $this->manager->id, ['usable' => -10, 'ready' => 10], 'inventory_cook', (string) Str::ulid());

            return $batch->fresh();
        });
        $bundle = Product::create(['organization_id' => $this->org->id, 'sku' => 'BUNDLE', 'name' => 'Bundle', 'condition' => 'frozen', 'pack_size' => 1, 'portion_size' => 1, 'retail_price' => 7000, 'wholesale_price' => 0]);
        $this->act($this->manager)->postJson('/api/v1/organizations/'.$this->org->id.'/products/'.$bundle->id.'/recipe', ['operation_id' => (string) Str::uuid(), 'version' => 0, 'active' => true, 'components' => [['product_id' => $this->product->id, 'pieces' => 2], ['product_id' => $shrimp->id, 'pieces' => 1]], 'reason' => 'Agreed recipe'])->assertOk();
        $shift = $this->open();
        $intent = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift['id'], 'lines' => [['product_id' => $bundle->id, 'unit' => 'piece', 'quantity' => 5, 'allocations' => [['component_product_id' => $this->product->id, 'batch_id' => $this->batch->id, 'bucket' => 'ready', 'quantity' => 3], ['component_product_id' => $this->product->id, 'batch_id' => $second->id, 'bucket' => 'ready', 'quantity' => 7], ['component_product_id' => $shrimp->id, 'batch_id' => $shrimpBatch->id, 'bucket' => 'ready', 'quantity' => 5]]]]])->assertCreated()->json('data');
        $sale = $this->pay($intent, 35000);
        $bundle->update(['retail_price' => 9999]);
        $this->refund($sale, $this->refundBody($sale));
        $sale['version'] = 2;
        $r = $this->refund($sale, $this->refundBody($sale, 4));
        $this->assertSame(28000, $r['refund']['amount']);
        $this->assertSame(3, $this->batch->fresh()->quarantined_pieces);
        $this->assertSame(7, $second->fresh()->quarantined_pieces);
        $this->assertSame(5, $shrimpBatch->fresh()->quarantined_pieces);
        $this->assertDatabaseMissing('inventory_batches', ['product_id' => $bundle->id]);
    }

    public function test_refund_ledger_failure_rolls_back_cash_voucher_refund_audit_and_operation_then_same_uuid_can_retry(): void
    {
        $v = $this->voucher();
        $shift = $this->open();
        $intent = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', [...$this->cart($shift), 'voucher_code' => $v['code']])->assertCreated()->json('data');
        $sale = $this->pay($intent);
        $url = '/api/v1/sales/'.$sale['id'].'/refunds';
        $body = $this->refundBody($sale, 4);
        $preview = $this->act($this->manager)->postJson($url.'/preview', $body)->assertOk()->json('data');
        $post = [...$body, 'operation_id' => (string) Str::uuid(), 'review_hash' => $preview['review_hash'], 'verified' => true];
        $cash = CashShift::find($shift['id'])->toArray();
        $audits = DB::table('audit_entries')->count();
        DB::statement("CREATE TRIGGER fail_refund_ledger BEFORE INSERT ON stock_entries WHEN NEW.source_type = 'sale_refund' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->postJson($url, $post)->assertStatus(500);
        $this->assertDatabaseCount('sale_refunds', 0);
        $this->assertDatabaseMissing('operation_receipts', ['operation_id' => $post['operation_id']]);
        $this->assertDatabaseHas('vouchers', ['id' => $v['id'], 'used_count' => 1]);
        $this->assertSame($cash, CashShift::find($shift['id'])->toArray());
        $this->assertSame($audits, DB::table('audit_entries')->count());
        $this->assertSame(0, $this->batch->fresh()->quarantined_pieces);
        DB::statement('DROP TRIGGER fail_refund_ledger');
        $this->postJson($url, $post)->assertCreated();
    }
}
