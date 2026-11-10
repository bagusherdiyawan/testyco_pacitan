<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Accounting\Application\InvoiceValuation;
use App\Modules\Accounting\Application\WholesalePolicy;
use App\Modules\Billing\Infrastructure\Invoice;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceValuationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $center;

    private Organization $partner;

    private Store $source;

    private Store $target;

    private Product $supplierProduct;

    private Product $localProduct;

    private User $owner;

    private User $lead;

    private User $manager;

    private User $cashier;

    private User $warehouse;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09T03:00:00Z'));
        $this->center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $this->partner = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $other = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $this->source = Store::create(['name' => 'Gudang', 'organization_id' => $this->center->id, 'address' => 'Pacitan']);
        $this->target = Store::create(['name' => 'A1', 'organization_id' => $this->partner->id, 'address' => 'Ponorogo']);
        $this->owner = $this->user($this->center, 'owner');
        $this->warehouse = $this->user($this->center, 'warehouse', [$this->source]);
        $this->lead = $this->user($this->partner, 'partner_lead');
        $this->manager = $this->user($this->partner, 'store_manager', [$this->target]);
        $this->cashier = $this->user($this->partner, 'cashier', [$this->target]);
        $this->outsider = $this->user($other, 'partner_lead');
        $data = ['sku' => 'AYAM-8', 'name' => 'Dimsum Ayam', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 18000];
        $this->supplierProduct = Product::create([...$data, 'organization_id' => $this->center->id]);
        $this->localProduct = Product::create([...$data, 'organization_id' => $this->partner->id]);
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

    private function act(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function body(int $quantity = 20): array
    {
        return ['operation_id' => (string) Str::uuid(), 'delivery_mode' => 'delivery', 'note' => 'Permintaan toko', 'lines' => [['supplier_product_id' => $this->supplierProduct->id, 'local_product_id' => $this->localProduct->id, 'quantity_packs' => $quantity]]];
    }

    private function create(int $quantity = 20): array
    {
        return $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders', $this->body($quantity))->assertCreated()->json('data');
    }

    private function transition(array $order, string $action, User $actor, array $extra = []): array
    {
        return $this->act($actor)->postJson('/api/v1/orders/'.$order['id'].'/'.$action, ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], ...$extra])->assertOk()->json('data');
    }

    private function ready(int $quantity = 20, int $stock = 20): array
    {
        $order = $this->transition($this->create($quantity), 'approve', $this->lead);
        $order = $this->transition($order, 'process', $this->owner, ['source_store_id' => $this->source->id]);
        $batch = $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'B-001', 'expires_on' => '2030-01-01', 'quantity_packs' => $stock, 'note' => 'Penerimaan fisik gudang'])->assertCreated()->json('data');

        return [$order, $batch];
    }

    private function dispatch(array $order, array $batch, int $quantity = 20): array
    {
        return $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => $quantity]]]]])->assertCreated()->json('data');
    }

    private function billing(array $d): string
    {
        return '/api/v1/orders/'.$d['order']['id'].'/billing';
    }

    private function invoiceBody(array $d, string $basis = 'dispatched'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'mode' => 'buyout', 'basis' => $basis, 'shipment_ids' => [$d['shipment']['id']], 'due_on' => '2026-12-31', 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_basis_points' => 1100, 'rounding' => 'half_up', 'note' => 'Terms reviewed per document'];
    }

    private function acceptInvoice(array $d, array $i): array
    {
        return $this->act($this->lead)->postJson($this->billing($d).'/invoices/'.$i['id'].'/accept', ['operation_id' => (string) Str::uuid(), 'version' => $i['version'], 'consent' => true, 'reason' => 'Agree invoice basis and due date'])->assertOk()->json('data');
    }

    private function erp(Organization $org, string $action, array $v, User $actor): array
    {
        return $this->act($actor)->postJson('/api/v1/organizations/'.$org->id.'/erp/'.$action, ['operation_id' => (string) Str::uuid(), ...$v])->assertCreated()->json('data');
    }

    private function configure(Organization $org, User $manager, string $tax = 'recoverable', string $freight = 'expense', bool $wholesale = true): array
    {
        $reviewer = $this->user($org, $org->type === 'center' ? 'admin' : 'partner_lead');
        $period = $this->erp($org, 'accounting_period', ['name' => 'November2026', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'note' => 'Explicit period'], $manager);
        $body = ['note' => 'Explicit base accounts'];
        foreach (AutomaticPosting::ROLES as $role => [$label,$kind,$normal]) {
            $a = $this->erp($org, 'account', ['code' => 'POS-'.$role, 'name' => $label, 'kind' => $kind, 'normal_balance' => $normal], $manager);
            $body[$role.'_account'] = $a['id'];
        }
        $base = $this->erp($org, 'posting_policy', $body, $manager);
        $this->erp($org, 'posting_decide', ['id' => $base['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Independent base review'], $reviewer);
        $body = ['base_policy_id' => $base['id'], 'base_policy_version' => 2, 'tax_treatment' => $tax, 'freight_treatment' => $freight, 'note' => 'Reviewed wholesale terms'];
        foreach (WholesalePolicy::ROLES as $role => [$label,$kind,$normal]) {
            $a = $this->erp($org, 'account', ['code' => 'B2B-'.$role, 'name' => $label, 'kind' => $kind, 'normal_balance' => $normal], $manager);
            $body[$role.'_account'] = $a['id'];
        }
        if (! $wholesale) {
            return ['period' => $period, 'reviewer' => $reviewer, 'body' => $body, 'base' => $base];
        }
        $p = $this->erp($org, 'wholesale_policy', $body, $manager);
        $this->act($manager)->postJson('/api/v1/organizations/'.$org->id.'/erp/posting_decide', ['operation_id' => (string) Str::uuid(), 'id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Self review denied'])->assertForbidden();
        $this->erp($org, 'posting_decide', ['id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Independent wholesale review'], $reviewer);

        return ['policy' => $p, 'period' => $period, 'reviewer' => $reviewer, 'body' => $body, 'base' => $base];
    }

    private function event(string $org, string $type, string $source): object
    {
        return DB::table('accounting_events')->where('organization_id', $org)->where('source_type', $type)->where('source_id', $source)->firstOrFail();
    }

    private function terms(object $event): array
    {
        return json_decode($event->terms, true);
    }

    private function valued(int $sold = 40, string $tax = 'recoverable', string $freight = 'clearing'): array
    {
        $this->supplierProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        $this->localProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        [$order,$batch] = $this->ready(100, 100);
        DB::table('inventory_values')->where('inventory_batch_id', $batch['id'])->update(['remaining_value' => 100000]);
        $this->configure($this->center, $this->owner);
        $buyer = $this->configure($this->partner, $this->lead, $tax, $freight);
        $d = $this->dispatch($order, $batch, 100);
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $d['order']['version'], 'note' => '100accepted', 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 100, 'damaged_packs' => 0]]];
        $d = $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', $v)->assertOk()->json('data');
        $b = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        $sale = $sold ? $this->sell($b, $sold) : null;

        return [$d, $buyer, $b, $sale];
    }

    private function sell($b, int $qty): array
    {
        $shift = DB::table('cash_shifts')->where('store_id', $this->target->id)->where('cashier_id', $this->lead->id)->where('status', 'open')->first();
        if (! $shift) {
            $shift = (object) $this->act($this->lead)->postJson('/api/v1/stores/'.$this->target->id.'/shifts', ['operation_id' => (string) Str::uuid(), 'device_name' => 'Valuation POS', 'opening_cash' => 0])->assertCreated()->json('data');
        }
        $i = $this->act($this->lead)->postJson('/api/v1/stores/'.$this->target->id.'/sales/intents', ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift->id, 'lines' => [['product_id' => $this->localProduct->id, 'unit' => 'pack', 'quantity' => $qty, 'allocations' => [['batch_id' => $b->id, 'bucket' => 'usable', 'quantity' => $qty]]]]])->assertCreated()->json('data');

        return $this->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => $i['total_amount']])->assertOk()->json('data');
    }

    private function proposed(array $d, int $shipping = 20000, int $discount = 0, int $tax = 0): array
    {
        return $this->act($this->owner)->postJson($this->billing($d).'/invoices', [...$this->invoiceBody($d), 'shipping_amount' => $shipping, 'discount_amount' => $discount, 'tax_basis_points' => $tax])->assertCreated()->json('data');
    }

    private function finalInvoice(array $d, int $shipping = 20000, int $discount = 0, int $tax = 0): array
    {
        $i = $this->proposed($d, $shipping, $discount, $tax);
        $url = $this->billing($d).'/invoices/'.$i['id'].'/accept';
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'consent' => true, 'reason' => 'Final invoice agreed'];
        $i = $this->act($this->lead)->postJson($url, $v)->assertOk()->json('data');
        $this->postJson($url, $v)->assertOk();

        return $i;
    }

    private function refund(array $sale, int $qty): array
    {
        $shift = DB::table('cash_shifts')->where('id', $sale['cash_shift_id'])->firstOrFail();
        $v = ['version' => $sale['version'], 'shift_id' => $shift->id, 'shift_version' => $shift->version, 'stock_disposition' => 'quarantine', 'reference' => 'REF-'.Str::ulid(), 'reason' => 'Actual goods returned', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => $qty]]];
        $url = '/api/v1/sales/'.$sale['id'].'/refunds';
        $preview = $this->act($this->lead)->postJson($url.'/preview', $v)->assertOk()->json('data');
        $v = [...$v, 'operation_id' => (string) Str::uuid(), 'verified' => true, 'review_hash' => $preview['review_hash']];
        $r = $this->postJson($url, $v)->assertCreated()->json('data');
        $this->postJson($url, $v)->assertCreated();

        return $r;
    }

    public function test_positive_variance_updates_remaining_stock_and_original_sale_cogs_without_overwriting_history(): void
    {
        [$d,$buyer,$b,$sale] = $this->valued();
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 60, 'remaining_value' => 132000]);
        $this->assertDatabaseHas('inventory_invoice_variances', ['invoice_id' => $i['id'], 'original_value' => 200000, 'final_value' => 220000, 'stock_adjustment' => 12000, 'cogs_adjustment' => 8000]);
        $old = DB::table('inventory_cost_entries')->where('inventory_batch_id', $b->id)->where('source_type', 'pos_sale')->firstOrFail();
        $this->assertSame(-80000, $old->value_delta);
        $this->assertDatabaseHas('inventory_invoice_sale_adjustments', ['cost_entry_id' => $old->id, 'cost_delta' => 8000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 1);
        $state = DB::table('accounting_invoice_revaluations')->where('invoice_id', $i['id'])->firstOrFail();
        $this->assertSame('applied', $state->status);
        DB::transaction(fn () => app(InvoiceValuation::class)->capture(Invoice::findOrFail($i['id']), (object) ['id' => $buyer['policy']['id']], $this->lead->id));
        $this->assertDatabaseCount('inventory_invoice_variances', 1);
        $this->assertSame(6, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $this->refund($sale, 10);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 70, 'remaining_value' => 154000]);
        $this->assertDatabaseHas('inventory_cost_entries', ['inventory_batch_id' => $b->id, 'source_type' => 'sale_refund', 'quantity_delta' => 10, 'value_delta' => 22000]);
        $this->act($this->lead)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=invoice_variances')->assertOk()->assertSee('12000')->assertSee('8000');
    }

    public function test_negative_variance_and_future_refund_use_corrected_cost(): void
    {
        [$d,$buyer,$b,$sale] = $this->valued();
        $i = $this->finalInvoice($d, 0, 20000);
        $this->assertDatabaseHas('inventory_invoice_variances', ['invoice_id' => $i['id'], 'stock_adjustment' => -12000, 'cogs_adjustment' => -8000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 108000]);
        $this->refund($sale, 10);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 70, 'remaining_value' => 126000]);
    }

    public function test_zero_final_cost_never_produces_negative_stock_or_refund_cost(): void
    {
        [$d,$buyer,$b,$sale] = $this->valued();
        $i = $this->finalInvoice($d, 0, 200000);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => -120000, 'cogs_adjustment' => -80000]);
        $this->refund($sale, 10);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 70, 'remaining_value' => 0]);
    }

    public function test_closed_period_reviews_then_retry_applies_once_and_is_tenant_guarded(): void
    {
        [$d,$buyer,$b] = $this->valued();
        app(AutomaticPosting::class)->drain();
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'closed']);
        $i = $this->finalInvoice($d);
        $state = DB::table('accounting_invoice_revaluations')->where('invoice_id', $i['id'])->firstOrFail();
        $this->assertSame('review_required', $state->status);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 120000]);
        $url = '/api/v1/organizations/'.$this->partner->id.'/erp/invoice_revalue_retry';
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $state->id, 'version' => $state->version, 'note' => 'Original periods verified and reopened'];
        $this->act($this->manager)->postJson($url, $v)->assertForbidden();
        $this->act($this->outsider)->postJson($url, $v)->assertForbidden();
        $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/erp/invoice_revalue_retry', $v)->assertNotFound();
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'open']);
        $period = DB::table('accounting_periods')->find($buyer['period']['id']);
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->partner->id.'/erp/period_status', ['operation_id' => (string) Str::uuid(), 'id' => $period->id, 'version' => $period->version, 'decision' => 'close', 'note' => 'Must block pending valuation'])->assertConflict()->assertSee('penyesuaian');
        $this->postJson($url, $v)->assertCreated();
        $this->postJson($url, $v)->assertCreated();
        $this->assertSame(1, app(InvoiceValuation::class)->drain(100));
        $this->assertSame(0, app(InvoiceValuation::class)->drain(100));
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 132000]);
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid()])->assertConflict();
    }

    public function test_refund_before_finalization_is_revalued_with_original_refund_cost_preserved(): void
    {
        [$d,$buyer,$b,$sale] = $this->valued();
        $this->refund($sale, 10);
        $old = DB::table('inventory_cost_entries')->where('inventory_batch_id', $b->id)->where('source_type', 'sale_refund')->firstOrFail();
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 14000, 'cogs_adjustment' => 6000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 70, 'remaining_value' => 154000]);
        $this->assertDatabaseHas('inventory_cost_entries', ['id' => $old->id, 'value_delta' => 20000]);
        $this->assertDatabaseHas('inventory_invoice_movement_adjustments', ['cost_entry_id' => $old->id, 'cost_delta' => -2000, 'expense_role' => 'cogs']);
        $this->assertDatabaseCount('inventory_invoice_sale_adjustments', 1);
    }

    public function test_adjustment_failure_rolls_back_invoice_and_value_then_same_uuid_recovers(): void
    {
        [$d,$buyer,$b] = $this->valued();
        $i = $this->proposed($d);
        $url = $this->billing($d).'/invoices/'.$i['id'].'/accept';
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'consent' => true, 'reason' => 'Final agreement'];
        DB::unprepared("CREATE TRIGGER fail_cost_layer BEFORE INSERT ON inventory_invoice_sale_adjustments BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->act($this->lead)->postJson($url, $v)->assertStatus(500);
        $this->assertDatabaseHas('invoices', ['id' => $i['id'], 'status' => 'pending', 'version' => 1]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 120000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 0);
        $this->assertDatabaseCount('accounting_invoice_revaluations', 0);
        DB::unprepared('DROP TRIGGER fail_cost_layer');
        $this->postJson($url, $v)->assertOk();
        $this->postJson($url, $v)->assertOk();
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 132000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 1);
        $this->assertDatabaseCount('inventory_invoice_sale_adjustments', 1);
    }

    public function test_retry_after_later_sales_posts_depletion_on_sale_day_not_before_sale(): void
    {
        [$d,$buyer,$b] = $this->valued(0);
        app(AutomaticPosting::class)->drain();
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'closed']);
        $i = $this->finalInvoice($d);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:00:00Z'));
        $this->sell($b, 40);
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'open']);
        $state = DB::table('accounting_invoice_revaluations')->where('invoice_id', $i['id'])->firstOrFail();
        $this->erp($this->partner, 'invoice_revalue_retry', ['id' => $state->id, 'version' => $state->version, 'note' => 'Periods verified'], $this->lead);
        app(InvoiceValuation::class)->drain(100);
        $this->assertDatabaseHas('accounting_events', ['organization_id' => $this->partner->id, 'source_type' => 'invoice_variance', 'event_on' => '2026-11-09']);
        $this->assertDatabaseHas('accounting_events', ['organization_id' => $this->partner->id, 'source_type' => 'invoice_variance_depletion', 'event_on' => '2026-11-10']);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 132000]);
    }

    public function test_unsold_stock_gets_full_variance_without_inventing_sale_cost(): void
    {
        [$d,$buyer,$b] = $this->valued(0);
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 220000]);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 20000, 'cogs_adjustment' => 0]);
        $this->assertDatabaseCount('inventory_invoice_sale_adjustments', 0);
    }

    public function test_fully_sold_batch_keeps_zero_stock_and_future_return_uses_final_cost(): void
    {
        [$d,$buyer,$b,$sale] = $this->valued(100);
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 0, 'remaining_value' => 0]);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'stock_adjustment' => 0, 'cogs_adjustment' => 20000]);
        $this->refund($sale, 10);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 10, 'remaining_value' => 22000]);
    }

    public function test_irrecoverable_tax_is_capitalized_and_freight_expense_is_excluded(): void
    {
        [$d,$buyer,$b] = $this->valued(40, 'clearing', 'expense');
        $i = $this->finalInvoice($d, 20000, 0, 1000);
        $this->assertSame(22000, $i['tax_amount']);
        $this->assertDatabaseHas('inventory_invoice_variances', ['invoice_id' => $i['id'], 'final_value' => 222000, 'stock_adjustment' => 13200, 'cogs_adjustment' => 8800]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 133200]);
    }

    public function test_unchanged_invoice_finalizes_without_reopening_historical_period(): void
    {
        [$d,$buyer,$b] = $this->valued();
        app(AutomaticPosting::class)->drain();
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'closed']);
        $i = $this->finalInvoice($d, 0, 0);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 0, 'cogs_adjustment' => 0]);
        $this->assertDatabaseCount('inventory_invoice_variances', 0);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 120000]);
    }

    public function test_unproven_extra_receipt_is_reviewed_without_mutating_either_value(): void
    {
        [$d,$buyer,$b] = $this->valued();
        DB::transaction(fn () => app(StockLedger::class)->credit($this->partner->id, $this->target->id, $this->localProduct->toArray(), $b->batch_code, $b->expires_on->format('Y-m-d'), 1, $this->lead->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Other receipt fixture', 2000));
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'review_required']);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 61, 'remaining_value' => 122000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 0);
    }

    public function test_receipts_on_different_days_keep_their_own_adjustment_dates(): void
    {
        $this->supplierProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        $this->localProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        [$order,$batch] = $this->ready(100, 100);
        DB::table('inventory_values')->where('inventory_batch_id', $batch['id'])->update(['remaining_value' => 100000]);
        $other = $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'B-002', 'expires_on' => '2030-01-01', 'quantity_packs' => 50, 'note' => 'Second physical lot'])->assertCreated()->json('data');
        DB::table('inventory_values')->where('inventory_batch_id', $other['id'])->update(['remaining_value' => 50000]);
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead, 'recoverable', 'clearing');
        $first = $this->dispatch($order, $batch, 50);
        $second = $this->dispatch($first['order'], $other, 50);
        $body = [...$this->invoiceBody($first), 'shipment_ids' => [$first['shipment']['id'], $second['shipment']['id']], 'shipping_amount' => 20000, 'tax_basis_points' => 0];
        $i = $this->act($this->owner)->postJson($this->billing($first).'/invoices', $body)->assertCreated()->json('data');
        $this->acceptInvoice($first, $i);
        $receive = function ($shipment, $version) {
            return $this->act($this->lead)->postJson('/api/v1/shipments/'.$shipment['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'lines' => [['shipment_line_id' => $shipment['lines'][0]['id'], 'good_packs' => 50, 'damaged_packs' => 0]]])->assertOk()->json('data');
        };
        $r = $receive($first['shipment'], $second['order']['version']);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'review_required']);
        $target = InventoryBatch::where('store_id', $this->target->id)->where('batch_code', 'B-001')->firstOrFail();
        $this->sell($target, 40);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:00:00Z'));
        $receive($second['shipment'], $r['order']['version']);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'event_on' => '2026-11-09', 'stock_adjustment' => 12000, 'cogs_adjustment' => 8000]);
        $this->assertDatabaseHas('accounting_events', ['organization_id' => $this->partner->id, 'source_type' => 'invoice_variance', 'event_on' => '2026-11-09']);
        $this->assertDatabaseHas('accounting_events', ['organization_id' => $this->partner->id, 'source_type' => 'invoice_variance', 'event_on' => '2026-11-10']);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $target->id, 'quantity' => 10, 'remaining_value' => 22000]);
    }

    public function test_prior_return_then_new_sale_replays_pool_and_future_return_uses_corrected_second_sale(): void
    {
        [$d,$buyer,$b,$first] = $this->valued();
        $this->refund($first, 10);
        $second = $this->sell($b, 20);
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 50, 'remaining_value' => 110000]);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 10000, 'cogs_adjustment' => 10000]);
        $this->refund($second, 5);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 55, 'remaining_value' => 121000]);
    }

    public function test_stock_loss_before_invoice_splits_variance_between_remaining_stock_cogs_and_loss(): void
    {
        [$d,$buyer,$b,$sale] = $this->valued();
        DB::transaction(fn () => app(StockLedger::class)->apply($b->fresh(), $this->lead->id, ['usable' => -10, 'waste' => 10], 'fixture_reviewed_loss', (string) Str::ulid(), 'Actual loss evidence'));
        $old = DB::table('inventory_cost_entries')->where('inventory_batch_id', $b->id)->where('source_type', 'fixture_reviewed_loss')->firstOrFail();
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 50, 'remaining_value' => 110000]);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 10000, 'cogs_adjustment' => 8000, 'loss_adjustment' => 2000]);
        $this->assertDatabaseHas('inventory_invoice_movement_adjustments', ['cost_entry_id' => $old->id, 'cost_delta' => 2000, 'expense_role' => 'loss']);
        $this->assertDatabaseHas('inventory_cost_entries', ['id' => $old->id, 'value_delta' => -20000]);
        $this->assertSame(7, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    public function test_return_on_later_date_gets_its_own_cogs_correction_day_after_closed_period_retry(): void
    {
        [$d,$buyer,$b,$sale] = $this->valued();
        app(AutomaticPosting::class)->drain();
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'closed']);
        $i = $this->finalInvoice($d);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:00:00Z'));
        $this->refund($sale, 10);
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'open']);
        $state = DB::table('accounting_invoice_revaluations')->where('invoice_id', $i['id'])->firstOrFail();
        $this->erp($this->partner, 'invoice_revalue_retry', ['id' => $state->id, 'version' => $state->version, 'note' => 'Original periods reviewed'], $this->lead);
        $this->assertSame(1, app(InvoiceValuation::class)->drain(100));
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 154000]);
        $e = DB::table('accounting_events')->where('organization_id', $this->partner->id)->where('source_type', 'invoice_variance_depletion')->firstOrFail();
        $this->assertSame('2026-11-10', $e->event_on);
        $this->assertSame([['finished', 'cogs', 2000]], json_decode($e->terms, true));
    }

    public function test_unproven_stock_increase_remains_review_without_partial_layers(): void
    {
        [$d,$buyer,$b] = $this->valued();
        DB::transaction(fn () => app(StockLedger::class)->apply($b->fresh(), $this->lead->id, ['quarantined' => 10], 'unproven_increase', (string) Str::ulid(), inventoryValue: 20000));
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'review_required']);
        $this->assertDatabaseCount('inventory_invoice_variances', 0);
        $this->assertDatabaseCount('inventory_invoice_movement_adjustments', 0);
    }

    public function test_internal_transfer_carries_exact_actual_cost_with_no_revenue_or_cogs_and_scoped_receipt(): void
    {
        $config = $this->configure($this->center, $this->owner, wholesale: false);
        $target = Store::create(['organization_id' => $this->center->id, 'name' => 'Own center retail']);
        $intake = $this->act($this->owner)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'INTERNAL-COST', 'expires_on' => '2030-01-01', 'quantity_packs' => 100, 'note' => 'Actual source inventory'])->assertCreated()->json('data');
        $proposal = $this->erp($this->center, 'value_propose', ['batch_id' => $intake['id'], 'stock_revision' => $intake['stock_revision'], 'amount' => 100001, 'note' => 'Opening value evidence'], $this->owner);
        $this->erp($this->center, 'value_decide', ['id' => $proposal['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Other manager approved'], $config['reviewer']);
        $order = $this->act($this->owner)->postJson('/api/v1/stores/'.$target->id.'/orders', ['operation_id' => (string) Str::uuid(), 'delivery_mode' => 'pickup', 'lines' => [['supplier_product_id' => $this->supplierProduct->id, 'local_product_id' => $this->supplierProduct->id, 'quantity_packs' => 50]]])->assertCreated()->json('data');
        $this->assertSame('internal_transfer', $order['kind']);
        $this->assertSame(0, $order['total_amount']);
        $order = $this->transition($order, 'approve', $this->owner);
        $order = $this->transition($order, 'process', $this->owner, ['source_store_id' => $this->source->id]);
        $sent = $this->dispatch($order, $intake, 50);
        $request = ['operation_id' => (string) Str::uuid(), 'version' => $sent['order']['version'], 'lines' => [['shipment_line_id' => $sent['shipment']['lines'][0]['id'], 'good_packs' => 50, 'damaged_packs' => 0]]];
        $url = '/api/v1/shipments/'.$sent['shipment']['id'].'/receive';
        $this->act($this->lead)->postJson($url, $request)->assertForbidden();
        $this->act($this->owner)->postJson($url, $request)->assertOk();
        $this->postJson($url, $request)->assertOk();
        $batch = InventoryBatch::where('store_id', $target->id)->firstOrFail();
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $batch->id, 'quantity' => 400, 'remaining_value' => 50000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $intake['id'], 'quantity' => 400, 'remaining_value' => 50001]);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, DB::table('accounting_events')->whereIn('source_type', ['distribution_cogs', 'distribution_receipt'])->count());
        $this->assertSame(0, DB::table('accounting_events')->where('source_type', 'stock_review')->where('status', 'review_required')->count());
        $this->assertDatabaseCount('invoices', 0);
    }

    private function restockSameBatch(): array
    {
        [$order,$source] = $this->ready(100, 100);
        DB::table('inventory_values')->where('inventory_batch_id', $source['id'])->update(['remaining_value' => 100000]);
        $d = $this->dispatch($order, $source, 100);

        return $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $d['order']['version'], 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 100, 'damaged_packs' => 0]]])->assertOk()->json('data');
    }

    public function test_two_proven_receipts_in_one_batch_support_sequential_invoices_without_double_cogs(): void
    {
        [$first,$buyer,$b,$firstSale] = $this->valued();
        $second = $this->restockSameBatch();
        $secondSale = $this->sell($b, 80);
        $a = $this->finalInvoice($first);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 80, 'remaining_value' => 166000]);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $a['id'], 'status' => 'applied', 'stock_adjustment' => 6000, 'cogs_adjustment' => 14000]);
        $c = $this->finalInvoice($second);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 80, 'remaining_value' => 176000]);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $c['id'], 'status' => 'applied', 'stock_adjustment' => 10000, 'cogs_adjustment' => 10000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 2);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 2);
        $original = DB::table('inventory_cost_entries')->where('inventory_batch_id', $b->id)->where('source_type', 'pos_sale')->orderBy('stock_revision')->get();
        $this->assertSame([-80000, -160000], $original->pluck('value_delta')->all());
        $this->assertSame(16000, (int) DB::table('inventory_invoice_sale_adjustments')->where('cost_entry_id', $original[1]->id)->sum('cost_delta'));
        $this->refund($secondSale, 20);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 220000]);
    }

    public function test_invoice_covering_two_shipments_to_same_batch_writes_one_stock_variance_and_two_source_proofs(): void
    {
        $this->supplierProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        $this->localProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        [$order,$source] = $this->ready(100, 100);
        DB::table('inventory_values')->where('inventory_batch_id', $source['id'])->update(['remaining_value' => 100000]);
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead, 'recoverable', 'clearing');
        $a = $this->dispatch($order, $source, 50);
        $c = $this->dispatch($a['order'], $source, 50);
        $receive = function ($d, $version) {
            return $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 50, 'damaged_packs' => 0]]])->assertOk()->json('data');
        };
        $r = $receive($a, $c['order']['version']);
        $b = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        $this->sell($b, 40);
        $receive($c, $r['order']['version']);
        $i = $this->act($this->owner)->postJson($this->billing($a).'/invoices', [...$this->invoiceBody($a), 'shipment_ids' => [$a['shipment']['id'], $c['shipment']['id']], 'shipping_amount' => 20000, 'tax_basis_points' => 0])->assertCreated()->json('data');
        $this->acceptInvoice($a, $i);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 12000, 'cogs_adjustment' => 8000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 60, 'remaining_value' => 132000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 1);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 2);
        $this->assertSame([110000, 110000], DB::table('inventory_invoice_receipt_adjustments')->orderBy('id')->pluck('after_value')->all());
    }

    public function test_prior_corrected_refund_then_second_invoice_keeps_incremental_return_layers_conservative(): void
    {
        [$first,$buyer,$b,$sale] = $this->valued();
        $second = $this->restockSameBatch();
        $laterSale = $this->sell($b, 80);
        $this->finalInvoice($first);
        $this->refund($laterSale, 20); // 166000/80*20 =41500, already corrected.
        $i = $this->finalInvoice($second);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 12500, 'cogs_adjustment' => 7500]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 220000]);
        $refund = DB::table('inventory_cost_entries')->where('inventory_batch_id', $b->id)->where('source_type', 'sale_refund')->firstOrFail();
        $this->assertSame(41500, $refund->value_delta);
        $this->assertDatabaseHas('inventory_invoice_movement_adjustments', ['cost_entry_id' => $refund->id, 'cost_delta' => -2500]);
    }

    public function test_legacy_dedicated_variance_is_read_as_evidence_without_backfilling_or_overwriting_history(): void
    {
        [$first,$buyer,$b] = $this->valued();
        $this->finalInvoice($first);
        DB::table('inventory_invoice_receipt_adjustments')->delete(); // Simulates a deployment before migration34.
        $second = $this->restockSameBatch();
        $this->sell($b, 80);
        $i = $this->finalInvoice($second);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied']);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 1);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 80, 'remaining_value' => 176000]);
    }

    public function test_second_invoice_rejects_corrupt_prior_receipt_price_proof_without_partial_value_change(): void
    {
        [$first,$buyer,$b] = $this->valued();
        $this->finalInvoice($first);
        $second = $this->restockSameBatch();
        DB::table('inventory_invoice_receipt_adjustments')->update(['value_delta' => 1]);
        $before = DB::table('inventory_values')->where('inventory_batch_id', $b->id)->value('remaining_value');
        $i = $this->finalInvoice($second);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'review_required']);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => $before]);
        $this->assertDatabaseCount('inventory_invoice_variances', 1);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 1);
    }

    public function test_mixed_batch_source_proof_failure_rolls_back_both_receipts_and_same_uuid_recovers(): void
    {
        [$first,$buyer,$b] = $this->valued();
        $this->restockSameBatch();
        $i = $this->proposed($first);
        $url = $this->billing($first).'/invoices/'.$i['id'].'/accept';
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'consent' => true, 'reason' => 'Exact final invoice'];
        DB::unprepared("CREATE TRIGGER fail_receipt_price BEFORE INSERT ON inventory_invoice_receipt_adjustments BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->act($this->lead)->postJson($url, $v)->assertStatus(500);
        $this->assertDatabaseHas('invoices', ['id' => $i['id'], 'status' => 'pending', 'version' => 1]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 160, 'remaining_value' => 320000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 0);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 0);
        DB::unprepared('DROP TRIGGER fail_receipt_price');
        $this->postJson($url, $v)->assertOk();
        $this->postJson($url, $v)->assertOk();
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 160, 'remaining_value' => 332000]);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 1);
        $this->assertDatabaseCount('inventory_invoice_variances', 1);
        $this->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=invoice_variances')->assertOk()->assertJsonCount(1, 'data.0.receipt_adjustments')->assertSee('Sumber penerimaan');
        $this->act($this->owner)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=invoice_variances')->assertForbidden();
    }

    public function test_same_batch_receipts_on_different_days_preserve_both_effective_journal_dates(): void
    {
        $this->supplierProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        $this->localProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        [$order,$source] = $this->ready(100, 100);
        DB::table('inventory_values')->where('inventory_batch_id', $source['id'])->update(['remaining_value' => 100000]);
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead, 'recoverable', 'clearing');
        $a = $this->dispatch($order, $source, 50);
        $c = $this->dispatch($a['order'], $source, 50);
        $i = $this->act($this->owner)->postJson($this->billing($a).'/invoices', [...$this->invoiceBody($a), 'shipment_ids' => [$a['shipment']['id'], $c['shipment']['id']], 'shipping_amount' => 20000, 'tax_basis_points' => 0])->assertCreated()->json('data');
        $this->acceptInvoice($a, $i);
        $receive = function ($d, $version) {
            return $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 50, 'damaged_packs' => 0]]])->assertOk()->json('data');
        };
        $r = $receive($a, $c['order']['version']);
        $b = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        $this->sell($b, 40);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:00:00Z'));
        $receive($c, $r['order']['version']);
        $state = DB::table('accounting_invoice_revaluations')->where('invoice_id', $i['id'])->firstOrFail();
        $this->assertSame('applied', $state->status);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 60, 'remaining_value' => 132000]);
        $events = DB::table('accounting_events')->where('organization_id', $this->partner->id)->where('source_type', 'invoice_variance')->orderBy('event_on')->get();
        $this->assertSame(['2026-11-09', '2026-11-10'], $events->pluck('event_on')->all());
        $this->assertSame([['finished', 'purchase_clearing', 2000], ['cogs', 'purchase_clearing', 8000]], json_decode($events[0]->terms, true));
        $this->assertSame([['finished', 'purchase_clearing', 10000]], json_decode($events[1]->terms, true));
        $this->assertDatabaseCount('inventory_invoice_variances', 1);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 2);
    }

    public function test_same_day_later_sale_and_return_cancel_without_creating_an_empty_gl_journal(): void
    {
        [$d,$buyer,$b] = $this->valued(0);
        app(AutomaticPosting::class)->drain();
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'closed']);
        $i = $this->finalInvoice($d);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:00:00Z'));
        $sale = $this->sell($b, 10);
        $this->refund($sale, 10);
        DB::table('accounting_periods')->where('id', $buyer['period']['id'])->update(['status' => 'open']);
        $state = DB::table('accounting_invoice_revaluations')->where('invoice_id', $i['id'])->firstOrFail();
        $this->erp($this->partner, 'invoice_revalue_retry', ['id' => $state->id, 'version' => $state->version, 'note' => 'Original periods verified'], $this->lead);
        $this->assertSame(1, app(InvoiceValuation::class)->drain(100));
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 220000]);
        $this->assertSame(0, DB::table('accounting_events')->where('organization_id', $this->partner->id)->where('source_type', 'invoice_variance_depletion')->count());
        $this->assertGreaterThan(0, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    private function actualReceipt(int $good, int $damaged, int $price = 2000, bool $manualDispatch = false, bool $proofFailure = false, int $sourceValue = 100001): array
    {
        $this->supplierProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => $price]);
        $this->localProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => $price]);
        [$order,$source] = $this->ready(100, 100);
        DB::table('inventory_values')->where('inventory_batch_id', $source['id'])->update(['remaining_value' => $sourceValue]);
        $this->configure($this->center, $this->owner);
        $buyer = $this->configure($this->partner, $this->lead, 'recoverable', 'clearing');
        $d = $this->dispatch($order, $source, 100);
        if ($manualDispatch) {
            DB::table('accounting_events')->where('organization_id', $this->center->id)->where('source_type', 'stock_review')->update(['status' => 'reconciled', 'reason' => 'Fixture manually reconciled dispatch']);
        }
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $d['order']['version'], 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => $good, 'damaged_packs' => $damaged, 'condition_note' => 'Actual physical discrepancy inspected']]];
        $url = '/api/v1/shipments/'.$d['shipment']['id'].'/receive';
        if ($proofFailure) {
            DB::unprepared("CREATE TRIGGER fail_actual_receipt_proof BEFORE INSERT ON accounting_receipt_costs BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
            $this->act($this->lead)->postJson($url, $v)->assertStatus(500);
            $this->assertSame(0, InventoryBatch::where('store_id', $this->target->id)->count());
            $this->assertDatabaseHas('shipments', ['id' => $d['shipment']['id'], 'received_at' => null]);
            $this->assertDatabaseCount('accounting_receipt_costs', 0);
            DB::unprepared('DROP TRIGGER fail_actual_receipt_proof');
        }
        $received = $this->act($this->lead)->postJson($url, $v)->assertOk()->json('data');
        $this->postJson($url, $v)->assertOk();

        return [$received, $buyer, InventoryBatch::where('store_id', $this->target->id)->first(), $source];
    }

    public function test_damaged_goods_have_known_quarantined_value_and_final_cost_allocation_per_bucket(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(80, 20);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 200000]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $b->id, 'usable_pieces' => 80, 'quarantined_pieces' => 20]);
        $this->assertDatabaseHas('accounting_receipt_costs', ['organization_id' => $this->partner->id, 'good_pieces' => 80, 'quarantined_pieces' => 20, 'missing_pieces' => 0, 'quoted_value' => 200000]);
        $this->sell($b, 40);
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 12000, 'cogs_adjustment' => 8000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 60, 'remaining_value' => 132000]);
        $this->assertSame([176000, 44000], DB::table('inventory_invoice_receipt_adjustments')->orderBy('id')->pluck('after_value')->all());
        $this->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=invoice_variances')->assertOk()->assertJsonPath('data.0.receipt_evidence.0.quarantined_pieces', 20)->assertSee('karantina 20');
        $this->act($this->owner)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=invoice_variances')->assertForbidden();
    }

    public function test_short_receipt_values_only_actual_goods_and_received_basis_invoice_reconciles_them(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(90, 0);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 90, 'remaining_value' => 180000]);
        $this->assertDatabaseHas('accounting_receipt_costs', ['organization_id' => $this->partner->id, 'good_pieces' => 90, 'quarantined_pieces' => 0, 'missing_pieces' => 10, 'quoted_value' => 180000]);
        $this->assertSame([['cogs', 'finished', 90000]], $this->terms($this->event($this->center->id, 'distribution_cogs', $d['shipment']['lines'][0]['id'])));
        $review = DB::table('accounting_events')->where('organization_id', $this->center->id)->where('source_type', 'stock_review')->firstOrFail();
        $this->assertSame('review_required', $review->status);
        $this->assertStringContainsString('sisa nilai Rp10001', $review->reason);
        $this->sell($b, 40);
        $i = $this->act($this->owner)->postJson($this->billing($d).'/invoices', [...$this->invoiceBody($d, 'good_received'), 'shipping_amount' => 18000, 'tax_basis_points' => 0])->assertCreated()->json('data');
        $this->acceptInvoice($d, $i);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 10000, 'cogs_adjustment' => 8000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 50, 'remaining_value' => 110000]);
    }

    public function test_dispatched_invoice_quantity_does_not_spread_missing_goods_cost_into_actual_stock(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(80, 10);
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'review_required']);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 90, 'remaining_value' => 180000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 0);
        $this->assertDatabaseCount('inventory_invoice_receipt_adjustments', 0);
    }

    public function test_damaged_stock_loss_before_invoice_retains_original_loss_and_splits_final_variance(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(80, 20);
        DB::transaction(fn () => app(StockLedger::class)->apply($b->fresh(), $this->lead->id, ['quarantined' => -20, 'waste' => 20], 'fixture_reviewed_damage_loss', (string) Str::ulid(), 'Documented physical damaged-goods disposal'));
        $this->sell($b, 40);
        $i = $this->finalInvoice($d);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 8000, 'cogs_adjustment' => 8000, 'loss_adjustment' => 4000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 40, 'remaining_value' => 88000]);
        $this->assertDatabaseHas('inventory_cost_entries', ['inventory_batch_id' => $b->id, 'source_type' => 'fixture_reviewed_damage_loss', 'quantity_delta' => -20, 'value_delta' => -40000]);
    }

    public function test_zero_good_received_invoice_line_cannot_silently_ignore_positive_quarantine_value(): void
    {
        $this->supplierProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        $this->localProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        [$order,$source] = $this->ready(100, 100);
        DB::table('inventory_values')->where('inventory_batch_id', $source['id'])->update(['remaining_value' => 100001]);
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead, 'recoverable', 'clearing');
        $a = $this->dispatch($order, $source, 50);
        $c = $this->dispatch($a['order'], $source, 50);
        $first = $this->act($this->lead)->postJson('/api/v1/shipments/'.$a['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $c['order']['version'], 'lines' => [['shipment_line_id' => $a['shipment']['lines'][0]['id'], 'good_packs' => 0, 'damaged_packs' => 10, 'condition_note' => 'Ten damaged, forty missing']]])->assertOk()->json('data');
        $this->postJson('/api/v1/shipments/'.$c['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $first['order']['version'], 'lines' => [['shipment_line_id' => $c['shipment']['lines'][0]['id'], 'good_packs' => 50, 'damaged_packs' => 0]]])->assertOk();
        $i = $this->act($this->owner)->postJson($this->billing($a).'/invoices', [...$this->invoiceBody($a, 'good_received'), 'shipment_ids' => [$a['shipment']['id'], $c['shipment']['id']], 'shipping_amount' => 0, 'tax_basis_points' => 0])->assertCreated()->json('data');
        $this->acceptInvoice($a, $i);
        $b = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'review_required']);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 60, 'remaining_value' => 120000]);
        $this->assertDatabaseCount('inventory_invoice_variances', 0);
    }

    public function test_known_free_receipt_preserves_zero_cost_evidence_and_participates_in_later_mixed_invoice(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(100, 0, 0);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 0]);
        $this->assertDatabaseHas('accounting_receipt_costs', ['organization_id' => $this->partner->id, 'quoted_value' => 0, 'good_pieces' => 100]);
        $this->assertSame(0, DB::table('accounting_events')->where('organization_id', $this->partner->id)->where('source_type', 'distribution_receipt')->count());
        $this->supplierProduct->update(['wholesale_price' => 2000]);
        $this->localProduct->update(['wholesale_price' => 2000]);
        $second = $this->restockSameBatch();
        $this->sell($b, 100);
        $i = $this->finalInvoice($second);
        $this->assertDatabaseHas('accounting_invoice_revaluations', ['invoice_id' => $i['id'], 'status' => 'applied', 'stock_adjustment' => 10000, 'cogs_adjustment' => 10000]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 110000]);
    }

    public function test_manually_reconciled_dispatch_is_not_automatically_expensed_a_second_time(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(90, 0, manualDispatch: true);
        $event = $this->event($this->center->id, 'distribution_cogs', $d['shipment']['lines'][0]['id']);
        $this->assertSame('review_required', $event->status);
        $this->assertStringContainsString('sudah direkonsiliasi', $event->reason);
        app(AutomaticPosting::class)->drain();
        $this->assertSame(0, DB::table('accounting_journals')->where('organization_id', $this->center->id)->count());
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'remaining_value' => 180000]);
    }

    public function test_receipt_cost_evidence_failure_rolls_back_both_buckets_then_original_uuid_recovers_once(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(80, 20, proofFailure: true);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 200000]);
        $this->assertDatabaseCount('accounting_receipt_costs', 1);
        $this->assertSame(1, DB::table('accounting_events')->where('organization_id', $this->partner->id)->where('source_type', 'distribution_receipt')->count());
        $this->assertSame(2, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    public function test_zero_value_owned_receipt_cannot_be_converted_to_manual_or_automatic_consignment(): void
    {
        [$d,$buyer,$b] = $this->actualReceipt(100, 0, 0, sourceValue: 0);
        $this->assertSame(0, DB::table('accounting_events')->whereIn('source_type', ['distribution_receipt', 'distribution_cogs'])->count());
        $contract = PartnershipContract::create(['organization_id' => $this->center->id, 'partner_id' => $this->partner->id, 'created_by' => $this->owner->id, 'title' => 'New consignment contract', 'mode' => 'consignment', 'status' => 'accepted', 'version' => 3, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'terms' => 'Both parties must reconcile previous ownership', 'brand_terms' => 'Brand', 'data_scope' => 'Agreed addenda', 'facilities' => 'None']);
        $this->act($this->lead)->postJson($this->billing($d).'/reports', ['operation_id' => (string) Str::uuid(), 'contract_id' => $contract->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-09', 'note' => 'Must not replace owned-zero receipt', 'lines' => [['order_line_id' => $d['order']['lines'][0]['id'], 'quantity_pieces' => 10]]])->assertConflict();
        $terms = ['basis' => 'net_sales', 'supplier_basis_points' => 3000, 'tax_basis_points' => 0, 'loss_policy' => 'zero_floor', 'bundle_basis' => 'reject', 'costs' => [], 'rounding' => 'floor_magnitude_per_line', 'refund_policy' => 'reverse_proportionally', 'movement_policy' => 'original_receipt_store_only', 'frequency' => 'continuous', 'data_scope' => 'allocated_receipt_totals'];
        $g = $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/commercial-agreements', ['operation_id' => (string) Str::uuid(), 'kind' => 'consignment', 'contract_id' => $contract->id, 'order_id' => $d['order']['id'], 'starts_on' => '2026-11-09', 'ends_on' => '2026-12-31', 'reference' => 'Requires ownership reconciliation', 'terms' => $terms])->assertCreated()->json('data');
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->partner->id.'/commercial-agreements/'.$g['id'].'/accept', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Must reconcile ownership first', 'consent' => true, 'sharing_consented' => true])->assertConflict();
        $this->assertDatabaseHas('commercial_agreements', ['id' => $g['id'], 'status' => 'pending']);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 0]);
        $this->assertDatabaseCount('consignment_reports', 0);
    }
}
