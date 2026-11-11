<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Accounting\Application\ConsignorCosting;
use App\Modules\Commercial\Application\ConsignmentAutomation;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WholesaleScenario;

class ConsignorInventoryTest extends WholesaleScenario
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:00:00Z'));
        $this->supplierProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
        $this->localProduct->update(['pack_size' => 1, 'portion_size' => 1, 'wholesale_price' => 2000]);
    }

    private function erp(string $org, User $u, string $action, array $body): array
    {
        return $this->act($u)->postJson('/api/v1/organizations/'.$org.'/erp/'.$action, ['operation_id' => (string) Str::uuid(), ...$body])->assertCreated()->json('data');
    }

    private function configure(): User
    {
        $reviewer = $this->user($this->center, 'admin');
        $this->erp($this->center->id, $this->owner, 'accounting_period', ['name' => 'November', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'note' => 'Explicit period']);
        $body = ['note' => 'Actual owner stock mapping'];
        foreach (AutomaticPosting::ROLES as $role => [$label,$kind,$normal]) {
            $a = $this->erp($this->center->id, $this->owner, 'account', ['code' => 'OWN-'.$role, 'name' => $label, 'kind' => $kind, 'normal_balance' => $normal]);
            $body[$role.'_account'] = $a['id'];
        }
        $policy = $this->erp($this->center->id, $this->owner, 'posting_policy', $body);
        $this->erp($this->center->id, $reviewer, 'posting_decide', ['id' => $policy['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Other owner finance manager checked']);

        return $reviewer;
    }

    private function agreement(array $order, PartnershipContract $contract): array
    {
        $terms = ['basis' => 'margin', 'supplier_basis_points' => 3000, 'tax_basis_points' => 0, 'loss_policy' => 'zero_floor', 'bundle_basis' => 'reject', 'costs' => [['order_line_id' => $order['lines'][0]['id'], 'cost_per_piece' => 1700, 'evidence_reference' => 'Contractual formula, deliberately distinct from actual owner cost']], 'rounding' => 'floor_magnitude_per_line', 'refund_policy' => 'reverse_proportionally', 'movement_policy' => 'original_receipt_store_only', 'frequency' => 'continuous', 'data_scope' => 'allocated_receipt_totals'];
        $g = $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/commercial-agreements', ['operation_id' => (string) Str::uuid(), 'kind' => 'consignment', 'contract_id' => $contract->id, 'order_id' => $order['id'], 'starts_on' => '2026-11-10', 'ends_on' => '2030-12-31', 'reference' => 'Both parties consent to consignment ownership', 'terms' => $terms])->assertCreated()->json('data');
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Full terms and scoped data sharing reviewed', 'consent' => true, 'sharing_consented' => true];
        $url = '/api/v1/organizations/'.$this->partner->id.'/commercial-agreements/'.$g['id'].'/accept';
        $g = $this->act($this->lead)->postJson($url, $body)->assertOk()->json('data');
        $this->postJson($url, $body)->assertOk();

        return $g;
    }

    private function fixture(int $good = 100, int $bad = 0, ?int $value = 100001, bool $beforeDispatch = false, int $orderQuantity = 100): array
    {
        $reviewer = $this->configure();
        $c = PartnershipContract::create(['organization_id' => $this->center->id, 'partner_id' => $this->partner->id, 'created_by' => $this->owner->id, 'title' => 'Standing consignment', 'mode' => 'consignment', 'status' => 'accepted', 'version' => 3, 'starts_on' => '2026-01-01', 'ends_on' => '2030-12-31', 'terms' => 'Supplier retains physical ownership', 'brand_terms' => 'Brand', 'data_scope' => 'Agreed totals', 'facilities' => 'None']);
        [$order,$stock] = $this->ready($orderQuantity, 100);
        if ($value !== null) {
            $p = $this->erp($this->center->id, $this->owner, 'value_propose', ['batch_id' => $stock['id'], 'stock_revision' => $stock['stock_revision'], 'amount' => $value, 'note' => 'Actual opening proof']);
            $this->erp($this->center->id, $reviewer, 'value_decide', ['id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Independent exact source value']);
        }
        $g = $beforeDispatch ? $this->agreement($order, $c) : null;
        $sent = $this->dispatch($order, $stock, 100);
        $d = $this->act($this->lead)->postJson('/api/v1/shipments/'.$sent['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $sent['order']['version'], 'lines' => [['shipment_line_id' => $sent['shipment']['lines'][0]['id'], 'good_packs' => $good, 'damaged_packs' => $bad, 'condition_note' => 'Actual physical quantity checked']]])->assertOk()->json('data');
        $g ??= $this->agreement($d['order'], $c);

        return [$d, $g, InventoryBatch::where('store_id', $this->target->id)->first(), $stock];
    }

    private function sale(InventoryBatch $b, int $qty): array
    {
        $shift = DB::table('cash_shifts')->where('store_id', $this->target->id)->where('status', 'open')->first();
        if (! $shift) {
            $shift = (object) $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->target->id.'/shifts', ['operation_id' => (string) Str::uuid(), 'device_name' => 'Custody POS', 'opening_cash' => 0])->assertCreated()->json('data');
        }
        $intent = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->target->id.'/sales/intents', ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift->id, 'lines' => [['product_id' => $this->localProduct->id, 'unit' => 'pack', 'quantity' => $qty, 'allocations' => [['batch_id' => $b->id, 'bucket' => 'usable', 'quantity' => $qty]]]]])->assertCreated()->json('data');
        $v = ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => $intent['total_amount']];
        $sale = $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', $v)->assertOk()->json('data');
        $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', $v)->assertOk();

        return $sale;
    }

    private function refund(array $sale, int $qty, string $disposition = 'quarantine'): void
    {
        $s = DB::table('sales')->where('id', $sale['id'])->firstOrFail();
        $shift = DB::table('cash_shifts')->where('id', $s->cash_shift_id)->firstOrFail();
        $v = ['version' => $s->version, 'shift_id' => $shift->id, 'shift_version' => $shift->version, 'stock_disposition' => $disposition, 'reference' => 'R-'.Str::ulid(), 'reason' => 'Historical customer refund', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => $qty]]];
        $path = '/api/v1/sales/'.$sale['id'].'/refunds';
        $preview = $this->act($this->lead)->postJson($path.'/preview', $v)->assertOk()->json('data');
        $v += ['operation_id' => (string) Str::uuid(), 'verified' => true, 'review_hash' => $preview['review_hash']];
        $this->postJson($path, $v)->assertCreated();
        $this->postJson($path, $v)->assertCreated();
    }

    public function test_all_original_goods_missing_then_replacement_keeps_actual_supplier_asset_and_cost(): void
    {
        [$d,$g] = $this->fixture(0, beforeDispatch: true);
        $path = '/api/v1/orders/'.$d['order']['id'].'/shipments/'.$d['shipment']['id'].'/discrepancy';
        $this->act($this->lead)->postJson($path, ['operation_id' => (string) Str::uuid(), 'order_version' => $d['order']['version'], 'reason' => 'All original goods missing', 'reference' => 'Actual custody checked'])->assertCreated();
        $p = $this->act($this->owner)->postJson($path.'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'type' => 'replacement', 'proposal' => 'Send actual supplier owned replacement', 'reference' => 'Both parties agree'])->assertCreated()->json('data');
        $this->act($this->lead)->postJson($path.'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();
        $stock = $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'CONSIGNED-REPLACEMENT', 'expires_on' => '2030-01-01', 'quantity_packs' => 20, 'note' => 'Actual replacement supply'])->assertCreated()->json('data');
        $value = $this->erp($this->center->id, $this->owner, 'value_propose', ['batch_id' => $stock['id'], 'stock_revision' => $stock['stock_revision'], 'amount' => 50003, 'note' => 'Actual replacement source cost']);
        $this->erp($this->center->id, $this->user($this->center, 'admin'), 'value_decide', ['id' => $value['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Independent replacement cost evidence']);
        $send = ['operation_id' => (string) Str::uuid(), 'version' => 3, 'handler' => 'Replacement courier', 'lines' => [['original_shipment_line_id' => $d['shipment']['lines'][0]['id'], 'allocations' => [['batch_id' => $stock['id'], 'quantity_packs' => 20]]]]];
        $r = $this->act($this->warehouse)->postJson($path.'/replacements', $send)->assertCreated()->json('data');
        $this->postJson($path.'/replacements', $send)->assertCreated();
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 4, 'lines' => [['replacement_line_id' => $r['replacement']['lines'][0]['id'], 'good_packs' => 20, 'damaged_packs' => 0, 'condition_note' => 'Replacement physically received']]];
        $url = $path.'/replacements/'.$r['replacement']['id'].'/receive';
        $this->act($this->lead)->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $b = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        $this->assertDatabaseHas('consignor_pools', ['inventory_batch_id' => $b->id, 'quantity' => 20, 'remaining_value' => 50003]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 0, 'remaining_value' => 0]);
        $this->assertSame([], $this->terms('distribution_replacement'));
        $this->sale($b, 10);
        $this->assertSame([[['cogs', 'finished', 25001]]], $this->terms('consignor_cogs'));
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 10, 'remaining_value' => 25002]);
        // A held activation must also block a batch with replacement-only provenance.
        DB::table('consignor_valuations')->where('agreement_id', $g['id'])->update(['status' => 'review_required']);
        $this->assertTrue(app(ConsignorCosting::class)->blocked($this->partner->id, $b->id));
        $this->assertFalse(app(ConsignorCosting::class)->blocked($this->center->id, $b->id));
    }

    private function terms(string $type): array
    {
        return DB::table('accounting_events')->where('organization_id', $this->center->id)->where('source_type', $type)->orderBy('id')->get()->map(fn ($r) => json_decode($r->terms, true))->all();
    }

    public function test_physical_value_stays_with_supplier_and_actual_cogs_ignores_contract_formula_and_replays_once(): void
    {
        [$d,$g,$b] = $this->fixture();
        $this->assertDatabaseHas('consignor_valuations', ['agreement_id' => $g['id'], 'status' => 'active']);
        $this->assertDatabaseHas('consignor_pools', ['inventory_batch_id' => $b->id, 'quantity' => 100, 'remaining_value' => 100001]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 0, 'remaining_value' => 0]);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $sale = $this->sale($b, 40);
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame([[['cogs', 'finished', 40000]]], $this->terms('consignor_cogs'));
        $this->assertDatabaseHas('consignor_pools', ['inventory_batch_id' => $b->id, 'quantity' => 60, 'remaining_value' => 60001]);
        $this->assertDatabaseHas('inventory_cost_entries', ['organization_id' => $this->partner->id, 'source_type' => 'pos_sale', 'quantity_delta' => 0, 'value_delta' => 0]);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $position = DB::table('commercial_entries')->firstOrFail();
        $this->assertSame(68000, json_decode($position->snapshot, true)['agreed_goods_cost']);
        $this->refund($sale, 10);
        $this->assertSame([[['finished', 'cogs', 10000]]], $this->terms('consignor_refund_cogs'));
        $this->assertDatabaseHas('consignor_pools', ['inventory_batch_id' => $b->id, 'quantity' => 70, 'remaining_value' => 70001]);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $this->act($this->owner)->getJson('/api/v1/organizations/'.$this->center->id.'/erp/context?section=consignor_values')->assertOk()->assertSee('70001');
        $this->act($this->lead)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=consignor_values')->assertOk()->assertDontSee('70001')->assertDontSee('100001')->assertJsonPath('data.0.owned_value', 0);
        $this->getJson('/api/v1/organizations/'.$this->center->id.'/erp/context?section=consignor_ledger')->assertForbidden();
        $this->act($this->cashier)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=consignor_values')->assertForbidden();
    }

    public function test_valuation_before_dispatch_tracks_transit_and_partial_receipt_keeps_missing_for_two_party_resolution(): void
    {
        [$d,$g,$b] = $this->fixture(90, 0, beforeDispatch: true);
        $this->assertDatabaseHas('consignor_lots', ['quantity' => 100, 'value' => 100001, 'received_quantity' => 90, 'received_value' => 90000]);
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 90, 'remaining_value' => 90000]);
        $missing = DB::table('accounting_missing_costs')->firstOrFail();
        $this->assertSame(10001, $missing->missing_value);
        $this->assertNotNull($missing->review_event_id);
        $path = '/api/v1/orders/'.$d['order']['id'].'/shipments/'.$d['shipment']['id'].'/discrepancy';
        $this->act($this->lead)->postJson($path, ['operation_id' => (string) Str::uuid(), 'order_version' => $d['order']['version'], 'reason' => 'Actual consignment shortage', 'reference' => 'Physical source'])->assertCreated();
        $this->erp($this->center->id, $this->owner, 'missing_propose', ['id' => $missing->id, 'version' => 1, 'owner_share_basis_points' => 10000, 'note' => 'Supplier bears full missing cost']);
        $this->erp($this->partner->id, $this->lead, 'missing_decide', ['id' => $missing->id, 'version' => 2, 'decision' => 'approve', 'consent' => true, 'note' => 'Recipient leader agrees exact shortage and burden']);
        $this->assertDatabaseHas('consignor_lots', ['settled_missing_value' => 10001, 'settled_missing_quantity' => 10]);
        $this->assertSame([[['loss', 'finished', 10001]]], $this->terms('distribution_missing'));
        $this->assertSame([], $this->terms('consignor_cogs'));
        $this->act($this->owner)->getJson('/api/v1/organizations/'.$this->center->id.'/erp/context?section=consignor_values')->assertOk()->assertJsonPath('data.0.transit_value', 0);
    }

    public function test_unknown_cost_is_never_zero_cogs_but_known_zero_remains_proven(): void
    {
        [$d,$g,$b] = $this->fixture(value: null);
        $this->sale($b, 10);
        $this->assertSame([[['cogs', 'finished', null]]], $this->terms('consignor_cogs'));
        app(AutomaticPosting::class)->drain();
        $this->assertDatabaseHas('accounting_events', ['source_type' => 'consignor_cogs', 'status' => 'review_required']);
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 90, 'remaining_value' => null]);
    }

    public function test_zero_source_has_zero_owned_holder_balance_and_no_fake_owner_journal(): void
    {
        [$d,$g,$b] = $this->fixture(value: 0, beforeDispatch: true);
        $sale = $this->sale($b, 10);
        $this->refund($sale, 2);
        $this->assertSame([], $this->terms('consignor_cogs'));
        $this->assertSame([], $this->terms('consignor_refund_cogs'));
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 92, 'remaining_value' => 0]);
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    public function test_refund_without_goods_keeps_cogs_and_last_piece_physical_refund_uses_exact_offset(): void
    {
        [$d,$g,$b] = $this->fixture();
        $sale = $this->sale($b, 100);
        $this->refund($sale, 99, 'no_stock');
        $this->assertSame([], $this->terms('consignor_refund_cogs'));
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 0, 'remaining_value' => 0]);
        $this->refund($sale, 1);
        $this->assertSame([[['finished', 'cogs', 1001]]], $this->terms('consignor_refund_cogs'));
        $this->assertDatabaseHas('consignor_cost_entries', ['source_type' => 'sale_refund', 'cost_offset' => 99, 'quantity_delta' => 1, 'value_delta' => 1001]);
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 1, 'remaining_value' => 1001]);
    }

    private function physicalReturn(array $d, InventoryBatch $b, int $received = 20): void
    {
        $path = '/api/v1/orders/'.$d['order']['id'].'/shipments/'.$d['shipment']['id'].'/discrepancy';
        $this->act($this->lead)->postJson($path, ['operation_id' => (string) Str::uuid(), 'order_version' => $d['order']['version'], 'reason' => 'Original damaged custody returned', 'reference' => 'Actual quarantine'])->assertCreated();
        $p = $this->act($this->owner)->postJson($path.'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'type' => 'return', 'proposal' => 'Return original damaged supplier-owned stock', 'reference' => 'Owner agrees physical return'])->assertCreated()->json('data');
        $this->act($this->lead)->postJson($path.'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();
        $returned = $this->postJson($path.'/returns', ['operation_id' => (string) Str::uuid(), 'version' => 3, 'destination_bucket' => 'quarantined', 'handler' => 'Consigned return courier', 'lines' => [['original_shipment_line_id' => $d['shipment']['lines'][0]['id'], 'allocations' => [['batch_id' => $b->id, 'quantity_packs' => 20]]]]])->assertCreated()->json('data');
        $body = ['operation_id' => (string) Str::uuid(), 'version' => $returned['case_version'], 'lines' => [['return_line_id' => $returned['return']['lines'][0]['id'], 'received_packs' => $received, 'condition_note' => 'Actual returned pieces checked']]];
        $url = $path.'/returns/'.$returned['return']['id'].'/receive';
        $this->act($this->warehouse)->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
    }

    public function test_unsold_quarantine_return_carries_supplier_cost_without_credit_or_cogs_reversal(): void
    {
        [$d,$g,$b,$stock] = $this->fixture(80, 20, beforeDispatch: true);
        $this->physicalReturn($d, $b);
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 80, 'remaining_value' => 80001]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $stock['id'], 'quantity' => 20, 'remaining_value' => 20000]);
        $this->assertDatabaseHas('consignor_returns', ['quantity' => 20, 'value' => 20000, 'received_quantity' => 20, 'received_value' => 20000]);
        $this->assertSame([], $this->terms('consignor_cogs'));
        $this->assertSame([], $this->terms('consignor_refund_cogs'));
        $this->assertDatabaseCount('invoice_credits', 0);
        $this->assertDatabaseCount('accounting_return_costs', 0);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    public function test_return_shortage_uses_private_owner_entry_for_bilateral_cost_settlement(): void
    {
        [$d,$g,$b] = $this->fixture(80, 20, beforeDispatch: true);
        $this->physicalReturn($d, $b, 18);
        $missing = DB::table('accounting_missing_costs')->firstOrFail();
        $this->assertNotNull($missing->consignor_entry_id);
        $this->assertSame(2000, $missing->missing_value);
        $this->erp($this->center->id, $this->owner, 'missing_propose', ['id' => $missing->id, 'version' => 1, 'owner_share_basis_points' => 10000, 'note' => 'Supplier retains title and bears missing return cost']);
        $this->erp($this->partner->id, $this->lead, 'missing_decide', ['id' => $missing->id, 'version' => 2, 'decision' => 'approve', 'consent' => true, 'note' => 'Partner confirms actual shortage and burden']);
        $this->assertDatabaseHas('consignor_returns', ['received_value' => 18000, 'settled_missing_value' => 2000, 'settled_missing_quantity' => 2]);
        $this->assertSame([[['loss', 'finished', 2000]]], $this->terms('distribution_missing'));
    }

    public function test_reviewed_physical_waste_requires_owner_finance_approval_before_loss_posting(): void
    {
        [$d,$g,$b] = $this->fixture();
        DB::transaction(fn () => app(StockLedger::class)->apply($b, $this->lead->id, ['usable' => -10, 'waste' => 10], 'stock_waste', (string) Str::ulid(), 'Independently reviewed physical waste'));
        $disposition = DB::table('consignor_dispositions')->firstOrFail();
        $this->assertSame(10000, $disposition->value);
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 90, 'remaining_value' => 90001]);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertDatabaseHas('accounting_events', ['source_type' => 'consignor_disposition', 'status' => 'review_required']);
        $body = ['operation_id' => (string) Str::uuid(), 'id' => $disposition->id, 'version' => 1, 'note' => 'Owner checked physical waste and accepts its own loss'];
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->center->id.'/erp/consignor_dispose', $body)->assertForbidden();
        $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/erp/consignor_dispose', $body)->assertCreated();
        $this->postJson('/api/v1/organizations/'.$this->center->id.'/erp/consignor_dispose', $body)->assertCreated();
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $this->assertSame([[['loss', 'finished', 10000]]], $this->terms('consignor_disposition'));
    }

    public function test_failed_owner_cogs_outbox_rolls_back_sale_stock_cash_and_private_value(): void
    {
        [$d,$g,$b] = $this->fixture();
        DB::unprepared("CREATE TRIGGER fail_consignor_cogs BEFORE INSERT ON accounting_events WHEN NEW.source_type='consignor_cogs' BEGIN SELECT RAISE(FAIL, 'owner outbox unavailable'); END");
        $this->withoutExceptionHandling();
        try {
            $this->sale($b, 10);
            $this->fail('Outbox failure must propagate.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('owner outbox unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(0, DB::table('consignor_cost_entries')->where('source_type', 'pos_sale')->count());
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 100, 'remaining_value' => 100001]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $b->id, 'usable_pieces' => 100]);
        DB::unprepared('DROP TRIGGER fail_consignor_cogs');
        $this->withExceptionHandling();
        $intent = DB::table('sale_intents')->firstOrFail();
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$intent->id.'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => $intent->total_amount])->assertOk();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 90, 'remaining_value' => 90001]);
    }

    public function test_later_receipt_uses_weighted_owner_pool_without_repricing_historical_cogs(): void
    {
        [$d,$g,$b] = $this->fixture(orderQuantity: 200);
        $this->sale($b, 40);
        $stock = $this->act($this->owner)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'B-001', 'expires_on' => '2030-01-01', 'quantity_packs' => 100, 'note' => 'Later actual source at different cost'])->assertCreated()->json('data');
        $opening = $this->erp($this->center->id, $this->owner, 'value_propose', ['batch_id' => $stock['id'], 'stock_revision' => $stock['stock_revision'], 'amount' => 200003, 'note' => 'Later source proof']);
        $reviewer = User::findOrFail(DB::table('organization_user')->where('organization_id', $this->center->id)->where('role', 'admin')->value('user_id'));
        $this->erp($this->center->id, $reviewer, 'value_decide', ['id' => $opening['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Independent later-cost review']);
        $sent = $this->dispatch($d['order'], $stock, 100);
        $this->act($this->lead)->postJson('/api/v1/shipments/'.$sent['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $sent['order']['version'], 'lines' => [['shipment_line_id' => $sent['shipment']['lines'][0]['id'], 'good_packs' => 100, 'damaged_packs' => 0]]])->assertOk();
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 160, 'remaining_value' => 260004]);
        $this->sale($b->fresh(), 80);
        $this->assertSame([[['cogs', 'finished', 40000]], [['cogs', 'finished', 130002]]], $this->terms('consignor_cogs'));
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 80, 'remaining_value' => 130002]);
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $b->id, 'quantity' => 0, 'remaining_value' => 0]);
    }

    public function test_custody_binding_persistence_failure_rolls_back_agreement_and_all_owner_proofs(): void
    {
        DB::unprepared("CREATE TRIGGER fail_consignor_binding BEFORE INSERT ON consignor_bindings BEGIN SELECT RAISE(FAIL, 'custody proof persistence failed'); END");
        $this->withoutExceptionHandling();
        try {
            $this->fixture();
            $this->fail('Binding persistence failure must propagate.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('custody proof persistence failed', $e->getMessage());
        }
        $this->assertDatabaseCount('consignor_valuations', 0);
        $this->assertDatabaseCount('consignor_pools', 0);
        $this->assertDatabaseCount('consignor_lots', 0);
        $this->assertDatabaseHas('commercial_agreements', ['status' => 'pending', 'version' => 1]);
        $batch = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        $this->assertDatabaseHas('inventory_values', ['inventory_batch_id' => $batch->id, 'quantity' => 100, 'remaining_value' => null]);
        DB::unprepared('DROP TRIGGER fail_consignor_binding');
        $this->withExceptionHandling();
        $g = DB::table('commercial_agreements')->firstOrFail();
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Same reviewed terms after persistence recovery', 'consent' => true, 'sharing_consented' => true];
        $url = '/api/v1/organizations/'.$this->partner->id.'/commercial-agreements/'.$g->id.'/accept';
        $this->act($this->lead)->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $this->assertDatabaseCount('consignor_pools', 1);
        $this->assertDatabaseCount('consignor_bindings', 1);
        $this->assertDatabaseHas('consignor_pools', ['quantity' => 100, 'remaining_value' => 100001]);
    }
}
