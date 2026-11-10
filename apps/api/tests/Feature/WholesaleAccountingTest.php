<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Accounting\Application\WholesalePolicy;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WholesaleAccountingTest extends TestCase
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

    private function accepted(array $d, int $discount = 0, int $shipping = 0): array
    {
        $i = $this->act($this->owner)->postJson($this->billing($d).'/invoices', [...$this->invoiceBody($d), 'discount_amount' => $discount, 'shipping_amount' => $shipping])->assertCreated()->json('data');

        return $this->acceptInvoice($d, $i);
    }

    private function payment(array $d, array $i, int $amount, string $kind = 'collection', string $method = 'bank_transfer'): array
    {
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $i['version'], 'kind' => $kind, 'method' => $method, 'amount' => $amount, 'reference' => 'PAY-'.Str::ulid(), 'paid_on' => '2026-11-09', 'verified' => true, 'note' => 'Verified statement'];
        $url = $this->billing($d).'/invoices/'.$i['id'].'/payments';
        $r = $this->act($this->owner)->postJson($url, $v)->assertCreated()->json('data');
        $this->postJson($url, $v)->assertCreated();

        return $r;
    }

    private function event(string $org, string $type, string $source): object
    {
        return DB::table('accounting_events')->where('organization_id', $org)->where('source_type', $type)->where('source_id', $source)->firstOrFail();
    }

    private function terms(object $event): array
    {
        return json_decode($event->terms, true);
    }

    public function test_two_party_accepted_invoice_and_payment_post_once_without_stock_or_drawer_changes(): void
    {
        $d = $this->received();
        $seller = $this->configure($this->center, $this->owner);
        $buyer = $this->configure($this->partner, $this->lead);
        $this->assertDatabaseHas('accounting_wholesale_policies', ['organization_id' => $this->partner->id, 'ownership_basis' => 'receipt_confirmed', 'provisional_basis' => 'approved_order', 'invoice_variance_basis' => 'remaining_stock_and_historical_cogs']);
        $stock = DB::table('stock_entries')->count();
        $cost = DB::table('inventory_cost_entries')->count();
        $i = $this->accepted($d, 1000, 2000);
        $this->assertSame(400710, $i['total_amount']);
        $this->assertSame([['trade_receivable', 'wholesale_sales', 359000], ['wholesale_discount', 'wholesale_sales', 1000], ['trade_receivable', 'shipping_revenue', 2000], ['trade_receivable', 'output_tax', 39710]], $this->terms($this->event($this->center->id, 'wholesale_invoice', $i['id'])));
        $this->assertSame([['purchase_clearing', 'trade_payable', 359000], ['freight_expense', 'trade_payable', 2000], ['input_tax', 'trade_payable', 39710]], $this->terms($this->event($this->partner->id, 'wholesale_invoice', $i['id'])));
        $paid = $this->payment($d, $i, 100000);
        $pid = $paid['payments'][0]['id'];
        $this->assertSame([['bank', 'trade_receivable', 100000]], $this->terms($this->event($this->center->id, 'wholesale_payment', $pid)));
        $this->assertSame([['trade_payable', 'bank', 100000]], $this->terms($this->event($this->partner->id, 'wholesale_payment', $pid)));
        $this->assertSame(4, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $this->assertDatabaseCount('accounting_journals', 4);
        $this->assertDatabaseCount('stock_entries', $stock);
        $this->assertDatabaseCount('inventory_cost_entries', $cost);
        $this->assertDatabaseCount('cash_movements', 0);
        foreach (DB::table('accounting_journals')->get() as $j) {
            $lines = collect(json_decode($j->lines, true));
            $this->assertSame($lines->sum('debit'), $lines->sum('credit'));
        }
        $this->act($this->owner)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=posting_events')->assertForbidden();
        $this->act($this->lead)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=posting')->assertOk()->assertSee('Pajak masukan dapat dikreditkan');
    }

    public function test_payment_reverse_uses_original_policy_and_exact_opposite_after_mapping_is_suspended(): void
    {
        $d = $this->received();
        $s = $this->configure($this->center, $this->owner);
        $b = $this->configure($this->partner, $this->lead);
        $i = $this->accepted($d);
        $paid = $this->payment($d, $i, 100000);
        $pid = $paid['payments'][0]['id'];
        $this->erp($this->center, 'posting_suspend', ['id' => $s['policy']['id'], 'version' => 2, 'note' => 'Stop new documents'], $this->owner);
        $url = $this->billing($d).'/invoices/'.$i['id'].'/payments/'.$pid.'/reverse';
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $paid['version'], 'reason' => 'Wrong matching'];
        $this->act($this->owner)->postJson($url, $v)->assertOk();
        $this->postJson($url, $v)->assertOk();
        foreach ([$this->center->id, $this->partner->id] as $org) {
            $original = $this->event($org, 'wholesale_payment', $pid);
            $reverse = $this->event($org, 'wholesale_payment_reverse', $pid);
            $this->assertSame($original->policy_id, $reverse->policy_id);
            $this->assertSame(array_map(fn ($t) => [$t[1], $t[0], $t[2]], $this->terms($original)), $this->terms($reverse));
        }
        $this->assertSame(6, app(AutomaticPosting::class)->drain());
    }

    public function test_irrecoverable_tax_and_capitalized_freight_use_explicit_purchase_clearing(): void
    {
        $d = $this->received();
        $this->configure($this->partner, $this->lead, 'clearing', 'clearing');
        $i = $this->accepted($d, 1000, 2000);
        $e = $this->event($this->partner->id, 'wholesale_invoice', $i['id']);
        $this->assertSame([['purchase_clearing', 'trade_payable', 359000], ['purchase_clearing', 'trade_payable', 2000], ['purchase_clearing', 'trade_payable', 39710]], $this->terms($e));
        $this->assertDatabaseCount('accounting_events', 1);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
    }

    public function test_pending_rejected_or_base_policy_only_invoice_never_posts_wholesale(): void
    {
        $d = $this->received();
        $config = $this->configure($this->center, $this->owner, wholesale: false);
        $this->erp($this->center, 'wholesale_policy', $config['body'], $this->owner); // still draft
        $i = $this->act($this->owner)->postJson($this->billing($d).'/invoices', $this->invoiceBody($d))->assertCreated()->json('data');
        $this->assertDatabaseCount('accounting_events', 0);
        $this->act($this->lead)->postJson($this->billing($d).'/invoices/'.$i['id'].'/reject', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Rejected'])->assertOk();
        $this->accepted($d);
        $this->assertDatabaseCount('accounting_events', 0);
    }

    public function test_acceptance_and_both_org_events_are_atomic_and_same_uuid_recovers_after_failure(): void
    {
        $d = $this->received();
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead);
        $i = $this->act($this->owner)->postJson($this->billing($d).'/invoices', $this->invoiceBody($d))->assertCreated()->json('data');
        $org = $this->partner->id;
        DB::unprepared("CREATE TRIGGER fail_buyer_event BEFORE INSERT ON accounting_events WHEN NEW.organization_id = '$org' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'consent' => true, 'reason' => 'Explicit consent'];
        $url = $this->billing($d).'/invoices/'.$i['id'].'/accept';
        $this->act($this->lead)->postJson($url, $v)->assertStatus(500);
        $this->assertDatabaseCount('accounting_events', 0);
        $this->assertDatabaseHas('invoices', ['id' => $i['id'], 'status' => 'pending', 'version' => 1]);
        DB::unprepared('DROP TRIGGER fail_buyer_event');
        $this->postJson($url, $v)->assertOk();
        $this->postJson($url, $v)->assertOk();
        $this->assertDatabaseCount('accounting_events', 2);
    }

    public function test_missing_original_period_requires_review_and_retry_never_moves_source_date(): void
    {
        $d = $this->received();
        $c = $this->configure($this->center, $this->owner);
        $i = $this->accepted($d);
        DB::table('accounting_periods')->where('id', $c['period']['id'])->update(['status' => 'closed']);
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $e = $this->event($this->center->id, 'wholesale_invoice', $i['id']);
        $this->assertSame('review_required', $e->status);
        DB::table('accounting_periods')->where('id', $c['period']['id'])->update(['status' => 'open']);
        $this->erp($this->center, 'posting_retry', ['id' => $e->id, 'version' => $e->version, 'note' => 'Original period reopened'], $this->owner);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertDatabaseHas('accounting_journals', ['posted_on' => '2026-11-09']);
    }

    public function test_payment_for_old_invoice_is_cutover_review_without_importing_invoice(): void
    {
        $d = $this->received();
        $i = $this->accepted($d);
        $this->configure($this->center, $this->owner);
        $this->payment($d, $i, 1000);
        $this->assertDatabaseCount('accounting_events', 1);
        $this->assertDatabaseHas('accounting_events', ['source_type' => 'wholesale_payment', 'status' => 'review_required']);
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    public function test_credit_and_customer_credit_refund_reverse_finance_without_revaluing_stock(): void
    {
        $d = $this->received();
        $this->report($d);
        $plan = $this->propose($d, type: 'credit');
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$plan['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead);
        $i = $this->accepted($d);
        $i = $this->payment($d, $i, 399600, 'collection', 'cash');
        $stock = DB::table('stock_entries')->count();
        $url = $this->billing($d).'/invoices/'.$i['id'];
        $proposed = $this->act($this->owner)->postJson($url.'/credits', ['operation_id' => (string) Str::uuid(), 'version' => $i['version'], 'case_id' => $plan['case']['id'], 'base_amount' => 54000, 'tax_amount' => 5940, 'reason' => 'Two damaged and one missing'])->assertCreated()->json('data');
        $credit = $proposed['credits'][0];
        $this->assertDatabaseCount('accounting_events', 4);
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $proposed['version'], 'consent' => true, 'reason' => 'Accepted credit'];
        $i = $this->act($this->lead)->postJson($url.'/credits/'.$credit['id'].'/accept', $v)->assertOk()->json('data');
        $this->postJson($url.'/credits/'.$credit['id'].'/accept', $v)->assertOk();
        $this->assertSame([['wholesale_returns', 'trade_receivable', 54000], ['output_tax', 'trade_receivable', 5940]], $this->terms($this->event($this->center->id, 'wholesale_credit', $credit['id'])));
        $this->assertSame([['trade_payable', 'purchase_clearing', 54000], ['trade_payable', 'input_tax', 5940]], $this->terms($this->event($this->partner->id, 'wholesale_credit', $credit['id'])));
        $this->payment($d, $i, 59940, 'credit_refund');
        $this->assertSame(8, app(AutomaticPosting::class)->drain());
        $this->assertDatabaseCount('stock_entries', $stock);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_wholesale_policy_requires_explicit_tax_freight_own_accounts_and_unchanged_base(): void
    {
        $d = $this->received();
        $c = $this->configure($this->center, $this->owner, wholesale: false);
        $url = '/api/v1/organizations/'.$this->center->id.'/erp/';
        $v = ['operation_id' => (string) Str::uuid(), ...$c['body']];
        unset($v['tax_treatment']);
        $this->act($this->owner)->postJson($url.'wholesale_policy', $v)->assertUnprocessable();
        $v = ['operation_id' => (string) Str::uuid(), ...$c['body']];
        $v['trade_payable_account'] = (string) Str::ulid();
        $this->postJson($url.'wholesale_policy', $v)->assertNotFound();
        $foreign = $this->erp($this->partner, 'account', ['code' => 'OTHER-AP', 'name' => 'Foreign payable', 'kind' => 'liability', 'normal_balance' => 'credit'], $this->lead);
        $foreignBody = ['operation_id' => (string) Str::uuid(), ...$c['body'], 'trade_payable_account' => $foreign['id']];
        $this->act($this->owner)->postJson($url.'wholesale_policy', $foreignBody)->assertNotFound();
        $proposal = $this->erp($this->center, 'wholesale_policy', $c['body'], $this->owner);
        $base = DB::table('accounting_policies')->find($c['base']['id']);
        $body = ['note' => 'Replace base'];
        foreach (json_decode($base->mapping, true) as $role => $a) {
            $body[$role.'_account'] = $a['account_id'];
        }
        $new = $this->erp($this->center, 'posting_policy', $body, $this->owner);
        $this->erp($this->center, 'posting_decide', ['id' => $new['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Reviewed replacement'], $c['reviewer']);
        $this->act($c['reviewer'])->postJson($url.'posting_decide', ['operation_id' => (string) Str::uuid(), 'id' => $proposal['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Stale baseline'])->assertConflict();
        $this->act($this->cashier)->postJson($url.'wholesale_policy', ['operation_id' => (string) Str::uuid(), ...$c['body']])->assertForbidden();
        $this->act($this->outsider)->postJson($url.'wholesale_policy', ['operation_id' => (string) Str::uuid(), ...$c['body']])->assertForbidden();
    }

    public function test_replaced_tax_mapping_does_not_change_existing_invoice_adjustments_or_silently_disable_wholesale(): void
    {
        $d = $this->received();
        $c = $this->configure($this->partner, $this->lead, 'recoverable', 'expense');
        $i = $this->accepted($d);
        $body = [...$c['body'], 'base_policy_id' => $c['policy']['id'], 'base_policy_version' => 2, 'tax_treatment' => 'clearing', 'freight_treatment' => 'clearing'];
        $new = $this->erp($this->partner, 'wholesale_policy', $body, $this->lead);
        $this->erp($this->partner, 'posting_decide', ['id' => $new['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Reviewed new tax treatment'], $c['reviewer']);
        $paid = $this->payment($d, $i, 1000);
        $event = $this->event($this->partner->id, 'wholesale_payment', $paid['payments'][0]['id']);
        $this->assertSame($c['policy']['id'], $event->policy_id);
        $old = json_decode(DB::table('accounting_policies')->find($c['policy']['id'])->mapping, true);
        $v = ['operation_id' => (string) Str::uuid(), 'note' => 'Cannot silently drop wholesale'];
        foreach (AutomaticPosting::ROLES as $role => $_) {
            $v[$role.'_account'] = $old[$role]['account_id'];
        }
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->partner->id.'/erp/posting_policy', $v)->assertConflict();
        $context = $this->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=posting')->assertOk()->json();
        $this->assertNotContains('posting_policy', array_column($context['forms'], 'operation'));
        $this->assertContains('wholesale_policy', array_column($context['forms'], 'operation'));
    }

    public function test_consignment_invoice_is_visible_review_case_instead_of_own_stock_or_revenue_assumption(): void
    {
        $d = $this->received();
        $url = $this->billing($d);
        $contract = PartnershipContract::create(['organization_id' => $this->center->id, 'partner_id' => $this->partner->id, 'created_by' => $this->owner->id, 'title' => 'Consignment agreed', 'mode' => 'consignment', 'status' => 'accepted', 'version' => 3, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'terms' => 'Terms', 'brand_terms' => 'Brand', 'data_scope' => 'Manual sold reports', 'facilities' => 'None']);
        $report = $this->act($this->lead)->postJson($url.'/reports', ['operation_id' => (string) Str::uuid(), 'contract_id' => $contract->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-09', 'note' => 'Actual9pieces', 'lines' => [['order_line_id' => $d['order']['lines'][0]['id'], 'quantity_pieces' => 9]]])->assertCreated()->json('data');
        $this->act($this->owner)->postJson($url.'/reports/'.$report['id'].'/accept', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'consent' => true, 'reason' => 'Actual sold reviewed'])->assertOk();
        $this->configure($this->center, $this->owner);
        $i = $this->act($this->owner)->postJson($url.'/invoices', [...$this->invoiceBody($d), 'mode' => 'consignment', 'basis' => 'consignment_report', 'report_id' => $report['id'], 'tax_basis_points' => 0])->assertCreated()->json('data');
        $this->acceptInvoice($d, $i);
        $e = $this->event($this->center->id, 'wholesale_invoice', $i['id']);
        $this->assertSame('review_required', $e->status);
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $this->assertDatabaseCount('accounting_journals', 0);
    }

    public function test_distribution_cost_reader_is_own_finance_only_and_preserves_unknown_cost(): void
    {
        $this->received();
        $before = DB::table('inventory_cost_entries')->count();
        $url = '/api/v1/organizations/'.$this->center->id.'/erp/context?section=distribution_costs';
        $this->act($this->owner)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.value_delta', null)->assertSee('belum diketahui');
        $this->act($this->lead)->getJson($url)->assertForbidden();
        $this->act($this->warehouse)->getJson($url)->assertForbidden();
        $this->assertDatabaseCount('inventory_cost_entries', $before);
        $this->assertDatabaseCount('accounting_events', 0);
    }

    public function test_full_good_receipt_values_buyer_at_order_quote_and_posts_actual_seller_batch_cost(): void
    {
        [$order,$batch] = $this->ready();
        DB::table('inventory_values')->where('inventory_batch_id', $batch['id'])->update(['remaining_value' => 10001]);
        $this->configure($this->center, $this->owner);
        $buyer = $this->configure($this->partner, $this->lead);
        $d = $this->dispatch($order, $batch);
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $d['order']['version'], 'note' => 'Full good receipt', 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 20, 'damaged_packs' => 0]]];
        $url = '/api/v1/shipments/'.$d['shipment']['id'].'/receive';
        $received = $this->act($this->lead)->postJson($url, $v)->assertOk()->json('data');
        $this->postJson($url, $v)->assertOk();
        $target = DB::table('inventory_batches')->where('store_id', $this->target->id)->firstOrFail();
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $target->id, 'quantity' => 160, 'remaining_value' => 360000]);
        $source = $d['shipment']['lines'][0]['id'];
        $this->assertSame([['cogs', 'finished', 10001]], $this->terms($this->event($this->center->id, 'distribution_cogs', $source)));
        $this->assertSame([['finished', 'purchase_clearing', 360000]], $this->terms($this->event($this->partner->id, 'distribution_receipt', $source)));
        $new = $this->erp($this->partner, 'wholesale_policy', [...$buyer['body'], 'base_policy_id' => $buyer['policy']['id'], 'base_policy_version' => 2, 'tax_treatment' => 'clearing'], $this->lead);
        $this->erp($this->partner, 'posting_decide', ['id' => $new['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'New treatment for future receipts'], $buyer['reviewer']);
        $invoice = $this->accepted($received);
        $this->assertSame($buyer['policy']['id'], $this->event($this->partner->id, 'wholesale_invoice', $invoice['id'])->policy_id);
        $this->act($this->lead)->postJson($this->billing($received).'/reports', ['operation_id' => (string) Str::uuid(), 'contract_id' => (string) Str::ulid(), 'period_start' => '2026-11-01', 'period_end' => '2026-11-09', 'note' => 'Cannot silently change ownership', 'lines' => [['order_line_id' => $received['order']['lines'][0]['id'], 'quantity_pieces' => 1]]])->assertConflict();
        $this->assertSame(4, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }
}
