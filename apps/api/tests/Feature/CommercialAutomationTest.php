<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commercial\Application\ConsignmentAutomation;
use App\Modules\Commercial\Infrastructure\CommercialAgreement;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WholesaleScenario;

class CommercialAutomationTest extends WholesaleScenario
{
    private function contract(): PartnershipContract
    {
        return PartnershipContract::create(['organization_id' => $this->center->id, 'partner_id' => $this->partner->id, 'created_by' => $this->owner->id, 'title' => 'Agreed scope', 'mode' => 'consignment', 'status' => 'accepted', 'version' => 3, 'starts_on' => '2026-01-01', 'ends_on' => '2030-12-31', 'terms' => 'Standing commercial addenda require consent', 'brand_terms' => 'Brand', 'data_scope' => 'Agreed addenda', 'facilities' => 'None']);
    }

    private function terms(array $d, string $basis = 'net_sales'): array
    {
        return ['basis' => $basis, 'supplier_basis_points' => 3000, 'tax_basis_points' => 0, 'loss_policy' => 'zero_floor', 'bundle_basis' => 'reject', 'costs' => $basis === 'margin' ? [['order_line_id' => $d['order']['lines'][0]['id'], 'cost_per_piece' => 1000, 'evidence_reference' => 'Actual unit cost']] : [], 'rounding' => 'floor_magnitude_per_line', 'refund_policy' => 'reverse_proportionally', 'movement_policy' => 'original_receipt_store_only', 'frequency' => 'continuous', 'data_scope' => 'allocated_receipt_totals'];
    }

    private function proposed(array $d, string $basis = 'net_sales'): array
    {
        return $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/commercial-agreements', ['operation_id' => (string) Str::uuid(), 'kind' => 'consignment', 'contract_id' => $this->contract()->id, 'order_id' => $d['order']['id'], 'starts_on' => now('Asia/Jakarta')->format('Y-m-d'), 'ends_on' => '2030-12-31', 'reference' => 'Signed rates and standing authorization', 'terms' => $this->terms($d, $basis)])->assertCreated()->json('data');
    }

