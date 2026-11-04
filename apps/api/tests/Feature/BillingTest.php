<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingTest extends TestCase
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

    private function received(int $sent = 20, int $good = 17, int $damaged = 2): array
    {
        [$o,$b] = $this->ready();
        $d = $this->dispatch($o, $b, $sent);

        return $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $d['order']['version'], 'note' => 'Physical check', 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => $good, 'damaged_packs' => $damaged, 'condition_note' => 'Damage or shortage noted']]])->assertOk()->json('data');
    }

    private function path(array $data): string
    {
        return '/api/v1/orders/'.$data['order']['id'].'/shipments/'.$data['shipment']['id'].'/discrepancy';
    }

    private function reportBody(array $data): array
    {
        return ['operation_id' => (string) Str::uuid(), 'order_version' => $data['order']['version'], 'reason' => 'Report physical discrepancy', 'reference' => 'Inspection log 1'];
    }

    private function report(array $data): array
    {
        return $this->act($this->lead)->postJson($this->path($data), $this->reportBody($data))->assertCreated()->json('data');
    }

    private function propose(array $data, int $version = 1, string $type = 'replacement'): array
    {
        return $this->act($this->owner)->postJson($this->path($data).'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'type' => $type, 'proposal' => 'Proposed administrative plan', 'reference' => 'Check physical handling first'])->assertCreated()->json('data');
    }

    private function confirmed(): array
    {
        $d = $this->received();
        $this->report($d);
        $p = $this->propose($d, type: 'return');
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();

        return $d;
    }

    private function sendBody(array $d, int $qty = 2, int $version = 3): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'destination_bucket' => 'quarantined', 'handler' => 'Physical return courier', 'lines' => [['original_shipment_line_id' => $d['shipment']['lines'][0]['id'], 'allocations' => [['batch_id' => DB::table('inventory_batches')->where('store_id', $this->target->id)->value('id'), 'quantity_packs' => $qty]]]]];
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

    public function test_explicit_invoice_tax_partial_collection_reversal_and_history(): void
    {
        $d = $this->received();
        $url = $this->billing($d);
        $b = $this->invoiceBody($d);
        $n = DB::table('stock_entries')->count();
        $i = $this->act($this->owner)->postJson($url.'/invoices', $b)->assertCreated()->assertJsonPath('data.subtotal', 360000)->assertJsonPath('data.tax_amount', 39600)->assertJsonPath('data.total_amount', 399600)->json('data');
        $this->postJson($url.'/invoices', $b)->assertCreated()->assertJsonPath('data.id', $i['id']);
        $this->postJson($url.'/invoices', [...$b, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $pay = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'kind' => 'collection', 'method' => 'bank_transfer', 'amount' => 100000, 'reference' => 'BANK-1', 'paid_on' => '2026-11-03', 'verified' => true, 'note' => 'Bank statement checked'];
        $this->postJson($url.'/invoices/'.$i['id'].'/payments', $pay)->assertConflict();
        $i = $this->acceptInvoice($d, $i);
        $pay['version'] = $i['version'];
        $paid = $this->act($this->owner)->postJson($url.'/invoices/'.$i['id'].'/payments', $pay)->assertCreated()->assertJsonPath('data.outstanding_amount', 299600)->json('data');
        $this->postJson($url.'/invoices/'.$i['id'].'/payments', $pay)->assertCreated()->assertJsonPath('data.version', 3);
        $this->postJson($url.'/invoices/'.$i['id'].'/payments', [...$pay, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson($url.'/invoices/'.$i['id'].'/payments', [...$pay, 'operation_id' => (string) Str::uuid(), 'version' => 3, 'reference' => 'BANK-2', 'amount' => 300000])->assertUnprocessable();
        $this->postJson($url.'/invoices/'.$i['id'].'/payments/'.$paid['payments'][0]['id'].'/reverse', ['operation_id' => (string) Str::uuid(), 'version' => 3, 'reason' => 'Incorrect matching, reversed'])->assertOk()->assertJsonPath('data.outstanding_amount', 399600)->assertJsonPath('data.payments.0.status', 'reversed');
        $this->assertSame($n, DB::table('stock_entries')->count());
        $this->assertSame(0, DB::table('cash_movements')->count());
        $this->act($this->lead)->getJson($url)->assertOk()->assertJsonPath('invoices.data.0.lines.0.unit_price', 18000);
    }

    public function test_return_credit_requires_physical_receipt_and_two_party_confirmation(): void
    {
        $d = $this->confirmed();
        $url = $this->billing($d);
        $i = $this->act($this->owner)->postJson($url.'/invoices', $this->invoiceBody($d))->assertCreated()->json('data');
        $i = $this->acceptInvoice($d, $i);
        $credit = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'case_id' => DB::table('shipment_discrepancies')->value('id'), 'base_amount' => 36000, 'tax_amount' => 3960, 'reason' => 'Return two damaged packs'];
        $this->act($this->owner)->postJson($url.'/invoices/'.$i['id'].'/credits', $credit)->assertUnprocessable();
        $r = $this->act($this->lead)->postJson($this->path($d).'/returns', $this->sendBody($d))->assertCreated()->json('data');
        $this->act($this->warehouse)->postJson($this->path($d).'/returns/'.$r['return']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => 4, 'lines' => [['return_line_id' => $r['return']['lines'][0]['id'], 'received_packs' => 2]]])->assertOk();
        $pending = $this->act($this->owner)->postJson($url.'/invoices/'.$i['id'].'/credits', $credit)->assertCreated()->assertJsonPath('data.outstanding_amount', 399600)->json('data');
        $c = $pending['credits'][0];
        $decide = ['operation_id' => (string) Str::uuid(), 'version' => 3, 'consent' => true, 'reason' => 'Confirm return credit'];
        $this->postJson($url.'/invoices/'.$i['id'].'/credits/'.$c['id'].'/accept', $decide)->assertForbidden();
        $this->act($this->lead)->postJson($url.'/invoices/'.$i['id'].'/credits/'.$c['id'].'/accept', $decide)->assertOk()->assertJsonPath('data.outstanding_amount', 359640);
        $this->postJson($url.'/invoices/'.$i['id'].'/credits/'.$c['id'].'/accept', $decide)->assertOk();
        $this->act($this->owner)->postJson($url.'/invoices/'.$i['id'].'/credits', [...$credit, 'operation_id' => (string) Str::uuid(), 'version' => 4, 'base_amount' => 1])->assertUnprocessable();
    }

    public function test_credit_on_paid_invoice_records_liability_and_explicit_refund_not_negative_receivable(): void
    {
        $d = $this->received();
        $this->report($d);
        $p = $this->propose($d, type: 'credit');
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();
        $url = $this->billing($d);
        $i = $this->act($this->owner)->postJson($url.'/invoices', $this->invoiceBody($d))->assertCreated()->json('data');
        $i = $this->acceptInvoice($d, $i);
        $this->act($this->owner)->postJson($url.'/invoices/'.$i['id'].'/payments', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'kind' => 'collection', 'method' => 'cash', 'amount' => 399600, 'reference' => 'COLLECT-1', 'paid_on' => '2026-11-03', 'verified' => true, 'note' => 'Verified manually'])->assertCreated();
        $c = $this->postJson($url.'/invoices/'.$i['id'].'/credits', ['operation_id' => (string) Str::uuid(), 'version' => 3, 'case_id' => $p['case']['id'], 'base_amount' => 54000, 'tax_amount' => 5940, 'reason' => 'Two damaged one missing, agreed credit'])->assertCreated()->json('data.credits.0');
        $this->act($this->lead)->postJson($url.'/invoices/'.$i['id'].'/credits/'.$c['id'].'/accept', ['operation_id' => (string) Str::uuid(), 'version' => 4, 'consent' => true, 'reason' => 'Confirm'])->assertOk()->assertJsonPath('data.customer_credit_amount', 59940)->assertJsonPath('data.outstanding_amount', 0);
        $this->act($this->owner)->postJson($url.'/invoices/'.$i['id'].'/payments', ['operation_id' => (string) Str::uuid(), 'version' => 5, 'kind' => 'credit_refund', 'method' => 'bank_transfer', 'amount' => 59940, 'reference' => 'REFUND-1', 'paid_on' => '2026-11-03', 'verified' => true, 'note' => 'Actual manual outgoing checked'])->assertCreated()->assertJsonPath('data.signed_balance', 0);
    }

    public function test_roles_scopes_required_terms_good_receipt_basis_and_rejected_invoice_release(): void
    {
        $d = $this->received();
        $url = $this->billing($d);
        $b = $this->invoiceBody($d, 'good_received');
        foreach ([$this->cashier, $this->warehouse, $this->manager, $this->outsider] as $u) {
            $this->act($u)->getJson($url)->assertForbidden();
            $this->postJson($url.'/invoices', $b)->assertForbidden();
        }
        $this->act($this->lead)->postJson($url.'/invoices', $b)->assertForbidden();
        $this->act($this->owner)->postJson($url.'/invoices', array_diff_key($b, ['tax_basis_points' => 1]))->assertUnprocessable();
        $i = $this->postJson($url.'/invoices', $b)->assertCreated()->assertJsonPath('data.subtotal', 306000)->json('data');
        $this->act($this->lead)->postJson($url.'/invoices/'.$i['id'].'/reject', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Different agreed due date'])->assertOk();
        $this->act($this->owner)->postJson($url.'/invoices', [...$b, 'operation_id' => (string) Str::uuid()])->assertCreated();
        $this->expectException(\RuntimeException::class);
        $m = require database_path('migrations/2026_11_03_000015_create_billing_documents.php');
        $m->down();
    }

    public function test_consignment_requires_signed_contract_accepted_sold_report_and_no_double_billing(): void
    {
        $d = $this->received();
        $url = $this->billing($d);
        $contract = PartnershipContract::create(['organization_id' => $this->center->id, 'partner_id' => $this->partner->id, 'created_by' => $this->owner->id, 'title' => 'Consignment agreed', 'mode' => 'consignment', 'status' => 'accepted', 'version' => 3, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'terms' => 'Terms', 'brand_terms' => 'Brand', 'data_scope' => 'Manual sold reports only', 'facilities' => 'None']);
        $b = ['operation_id' => (string) Str::uuid(), 'contract_id' => $contract->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-03', 'note' => 'Actual 9 pieces sold', 'lines' => [['order_line_id' => $d['order']['lines'][0]['id'], 'quantity_pieces' => 9]]];
        $report = $this->act($this->lead)->postJson($url.'/reports', $b)->assertCreated()->assertJsonPath('data.lines.0.line_total', 20250)->json('data');
        $this->postJson($url.'/reports', [...$b, 'operation_id' => (string) Str::uuid(), 'lines' => [['order_line_id' => $d['order']['lines'][0]['id'], 'quantity_pieces' => 136]]])->assertUnprocessable();
        $this->act($this->owner)->postJson($url.'/invoices', $this->invoiceBody($d))->assertConflict();
        $ib = [...$this->invoiceBody($d), 'mode' => 'consignment', 'basis' => 'consignment_report', 'report_id' => $report['id'], 'tax_basis_points' => 0];
        $this->postJson($url.'/invoices', $ib)->assertNotFound();
        $this->postJson($url.'/reports/'.$report['id'].'/accept', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'consent' => true, 'reason' => 'Actual sold quantities reviewed'])->assertOk();
        $i = $this->postJson($url.'/invoices', $ib)->assertCreated()->assertJsonPath('data.total_amount', 20250)->json('data');
        $this->acceptInvoice($d, $i);
        $this->act($this->owner)->postJson($url.'/invoices', [...$ib, 'operation_id' => (string) Str::uuid()])->assertConflict();
    }
}
