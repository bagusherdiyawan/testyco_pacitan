<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Procurement\Infrastructure\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomaticAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private User $owner;

    private User $reviewer;

    private Product $product;

    private array $period;

    private array $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9));
        $this->org = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'Produksi']);
        $this->owner = $this->user('owner');
        $this->reviewer = $this->user('admin');
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'D', 'name' => 'Dimsum', 'condition' => 'frozen', 'pack_size' => 1, 'portion_size' => 1, 'retail_price' => 100, 'wholesale_price' => 90]);
        Sanctum::actingAs($this->owner);
        $this->period = $this->cmd('accounting_period', ['name' => 'November', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'note' => 'Disetujui']);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $this->org->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function url(string $action): string
    {
        return '/api/v1/organizations/'.$this->org->id.'/erp/'.$action;
    }

    private function cmd(string $action, array $v): array
    {
        return $this->postJson($this->url($action), ['operation_id' => (string) Str::uuid(), ...$v])->assertCreated()->json('data');
    }

    private function activate(): void
    {
        $v = ['note' => 'Standing authority: mappings reviewed, opening balances reconciled explicitly'];
        foreach (AutomaticPosting::ROLES as $role => [$label,$kind,$normal]) {
            $a = $this->cmd('account', ['code' => $role, 'name' => $label, 'kind' => $kind, 'normal_balance' => $normal]);
            $v[$role.'_account'] = $a['id'];
        }
        $this->policy = $this->cmd('posting_policy', $v);
        $d = ['operation_id' => (string) Str::uuid(), 'id' => $this->policy['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Akun/bukti sesuai'];
        $this->postJson($this->url('posting_decide'), $d)->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('posting_decide'), $d)->assertCreated();
        $this->postJson($this->url('posting_decide'), $d)->assertCreated();
        Sanctum::actingAs($this->owner);
    }

    private function producedBatch(): InventoryBatch
    {
        $m = $this->cmd('material', ['code' => 'EGG', 'name' => 'Telur', 'unit' => 'pcs', 'minimum' => '0', 'expiry_required' => false, 'fefo_required' => false]);
        $l = $this->cmd('receive', ['source' => 'opening', 'store_id' => $this->store->id, 'material_id' => $m['id'], 'quantity' => '3', 'opening_value' => 10, 'batch_code' => 'RAW', 'received_on' => '2026-11-09', 'evidence' => 'Bukti saldo awal', 'note' => 'Ditimbang']);
        $l = $this->cmd('material_quality', ['id' => $l['id'], 'version' => 1, 'decision' => 'release', 'quantity' => '3', 'note' => 'Lolos']);
        $r = $this->cmd('recipe', ['product_id' => $this->product->id, 'title' => 'Resep', 'output_packs' => 3, 'ingredients' => [$m['id'] => '3'], 'instructions' => 'Sesuai SOP']);
        $p = $this->cmd('production', ['store_id' => $this->store->id, 'recipe_id' => $r['id'], 'recipe_versions' => [$r['id'] => 1], 'number' => 'P-1', 'runs' => 1, 'note' => 'Aktual']);
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $p['id'], 'version' => 1, 'allocations' => [$l['id'] => '3'], 'allocation_revisions' => [$l['id'] => 2], 'batch_code' => 'FINISHED', 'produced_on' => '2026-11-09', 'expires_on' => '2026-12-01', 'good_packs' => 3, 'failed_pieces' => 0, 'labor_cost' => 0, 'overhead_cost' => 0, 'cost_allocation' => 'all_to_good_output', 'cost_evidence' => 'Biaya telur10', 'note' => 'Aktual'];
        $done = $this->postJson($this->url('complete'), $v)->assertCreated()->json('data');
        $this->postJson($this->url('complete'), $v)->assertCreated();
        $this->cmd('production_quality', ['id' => $p['id'], 'version' => 2, 'stock_revision' => 1, 'decision' => 'release', 'note' => 'Owner memeriksa']);

        return InventoryBatch::findOrFail($done['output_batch_id']);
    }

    private function sale(InventoryBatch $b, int $qty = 1): array
    {
        $shift = DB::table('cash_shifts')->where('store_id', $this->store->id)->where('cashier_id', $this->owner->id)->where('status', 'open')->first();
        if (! $shift) {
            $s = $this->postJson('/api/v1/stores/'.$this->store->id.'/shifts', ['operation_id' => (string) Str::uuid(), 'device_name' => 'Kasir', 'opening_cash' => 0])->assertCreated()->json('data');
            $shift = (object) $s;
        }
        $intent = $this->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift->id, 'lines' => [['product_id' => $this->product->id, 'unit' => 'pack', 'quantity' => $qty, 'allocations' => [['batch_id' => $b->id, 'bucket' => 'usable', 'quantity' => $qty]]]]])->assertCreated()->json('data');
        $v = ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 100 * $qty];
        $sale = $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', $v)->assertOk()->json('data');
        $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', $v)->assertOk();

        return $sale;
    }

    public function test_material_production_three_sales_conserve_exact_cost_and_post_once(): void
    {
        $this->activate();
        $b = $this->producedBatch();
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 3, 'remaining_value' => 10]);
        foreach ([3, 3, 4] as $cost) {
            $s = $this->sale($b);
            $e = DB::table('accounting_events')->where('source_type', 'pos_sale')->where('source_id', $s['id'])->firstOrFail();
            $this->assertSame($cost, collect(json_decode($e->terms, true))->first(fn ($term) => $term[0] === 'cogs')[2]);
        }
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 0, 'remaining_value' => 0]);
        $this->assertSame(6, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $this->assertDatabaseCount('accounting_journals', 6);
        foreach (DB::table('accounting_journals')->get() as $j) {
            $lines = collect(json_decode($j->lines, true));
            $this->assertSame($lines->sum('debit'), $lines->sum('credit'));
        }
        $this->getJson($this->url('context').'?section=trial_balance&period_id='.$this->period['id'])->assertOk()->assertSee('aset Rp300')->assertSee('neto akun beban periode Rp10');
    }

    public function test_period_close_blocks_pending_and_closed_source_retry_keeps_original_date_mapping(): void
    {
        $this->activate();
        $this->producedBatch();
        $p = DB::table('accounting_periods')->find($this->period['id']);
        $this->postJson($this->url('period_status'), ['operation_id' => (string) Str::uuid(), 'id' => $p->id, 'version' => $p->version, 'decision' => 'close', 'note' => 'Tutup'])->assertConflict();
        DB::table('accounting_periods')->where('id', $p->id)->update(['status' => 'closed']);
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $e = DB::table('accounting_events')->where('status', 'review_required')->firstOrFail();
        DB::table('accounting_periods')->where('id', $p->id)->update(['status' => 'open']);
        $this->cmd('posting_retry', ['id' => $e->id, 'version' => $e->version, 'note' => 'Periode asal dibuka']);
        $this->assertTrue(app(AutomaticPosting::class)->post($e->id));
        $this->assertDatabaseHas('accounting_journals', ['posted_on' => '2026-11-09']);
    }

    public function test_unknown_cost_never_posts_zero_and_stock_rollback_also_rolls_back_cost(): void
    {
        $this->activate();
        $r = DB::transaction(fn () => app(StockLedger::class)->credit($this->org->id, $this->store->id, $this->product->toArray(), 'UNKNOWN', '2026-12-01', 3, $this->owner->id, 'intake', 'fixture', 'usable'));
        $b = InventoryBatch::findOrFail($r['id']);
        $s = $this->sale($b);
        app(AutomaticPosting::class)->drain();
        $this->assertDatabaseMissing('accounting_events', ['source_type' => 'pos_sale', 'source_id' => $s['id'], 'status' => 'posted']);
        $e = DB::table('accounting_events')->where('source_type', 'pos_sale')->firstOrFail();
        $this->cmd('posting_suspend', ['id' => $this->policy['id'], 'version' => 2, 'note' => 'Rekonsiliasi']);
        $this->postJson($this->url('posting_retry'), ['operation_id' => (string) Str::uuid(), 'id' => $e->id, 'version' => $e->version, 'note' => 'Tidak boleh bypass'])->assertConflict();
        $count = DB::table('inventory_cost_entries')->count();
        try {
            DB::transaction(function () use ($b) {
                app(StockLedger::class)->apply($b, $this->owner->id, ['usable' => -1], 'pos_sale', 'rollback');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertDatabaseCount('inventory_cost_entries', $count);
        $this->assertSame(2, $b->fresh()->usable_pieces);
    }

    public function test_opening_valuation_requires_other_manager_exact_revision_and_cannot_overwrite(): void
    {
        $this->activate();
        $r = DB::transaction(fn () => app(StockLedger::class)->credit($this->org->id, $this->store->id, $this->product->toArray(), 'OPEN', '2026-12-01', 3, $this->owner->id, 'intake', 'fixture', 'usable'));
        $p = $this->cmd('value_propose', ['batch_id' => $r['id'], 'stock_revision' => 1, 'amount' => 10, 'note' => 'Bukti fisik nilai10']);
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Bukti cocok'];
        $this->postJson($this->url('value_decide'), $v)->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('value_decide'), $v)->assertCreated();
        $this->postJson($this->url('value_decide'), $v)->assertCreated();
        $this->postJson($this->url('value_propose'), ['operation_id' => (string) Str::uuid(), 'batch_id' => $r['id'], 'stock_revision' => 1, 'amount' => 99, 'note' => 'Timpa'])->assertConflict();
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $r['id'], 'remaining_value' => 10]);
    }

    public function test_partner_finance_is_own_only_and_never_grants_manufacturing_to_partner_or_cashier(): void
    {
        $partner = Organization::create(['name' => 'Mitra', 'type' => 'partner']);
        DB::table('organization_user')->insert(['organization_id' => $partner->id, 'user_id' => $this->owner->id, 'role' => 'partner_lead']);
        $url = '/api/v1/organizations/'.$partner->id.'/erp/';
        $this->getJson($url.'context?section=posting')->assertOk()->assertJsonPath('allowed_sections.0', 'accounts');
        $this->getJson($url.'context?section=materials')->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $this->getJson($url.'context?section=posting')->assertForbidden();
        Sanctum::actingAs($this->user('cashier'));
        $this->getJson($this->url('context').'?section=posting_events')->assertForbidden();
    }

    public function test_no_policy_means_no_historical_import_and_automatic_journal_cannot_be_manually_reversed(): void
    {
        $this->producedBatch();
        $this->assertDatabaseCount('accounting_events', 0);
        $this->activate();
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $b = InventoryBatch::firstOrFail();
        $this->sale($b);
        app(AutomaticPosting::class)->drain();
        $j = DB::table('accounting_journals')->firstOrFail();
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('journal_reverse'), ['operation_id' => (string) Str::uuid(), 'id' => $j->id, 'version' => 1, 'period_id' => $j->period_id, 'number' => 'REV', 'posted_on' => '2026-11-09', 'evidence' => 'Tidak boleh', 'note' => 'Tidak boleh'])->assertConflict();
    }

    private function refundSale(array $sale, int $qty, string $disposition): array
    {
        $shift = DB::table('cash_shifts')->find($sale['cash_shift_id']);
        $v = ['version' => $sale['version'], 'shift_id' => $shift->id, 'shift_version' => $shift->version, 'stock_disposition' => $disposition, 'reference' => 'REF-'.Str::ulid(), 'reason' => 'Uang dan barang diperiksa', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => $qty]]];
        $url = '/api/v1/sales/'.$sale['id'].'/refunds';
        $preview = $this->postJson($url.'/preview', $v)->assertOk()->json('data');
        $v = [...$v, 'operation_id' => (string) Str::uuid(), 'verified' => true, 'review_hash' => $preview['review_hash']];
        $r = $this->postJson($url, $v)->assertCreated()->json('data');
        $this->postJson($url, $v)->assertCreated();

        return $r;
    }

    public function test_partial_refund_uses_original_cost_slice_and_no_stock_refund_keeps_cogs(): void
    {
        $this->activate();
        $b = $this->producedBatch();
        $s = $this->sale($b, 3);
        $this->refundSale($s, 1, 'no_stock');
        $s['version'] = 2;
        $r = $this->refundSale($s, 1, 'quarantine');
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 1, 'remaining_value' => 3]);
        $source = $r['refund']['lines'][0]['allocations'][0]['source_id'];
        $this->assertDatabaseHas('inventory_cost_entries', ['source_type' => 'sale_refund', 'source_id' => $source, 'value_delta' => 3]);
        $s['version'] = 3;
        $this->refundSale($s, 1, 'quarantine');
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 2, 'remaining_value' => 7]);
        $events = DB::table('accounting_events')->where('source_type', 'pos_refund')->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertCount(1, json_decode($events[0]->terms, true));
        $this->assertSame(7, app(AutomaticPosting::class)->drain());
        $this->assertDatabaseCount('sale_refunds', 3);
    }

    public function test_supplier_receipt_payment_return_and_refund_create_balanced_actual_value_events(): void
    {
        $this->activate();
        $m = $this->cmd('material', ['code' => 'A', 'name' => 'Bahan', 'unit' => 'pcs', 'minimum' => '0', 'expiry_required' => false, 'fefo_required' => false]);
        $supplier = $this->cmd('supplier', ['name' => 'Supplier', 'contact' => 'Kontak', 'address' => 'Alamat']);
        $po = $this->cmd('purchase', ['store_id' => $this->store->id, 'supplier_id' => $supplier['id'], 'number' => 'PO-1', 'ordered_on' => '2026-11-09', 'due_on' => '2026-11-30', 'quantities' => [$m['id'] => '3'], 'amounts' => [$m['id'] => 10], 'note' => 'Disepakati']);
        $this->cmd('purchase_decide', ['id' => $po['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Setuju']);
        $lot = $this->cmd('receive', ['source' => 'purchase', 'purchase_id' => $po['id'], 'version' => 2, 'store_id' => $this->store->id, 'material_id' => $m['id'], 'quantity' => '3', 'batch_code' => 'POLOT', 'received_on' => '2026-11-09', 'evidence' => 'Surat jalan', 'note' => 'Timbang']);
        $this->cmd('payment', ['id' => $po['id'], 'version' => 3, 'amount' => 10, 'method' => 'bank_transfer', 'reference' => 'PAY1', 'paid_on' => '2026-11-09', 'verified' => true, 'note' => 'Bukti bank']);
        $this->cmd('material_quality', ['id' => $lot['id'], 'version' => 1, 'decision' => 'return', 'quantity' => '3', 'bucket' => 'quarantined', 'supplier_credit' => 8, 'verified' => true, 'note' => 'Supplier setuju8; rugi2']);
        $this->cmd('payment', ['id' => $po['id'], 'version' => 5, 'kind' => 'supplier_refund', 'amount' => 8, 'method' => 'bank_transfer', 'reference' => 'REF1', 'paid_on' => '2026-11-09', 'verified' => true, 'note' => 'Refund diterima']);
        $this->assertSame(4, app(AutomaticPosting::class)->drain());
        $this->getJson($this->url('context').'?section=trial_balance&period_id='.$this->period['id'])->assertOk()->assertSee('neto akun beban periode Rp2');
        $this->getJson($this->url('context').'?section=purchases')->assertOk()->assertSee('PO-1');
    }

    public function test_exception_requires_reviewed_own_manual_journal_and_never_silently_retries(): void
    {
        $this->activate();
        DB::transaction(fn () => app(AutomaticPosting::class)->record($this->org->id, $this->owner->id, 'distribution', 'EXCEPTION', '2026-11-09', [], 'Rekonsiliasi', 'Ownership belum disepakati'));
        $e = DB::table('accounting_events')->firstOrFail();
        $mapping = json_decode(DB::table('accounting_policies')->find($this->policy['id'])->mapping, true);
        $j = $this->cmd('journal', ['period_id' => $this->period['id'], 'number' => 'RECON-1', 'posted_on' => '2026-11-09', 'debits' => [$mapping['finished']['account_id'] => 10], 'credits' => [$mapping['opening']['account_id'] => 10], 'evidence' => 'Bukti sumber EXCEPTION', 'note' => 'Rekonsiliasi']);
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $e->id, 'version' => 1, 'journal_id' => $j['id'], 'note' => 'Sesuai bukti'];
        $this->postJson($this->url('posting_reconcile'), $v)->assertConflict();
        Sanctum::actingAs($this->reviewer);
        $this->cmd('journal_decide', ['id' => $j['id'], 'version' => 1, 'decision' => 'post', 'note' => 'Diperiksa']);
        $this->postJson($this->url('posting_reconcile'), $v)->assertCreated()->assertJsonPath('data.status', 'reconciled');
        $this->postJson($this->url('posting_reconcile'), $v)->assertCreated();
        $this->assertDatabaseCount('accounting_journals', 1);
    }

    public function test_policy_rejects_foreign_or_wrong_kind_accounts_and_stale_opening_snapshot(): void
    {
        $this->activate();
        $mapping = json_decode(DB::table('accounting_policies')->find($this->policy['id'])->mapping, true);
        $v = ['operation_id' => (string) Str::uuid(), 'note' => 'Reviewed mapping'];
        foreach ($mapping as $role => $account) {
            $v[$role.'_account'] = $account['account_id'];
        }
        $bad = $v;
        $bad['cash_account'] = (string) Str::ulid();
        $this->postJson($this->url('posting_policy'), $bad)->assertNotFound();
        $bad = $v;
        $bad['cogs_account'] = $mapping['sales']['account_id'];
        $this->postJson($this->url('posting_policy'), $bad)->assertUnprocessable();
        $r = DB::transaction(fn () => app(StockLedger::class)->credit($this->org->id, $this->store->id, $this->product->toArray(), 'STALE', '2026-12-01', 3, $this->owner->id, 'intake', 'fixture', 'usable'));
        $p = $this->cmd('value_propose', ['batch_id' => $r['id'], 'stock_revision' => 1, 'amount' => 10, 'note' => 'Counted3']);
        $this->sale(InventoryBatch::findOrFail($r['id']));
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('value_decide'), ['operation_id' => (string) Str::uuid(), 'id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Stale count must fail'])->assertConflict();
        $this->assertDatabaseMissing('inventory_values', ['inventory_batch_id' => $r['id'], 'remaining_value' => 10]);
    }

    public function test_refund_of_pre_activation_sale_is_cutover_review_and_does_not_import_original_sale(): void
    {
        $b = $this->producedBatch();
        $sale = $this->sale($b);
        $this->activate();
        $this->refundSale($sale, 1, 'quarantine');
        $this->assertDatabaseHas('accounting_events', ['source_type' => 'pos_refund', 'status' => 'review_required']);
        $this->assertDatabaseMissing('accounting_events', ['source_type' => 'pos_sale']);
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    public function test_supplier_reversal_requires_other_manager_and_replays_once(): void
    {
        $supplier = $this->cmd('supplier', ['name' => 'Supplier', 'contact' => 'Contact', 'address' => 'Address']);
        $payment = (string) Str::ulid();
        $p = Purchase::create(['organization_id' => $this->org->id, 'store_id' => $this->store->id, 'supplier_id' => $supplier['id'], 'number' => 'REV-PO', 'status' => 'approved', 'version' => 1, 'ordered_on' => '2026-11-09', 'due_on' => '2026-11-30', 'lines' => [], 'total_amount' => 10, 'received_amount' => 10, 'credited_amount' => 0, 'paid_amount' => 10, 'refunded_amount' => 0, 'created_by' => $this->owner->id, 'note' => 'Fixture', 'payments' => [['id' => $payment, 'actor_id' => $this->owner->id, 'kind' => 'payment', 'amount' => 10, 'method' => 'cash', 'reference' => 'PAY1', 'paid_on' => '2026-11-09', 'note' => 'Verified']]]);
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $p->id, 'version' => 1, 'payment_id' => $payment, 'verified' => true, 'note' => 'Wrong matching verified'];
        $this->postJson($this->url('payment_reverse'), $v)->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('payment_reverse'), $v)->assertCreated()->assertJsonPath('data.paid_amount', 0);
        $this->postJson($this->url('payment_reverse'), $v)->assertCreated()->assertJsonPath('data.version', 2);
        $this->postJson($this->url('payment_reverse'), [...$v, 'operation_id' => (string) Str::uuid(), 'version' => 2])->assertConflict();
    }

    public function test_supplier_reversal_preserves_original_policy_and_exact_journal_terms(): void
    {
        $this->activate();
        $supplier = $this->cmd('supplier', ['name' => 'Supplier', 'contact' => 'Contact', 'address' => 'Address']);
        $payment = (string) Str::ulid();
        $p = Purchase::create(['organization_id' => $this->org->id, 'store_id' => $this->store->id, 'supplier_id' => $supplier['id'], 'number' => 'REV-GL', 'status' => 'approved', 'version' => 1, 'ordered_on' => '2026-11-09', 'due_on' => '2026-11-30', 'lines' => [], 'total_amount' => 10, 'received_amount' => 10, 'credited_amount' => 0, 'paid_amount' => 10, 'refunded_amount' => 0, 'created_by' => $this->owner->id, 'note' => 'Fixture', 'payments' => [['id' => $payment, 'actor_id' => $this->owner->id, 'kind' => 'payment', 'amount' => 10, 'method' => 'bank_transfer', 'reference' => 'PAY-GL', 'paid_on' => '2026-11-09', 'note' => 'Verified']]]);
        DB::transaction(fn () => app(AutomaticPosting::class)->record($this->org->id, $this->owner->id, 'supplier_payment', $payment, '2026-11-09', [['payable', 'bank', 10]], 'Verified payment'));
        $this->cmd('posting_suspend', ['id' => $this->policy['id'], 'version' => 2, 'note' => 'Suspend new sources']);
        Sanctum::actingAs($this->reviewer);
        $this->cmd('payment_reverse', ['id' => $p->id, 'version' => 1, 'payment_id' => $payment, 'verified' => true, 'note' => 'Wrong matching verified']);
        $reverse = DB::table('accounting_events')->where('source_type', 'supplier_payment_reverse')->firstOrFail();
        $this->assertSame($this->policy['id'], $reverse->policy_id);
        $this->assertSame([['bank', 'payable', 10]], json_decode($reverse->terms, true));
        $this->assertSame(2, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }
}