    private function accept(array $g, ?User $actor = null, string $org = ''): array
    {
        return $this->act($actor ?? $this->lead)->postJson('/api/v1/organizations/'.($org ?: $this->partner->id).'/commercial-agreements/'.$g['id'].'/accept', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Full terms reviewed', 'consent' => true, 'sharing_consented' => true])->assertOk()->json('data');
    }

    private function checkout(?string $voucher = null, int $quantity = 2, bool $pay = true): array
    {
        $b = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        if ($b->opened_pieces === 0) {
            $this->act($this->lead)->postJson('/api/v1/stores/'.$this->target->id.'/inventory/processes', ['operation_id' => (string) Str::uuid(), 'batch_id' => $b->id, 'expected_revision' => $b->stock_revision, 'kind' => 'open', 'quantity_packs' => 1, 'reason' => 'Actual opened pack'])->assertCreated();
        }
        $shift = DB::table('cash_shifts')->where('store_id', $this->target->id)->where('cashier_id', $this->cashier->id)->where('status', 'open')->first();
        if (! $shift) {
            $shift = (object) $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->target->id.'/shifts', ['operation_id' => (string) Str::uuid(), 'device_name' => 'Terminal', 'opening_cash' => 100000])->assertCreated()->json('data');
        }
        $body = ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift->id, 'lines' => [['product_id' => $this->localProduct->id, 'unit' => 'piece', 'quantity' => $quantity, 'allocations' => [['batch_id' => $b->id, 'bucket' => 'opened', 'quantity' => $quantity]]]]];
        if ($voucher) {
            $body['voucher_code'] = $voucher;
        }
        $intent = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->target->id.'/sales/intents', $body)->assertCreated()->json('data');
        if (! $pay) {
            return $intent;
        }

        return $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertOk()->json('data');
    }

    private function refund(array $s, int $quantity = 1): void
    {
        $shift = DB::table('cash_shifts')->find($s['cash_shift_id']);
        $body = ['version' => $s['version'], 'shift_id' => $shift->id, 'shift_version' => (int) $shift->version, 'stock_disposition' => 'no_stock', 'reference' => 'ACTUAL-REFUND-'.Str::ulid(), 'reason' => 'Actual refund verified', 'lines' => [['sale_line_id' => $s['lines'][0]['id'], 'quantity' => $quantity]]];
        $review = $this->act($this->lead)->postJson('/api/v1/sales/'.$s['id'].'/refunds/preview', $body)->assertOk()->json('data');
        $this->postJson('/api/v1/sales/'.$s['id'].'/refunds', [...$body, 'operation_id' => (string) Str::uuid(), 'review_hash' => $review['review_hash'], 'verified' => true])->assertCreated();
    }

    public function test_standing_consent_automatic_share_replay_refund_delta_and_verified_payment(): void
    {
        $d = $this->received(20, 20, 0);
        $g = $this->proposed($d);
        $this->accept($g);
        $s = $this->checkout();
        $stock = DB::table('stock_entries')->count();
        $this->assertSame(['done' => 1, 'failed' => 0], app(ConsignmentAutomation::class)->accrue());
        $this->assertSame(1800, (int) DB::table('commercial_entries')->sum('amount'));
        app(ConsignmentAutomation::class)->accrue();
        $this->assertDatabaseCount('commercial_entries', 1);
        $this->refund($s);
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame(900, (int) DB::table('commercial_entries')->sum('amount'));
        $this->assertDatabaseCount('commercial_entries', 2);
        $this->assertSame($stock, DB::table('stock_entries')->count());
        $p = '/api/v1/organizations/'.$this->center->id.'/commercial-agreements/'.$g['id'];
        $this->act($this->outsider)->getJson($p)->assertForbidden();
        $this->act($this->cashier)->getJson('/api/v1/organizations/'.$this->partner->id.'/commercial-agreements/'.$g['id'])->assertForbidden();
        $this->act($this->owner)->getJson($p)->assertOk()->assertJsonPath('own_receivable', 900);
        $payment = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'direction' => 'collection', 'amount' => 900, 'method' => 'bank_transfer', 'reference' => 'BANK1', 'evidence_reference' => 'Actual bank receipt', 'paid_on' => now('Asia/Jakarta')->format('Y-m-d'), 'verified' => true];
        $this->postJson($p.'/payments', $payment)->assertOk();
        $this->postJson($p.'/payments', $payment)->assertOk();
        $this->getJson($p)->assertJsonPath('own_receivable', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('commercial_payments', 1);
        $this->postJson('/api/v1/orders/'.$d['order']['id'].'/billing/invoices', ['operation_id' => (string) Str::uuid(), 'mode' => 'buyout', 'basis' => 'dispatched', 'shipment_ids' => [$d['shipment']['id']], 'due_on' => '2030-12-31', 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_basis_points' => 0, 'rounding' => 'half_up', 'note' => 'Must not double bill'])->assertConflict();
    }

    public function test_margin_requires_all_costs_and_no_party_can_self_accept_or_read_other_scope(): void
    {
        $d = $this->received(20, 20, 0);
        $g = $this->proposed($d, 'margin');
        $body = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Try self', 'consent' => true, 'sharing_consented' => true];
        $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/commercial-agreements/'.$g['id'].'/accept', $body)->assertForbidden();
        $this->accept($g);
        $this->checkout();
        $this->assertSame(['done' => 1, 'failed' => 0], app(ConsignmentAutomation::class)->accrue());
        $this->assertSame(1200, (int) DB::table('commercial_entries')->sum('amount'));
    }

    public function test_sponsored_discount_budget_atomic_claim_and_partial_refund_reverse_both_liability_and_budget(): void
    {
        $this->received(20, 20, 0);
        $c = $this->contract();
        $url = '/api/v1/organizations/'.$this->partner->id.'/commercial-agreements';
        $terms = ['sponsor_basis_points' => 5000, 'budget_amount' => 1500, 'budget_restore' => 'proportional_refund', 'data_scope' => 'allocated_receipt_totals', 'voucher' => ['code' => 'SPONSORED', 'kind' => 'fixed', 'value' => 2000, 'maximum_discount' => 2000, 'minimum_subtotal' => 0, 'usage_limit' => 10, 'store_ids' => [$this->target->id], 'product_ids' => [$this->localProduct->id], 'restore_policy' => 'on_full_refund', 'terms' => 'Shared explicitly']];
        $g = $this->act($this->lead)->postJson($url, ['operation_id' => (string) Str::uuid(), 'kind' => 'sponsored_promo', 'contract_id' => $c->id, 'starts_on' => now('Asia/Jakarta')->format('Y-m-d'), 'ends_on' => '2030-12-31', 'reference' => 'Sponsor budget approved', 'terms' => $terms])->assertCreated()->json('data');
        $this->accept($g, $this->owner, $this->center->id);
        $s = $this->checkout('SPONSORED');
        $this->assertSame(4000, $s['total_amount']);
        $this->assertSame(1000, $s['voucher_snapshot']['sponsor_amount']);
        $this->assertSame(-1000, (int) DB::table('commercial_entries')->sum('amount'));
        $this->assertSame(1000, (int) DB::table('commercial_agreements')->where('id', $g['id'])->value('budget_spent'));
        $this->refund($s);
        $this->assertSame(-500, (int) DB::table('commercial_entries')->sum('amount'));
        $this->assertSame(500, (int) DB::table('commercial_agreements')->where('id', $g['id'])->value('budget_spent'));
        $this->checkout('SPONSORED');
        $this->assertSame(1500, (int) DB::table('commercial_agreements')->where('id', $g['id'])->value('budget_spent'));
    }

    public function test_sponsor_budget_is_rechecked_at_payment_for_two_prepared_unreserved_drafts(): void
    {
        $this->received(20, 20, 0);
        $contract = $this->contract();
        $terms = ['sponsor_basis_points' => 10000, 'budget_amount' => 1000, 'budget_restore' => 'never', 'data_scope' => 'allocated_receipt_totals', 'voucher' => ['code' => 'LAST1000', 'kind' => 'fixed', 'value' => 1000, 'maximum_discount' => 1000, 'minimum_subtotal' => 0, 'usage_limit' => 5, 'store_ids' => [$this->target->id], 'product_ids' => [], 'restore_policy' => 'never', 'terms' => 'Budget cap is shared']];
        $g = $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->partner->id.'/commercial-agreements', ['operation_id' => (string) Str::uuid(), 'kind' => 'sponsored_promo', 'contract_id' => $contract->id, 'starts_on' => now('Asia/Jakarta')->format('Y-m-d'), 'ends_on' => '2030-12-31', 'reference' => 'Limited actual sponsor budget', 'terms' => $terms])->assertCreated()->json('data');
        $this->accept($g, $this->owner, $this->center->id);
        $a = $this->checkout('LAST1000', 2, false);
        $b = $this->checkout('LAST1000', 2, false);
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$a['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 10000])->assertOk();
        $stock = DB::table('stock_entries')->count();
        $this->postJson('/api/v1/sales/intents/'.$b['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 10000])->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame($stock, DB::table('stock_entries')->count());
        $this->assertSame(1000, (int) DB::table('commercial_agreements')->value('budget_spent'));
    }

    public function test_existing_sales_prevent_retroactive_activation_and_pending_same_order_is_unique(): void
    {
        $d = $this->received(20, 20, 0);
        $g = $this->proposed($d);
        $this->checkout();
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Cannot retroactively enroll', 'consent' => true, 'sharing_consented' => true];
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->partner->id.'/commercial-agreements/'.$g['id'].'/accept', $v)->assertConflict();
        $this->assertSame('pending', DB::table('commercial_agreements')->value('status'));
        $this->assertDatabaseCount('commercial_entries', 0);
    }

    public function test_explicit_loss_sharing_and_suspension_do_not_silently_charge_new_sales(): void
    {
        $d = $this->received(20, 20, 0);
        $c = $this->contract();
        $terms = $this->terms($d, 'margin');
        $terms['loss_policy'] = 'share_loss';
        $terms['costs'][0]['cost_per_piece'] = 5000;
        $url = '/api/v1/organizations/'.$this->center->id.'/commercial-agreements';
        $body = ['operation_id' => (string) Str::uuid(), 'kind' => 'consignment', 'contract_id' => $c->id, 'order_id' => $d['order']['id'], 'starts_on' => now('Asia/Jakarta')->format('Y-m-d'), 'ends_on' => '2030-12-31', 'reference' => 'Explicit negative margin split', 'terms' => $terms];
        $this->act($this->owner)->postJson($url, [...$body, 'operation_id' => (string) Str::uuid(), 'terms' => [...$terms, 'costs' => []]])->assertUnprocessable();
        $this->assertDatabaseCount('commercial_agreements', 0);
        $g = $this->postJson($url, $body)->assertCreated()->json('data');
        $this->accept($g);
        $this->checkout();
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame(-1200, (int) DB::table('commercial_entries')->sum('amount'));
        $this->act($this->owner)->postJson($url.'/'.$g['id'].'/suspend', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'reason' => 'Stop future settlements and review remaining goods'])->assertOk();
        $this->assertSame($this->lead->id, CommercialAgreement::findOrFail($g['id'])->terms['authorization']['approved_by']);
        $intent = $this->checkout(null, 2, false);
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 10000])->assertConflict();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_later_batch_credit_cannot_rewrite_frozen_sale_provenance_or_hide_refund_correction(): void
    {
        $d = $this->received(20, 20, 0);
        $g = $this->proposed($d);
        $this->accept($g);
        $sale = $this->checkout();
        $this->assertArrayNotHasKey('commercial_origin_snapshot', $sale['lines'][0]['allocations'][0]);
        $batch = InventoryBatch::where('store_id', $this->target->id)->firstOrFail();
        DB::transaction(fn () => app(StockLedger::class)->credit($this->partner->id, $this->target->id, $this->localProduct->only(['id', 'sku', 'name', 'condition', 'pack_size']), $batch->batch_code, $batch->expires_on->format('Y-m-d'), 1, $this->lead->id, 'inventory_intake', (string) Str::ulid(), 'usable', 'New separately owned goods after the sale'));
        $this->refund($sale);
        $this->assertSame(['done' => 1, 'failed' => 0], app(ConsignmentAutomation::class)->accrue());
        $this->assertSame(900, (int) DB::table('commercial_entries')->sum('amount'));
    }

    public function test_commercial_routes_deny_cashier_foreign_leader_and_central_onboarding_override(): void
    {
        $d = $this->received(20, 20, 0);
        $g = $this->proposed($d);
        $root = '/api/v1/organizations/'.$this->partner->id.'/commercial-agreements';
        foreach ([$this->cashier, $this->outsider, $this->owner] as $u) {
            $this->act($u)->getJson($root)->assertForbidden();
            $this->getJson($root.'/options')->assertForbidden();
            $this->getJson($root.'/'.$g['id'])->assertForbidden();
            foreach (['accept', 'reject', 'withdraw', 'suspend'] as $action) {
                $this->postJson($root.'/'.$g['id'].'/'.$action, ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Unauthorized request', 'consent' => true, 'sharing_consented' => true])->assertForbidden();
            }
            $this->postJson($root.'/'.$g['id'].'/payments', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'direction' => 'collection', 'amount' => 1, 'method' => 'bank_transfer', 'reference' => 'DENIED', 'evidence_reference' => 'Fixture', 'paid_on' => now('Asia/Jakarta')->format('Y-m-d'), 'verified' => true])->assertForbidden();
        }
        $this->assertDatabaseCount('commercial_agreements', 1);
        $this->assertDatabaseCount('commercial_payments', 0);
        $this->assertDatabaseCount('commercial_entries', 0);
    }
}
