<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\Infrastructure\Invoice;
use App\Modules\Billing\Infrastructure\InvoicePayment;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Shifts\Infrastructure\CashShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommercialReportTest extends TestCase
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

    private function url(): string
    {
        return '/api/v1/organizations/'.$this->org->id.'/reports/commercial?'.http_build_query(['from' => now()->subDay()->toIso8601String(), 'to' => now()->addDay()->toIso8601String()]);
    }

    public function test_margin_unknown_without_cost_then_versioned_evidence_never_changes_cash_stock(): void
    {
        $sale = $this->pay($this->intent($this->open()));
        $this->act($this->manager)->getJson($this->url())->assertOk()->assertJsonPath('metrics.pos_sale.profit_amount', null)->assertJsonPath('metrics.pos_sale.net_goods_amount', 12000);
        $cash = CashShift::find($sale['cash_shift_id'])->toArray();
        $ledger = DB::table('stock_entries')->count();
        $url = '/api/v1/organizations/'.$this->org->id.'/reports/commercial/costs';
        $v = ['operation_id' => (string) Str::uuid(), 'source_type' => 'pos_sale', 'source_id' => $sale['id'], 'version' => 0, 'goods_cost' => 4000, 'operating_cost' => 500, 'evidence_reference' => 'Actual item cost document', 'reason' => 'Verified net cost'];
        $this->postJson($url, $v)->assertCreated()->assertJsonPath('data.version', 1);
        $this->postJson($url, $v)->assertCreated();
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonPath('metrics.pos_sale.profit_amount', 7500)->assertJsonPath('metrics.pos_sale.costed_document_count', 1);
        $this->assertSame($cash, CashShift::find($sale['cash_shift_id'])->toArray());
        $this->assertSame($ledger, DB::table('stock_entries')->count());
        $this->assertDatabaseCount('cost_declarations', 1);
        foreach ([$this->cashier, $this->warehouse, $this->outside] as $u) {
            $this->act($u)->getJson($this->url())->assertForbidden();
        }
    }

    public function test_b2b_receivable_and_margin_never_treat_partner_purchases_as_supplier_pos(): void
    {
        $center = Organization::create(['name' => 'Supplier', 'type' => 'center']);
        $owner = $this->user($center, 'owner');
        $o = SupplyOrder::create(['organization_id' => $this->org->id, 'supplier_organization_id' => $center->id, 'store_id' => $this->store->id, 'created_by' => $this->manager->id, 'destination_name' => 'Historical destination', 'kind' => 'wholesale', 'status' => 'completed', 'version' => 1, 'delivery_mode' => 'pickup', 'total_amount' => 1100, 'approved_at' => now()]);
        $invoice = Invoice::create(['supply_order_id' => $o->id, 'organization_id' => $center->id, 'partner_id' => $this->org->id, 'number' => 'INV-READ-1', 'status' => 'accepted', 'version' => 2, 'basis' => 'dispatched', 'mode' => 'buyout', 'currency' => 'IDR', 'rounding' => 'half_up', 'due_on' => now()->subDay()->toDateString(), 'subtotal' => 1000, 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_basis_points' => 1000, 'tax_amount' => 100, 'total_amount' => 1100, 'lines' => [], 'note' => 'Read model fixture', 'created_by' => $owner->id, 'decided_by' => $this->manager->id, 'decided_at' => now()]);
        InvoicePayment::create(['invoice_id' => $invoice->id, 'kind' => 'collection', 'method' => 'bank_transfer', 'amount' => 400, 'reference' => 'ACTUAL-400', 'paid_on' => now()->toDateString(), 'note' => 'Verified collection', 'status' => 'verified', 'verified_by' => $owner->id, 'verified_at' => now()]);
        $cost = ['operation_id' => (string) Str::uuid(), 'source_type' => 'wholesale_invoice', 'source_id' => $invoice->id, 'version' => 0, 'goods_cost' => 200, 'operating_cost' => 50, 'evidence_reference' => 'Supplier private cost', 'reason' => 'Evidence verified'];
        $this->act($owner)->postJson('/api/v1/organizations/'.$center->id.'/reports/commercial/costs', $cost)->assertCreated();
        $query = http_build_query(['from' => now()->subDay()->toIso8601String(), 'to' => now()->addDay()->toIso8601String()]);
        $this->getJson('/api/v1/organizations/'.$center->id.'/reports/commercial?'.$query)->assertOk()->assertJsonPath('metrics.wholesale_invoice.profit_amount', 750)->assertJsonPath('metrics.pos_sale.document_count', 0)->assertJsonPath('balances.outstanding_amount', 700)->assertJsonPath('balances.overdue_amount', 700);
        $this->act($this->manager)->getJson('/api/v1/organizations/'.$this->org->id.'/reports/commercial?'.$query)->assertOk()->assertJsonPath('side', 'buyer')->assertJsonPath('metrics.purchase_invoice.net_goods_amount', 1000)->assertJsonPath('metrics.purchase_invoice.profit_amount', null)->assertDontSee('Supplier private cost');
        $this->getJson('/api/v1/organizations/'.$center->id.'/reports/commercial?'.$query)->assertForbidden();
    }
}
