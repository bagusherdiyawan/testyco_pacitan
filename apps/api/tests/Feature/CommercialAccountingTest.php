<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Accounting\Application\CommercialPolicy;
use App\Modules\Commercial\Application\ConsignmentAutomation;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WholesaleScenario;

class CommercialAccountingTest extends WholesaleScenario
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

    private function erp(Organization $org, User $u, string $action, array $v): array
    {
        return $this->act($u)->postJson('/api/v1/organizations/'.$org->id.'/erp/'.$action, ['operation_id' => (string) Str::uuid(), ...$v])->assertCreated()->json('data');
    }

    private function configure(Organization $org, User $u): array
    {
        $reviewer = $this->user($org, $org->type === 'center' ? 'admin' : 'partner_lead');
        $today = now('Asia/Jakarta')->format('Y-m-d');
        $this->erp($org, $u, 'accounting_period', ['name' => 'Period', 'starts_on' => $today, 'ends_on' => $today, 'note' => 'Explicit']);
        $baseFields = ['note' => 'Approved own accounts'];
        $commercial = [];
        foreach ([...AutomaticPosting::ROLES, ...CommercialPolicy::ROLES] as $role => [$label,$kind,$normal]) {
            $a = $this->erp($org, $u, 'account', ['code' => $role, 'name' => $label, 'kind' => $kind, 'normal_balance' => $normal]);
            if (isset(AutomaticPosting::ROLES[$role])) {
                $baseFields[$role.'_account'] = $a['id'];
            } else {
                $commercial[$role.'_account'] = $a['id'];
            }
        }
        $base = $this->erp($org, $u, 'posting_policy', $baseFields);
        $this->erp($org, $reviewer, 'posting_decide', ['id' => $base['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Independent']);
        $v = ['base_policy_id' => $base['id'], 'base_policy_version' => 2, 'recognition_basis' => 'contractual_rights', 'tax_treatment' => 'recoverable', ...$commercial, 'note' => 'Only contractual rights, ownership cost separate'];
        $p = $this->erp($org, $u, 'commercial_policy', $v);
        $this->act($u)->postJson('/api/v1/organizations/'.$org->id.'/erp/posting_decide', ['operation_id' => (string) Str::uuid(), 'id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'No self approval'])->assertForbidden();
        $this->erp($org, $reviewer, 'posting_decide', ['id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Independently approved']);

        return ['policy' => $p, 'reviewer' => $reviewer, 'request' => $v];
    }

    private function source(string $org, string $type): array
    {
        return DB::table('accounting_events')->where('organization_id', $org)->where('source_type', $type)->orderBy('id')->get()->map(fn ($e) => json_decode($e->terms, true))->all();
    }

    public function test_contractual_share_accrual_refund_and_payment_have_two_private_exact_once_books(): void
    {
        $d = $this->received(20, 20, 0);
        $center = $this->configure($this->center, $this->owner);
        $partner = $this->configure($this->partner, $this->lead);
        $g = $this->accept($this->proposed($d));
        $s = $this->checkout();
        $this->assertSame(['done' => 1, 'failed' => 0], app(ConsignmentAutomation::class)->accrue());
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame([[['share_receivable', 'share_income', 1800]]], $this->source($this->center->id, 'commercial_accrual'));
        $this->assertSame([[['share_expense', 'share_payable', 1800]]], $this->source($this->partner->id, 'commercial_accrual'));
        $this->assertSame(2, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $this->refund($s);
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame([['share_income', 'share_receivable', 900]], $this->source($this->center->id, 'commercial_accrual')[1]);
        $pay = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'direction' => 'collection', 'amount' => 900, 'method' => 'bank_transfer', 'reference' => 'VERIFIED-COMM', 'evidence_reference' => 'Bank evidence', 'paid_on' => now('Asia/Jakarta')->format('Y-m-d'), 'verified' => true];
        $url = '/api/v1/organizations/'.$this->center->id.'/commercial-agreements/'.$g['id'].'/payments';
        $this->act($this->owner)->postJson($url, $pay)->assertOk();
        $this->postJson($url, $pay)->assertOk();
        $this->assertSame([[['bank', 'share_receivable', 900]]], $this->source($this->center->id, 'commercial_payment'));
        $this->assertSame([[['share_payable', 'bank', 900]]], $this->source($this->partner->id, 'commercial_payment'));
        $this->assertSame(4, app(AutomaticPosting::class)->drain());
        $this->act($this->owner)->getJson('/api/v1/organizations/'.$this->partner->id.'/erp/context?section=posting_events')->assertForbidden();
        $this->assertDatabaseCount('commercial_payments', 1);
    }

    public function test_sponsor_claim_refund_and_payout_use_sponsor_roles_without_pos_drawer_writes(): void
    {
        $this->received(20, 20, 0);
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead);
        $terms = ['sponsor_basis_points' => 5000, 'budget_amount' => 1500, 'budget_restore' => 'proportional_refund', 'data_scope' => 'allocated_receipt_totals', 'voucher' => ['code' => 'SPONSORED', 'kind' => 'fixed', 'value' => 2000, 'maximum_discount' => 2000, 'minimum_subtotal' => 0, 'usage_limit' => 10, 'store_ids' => [$this->target->id], 'product_ids' => [$this->localProduct->id], 'restore_policy' => 'on_full_refund', 'terms' => 'Shared explicitly']];
        $g = $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->partner->id.'/commercial-agreements', ['operation_id' => (string) Str::uuid(), 'kind' => 'sponsored_promo', 'contract_id' => $this->contract()->id, 'starts_on' => now('Asia/Jakarta')->format('Y-m-d'), 'ends_on' => '2030-12-31', 'reference' => 'Explicit sponsor budget', 'terms' => $terms])->assertCreated()->json('data');
        $this->accept($g, $this->owner, $this->center->id);
        $sale = $this->checkout('SPONSORED');
        $this->assertSame([[['sponsor_expense', 'sponsor_payable', 1000]]], $this->source($this->center->id, 'commercial_accrual'));
        $this->assertSame([[['sponsor_receivable', 'sponsor_recovery', 1000]]], $this->source($this->partner->id, 'commercial_accrual'));
        $this->refund($sale);
        $this->assertSame([['sponsor_payable', 'sponsor_expense', 500]], $this->source($this->center->id, 'commercial_accrual')[1]);
        $cash = DB::table('cash_movements')->count();
        $pay = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'direction' => 'payout', 'amount' => 500, 'method' => 'bank_transfer', 'reference' => 'SPONSOR-PAID', 'evidence_reference' => 'Verified transfer', 'paid_on' => now('Asia/Jakarta')->format('Y-m-d'), 'verified' => true];
        $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/commercial-agreements/'.$g['id'].'/payments', $pay)->assertOk();
        $this->assertSame([[['sponsor_payable', 'bank', 500]]], $this->source($this->center->id, 'commercial_payment'));
        $this->assertSame([[['bank', 'sponsor_receivable', 500]]], $this->source($this->partner->id, 'commercial_payment'));
        $this->assertSame($cash, DB::table('cash_movements')->count());
    }

    public function test_old_position_correction_after_activation_is_review_not_silent_history_import(): void
    {
        $d = $this->received(20, 20, 0);
        $this->accept($this->proposed($d));
        $sale = $this->checkout();
        app(ConsignmentAutomation::class)->accrue();
        $this->configure($this->center, $this->owner);
        $this->refund($sale);
        app(ConsignmentAutomation::class)->accrue();
        $this->assertDatabaseHas('accounting_events', ['organization_id' => $this->center->id, 'source_type' => 'commercial_accrual', 'status' => 'review_required']);
        $this->assertDatabaseCount('accounting_commercial_positions', 0);
    }

    public function test_policy_cannot_drop_active_commercial_extension_and_foreign_role_is_denied(): void
    {
        $configured = $this->configure($this->center, $this->owner);
        $url = '/api/v1/organizations/'.$this->center->id.'/erp/commercial_policy';
        $this->act($this->warehouse)->postJson($url, ['operation_id' => (string) Str::uuid(), ...$configured['request']])->assertForbidden();
        $v = [...$configured['request'], 'base_policy_id' => $configured['policy']['id'], 'base_policy_version' => 2, 'share_income_account' => (string) Str::ulid()];
        $this->act($this->owner)->postJson($url, ['operation_id' => (string) Str::uuid(), ...$v])->assertNotFound();
        $this->postJson('/api/v1/organizations/'.$this->center->id.'/erp/posting_policy', ['operation_id' => (string) Str::uuid(), 'note' => 'Cannot silently drop', ...collect(json_decode($configured['policy']['mapping'], true))->only(array_keys(AutomaticPosting::ROLES))->mapWithKeys(fn ($a, $r) => [$r.'_account' => $a['account_id']])->all()])->assertConflict();
    }

    public function test_original_policy_survives_suspension_and_closed_source_date_requires_reopen_retry(): void
    {
        $d = $this->received(20, 20, 0);
        $configured = $this->configure($this->center, $this->owner);
        $this->accept($this->proposed($d));
        $sale = $this->checkout();
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->erp($this->center, $this->owner, 'posting_suspend', ['id' => $configured['policy']['id'], 'version' => 2, 'note' => 'Stop new sources, keep original corrections']);
        DB::table('accounting_periods')->where('organization_id', $this->center->id)->update(['status' => 'closed']);
        $this->refund($sale);
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
        $event = DB::table('accounting_events')->where('organization_id', $this->center->id)->where('source_type', 'commercial_accrual')->orderByDesc('id')->firstOrFail();
        $this->assertSame('review_required', $event->status);
        $this->assertSame($configured['policy']['id'], $event->policy_id);
        DB::table('accounting_periods')->where('organization_id', $this->center->id)->update(['status' => 'open']);
        $this->erp($this->center, $this->owner, 'posting_retry', ['id' => $event->id, 'version' => $event->version, 'note' => 'Source period reopened']);
        $this->assertSame(1, app(AutomaticPosting::class)->drain());
        $this->assertSame(0, app(AutomaticPosting::class)->drain());
    }

    public function test_failed_outbox_rolls_back_commercial_projection_and_worker_can_recover_once(): void
    {
        $d = $this->received(20, 20, 0);
        $this->configure($this->center, $this->owner);
        $this->accept($this->proposed($d));
        $this->checkout();
        DB::unprepared("CREATE TRIGGER fail_commercial_event BEFORE INSERT ON accounting_events WHEN NEW.source_type = 'commercial_accrual' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->assertSame(['done' => 0, 'failed' => 1], app(ConsignmentAutomation::class)->accrue());
        $this->assertDatabaseCount('commercial_entries', 0);
        $this->assertDatabaseCount('commercial_positions', 0);
        $this->assertDatabaseCount('accounting_commercial_positions', 0);
        DB::unprepared('DROP TRIGGER fail_commercial_event');
        $this->assertSame(['done' => 1, 'failed' => 0], app(ConsignmentAutomation::class)->accrue());
        app(ConsignmentAutomation::class)->accrue();
        $this->assertDatabaseCount('commercial_entries', 1);
        $this->assertCount(1, $this->source($this->center->id, 'commercial_accrual'));
    }

    public function test_agreed_share_tax_uses_explicit_own_input_treatment_and_original_rounded_refund_delta(): void
    {
        $d = $this->received(20, 20, 0);
        $this->configure($this->center, $this->owner);
        $this->configure($this->partner, $this->lead);
        $terms = $this->terms($d);
        $terms['tax_basis_points'] = 1000;
        $g = $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/commercial-agreements', ['operation_id' => (string) Str::uuid(), 'kind' => 'consignment', 'contract_id' => $this->contract()->id, 'order_id' => $d['order']['id'], 'starts_on' => now('Asia/Jakarta')->format('Y-m-d'), 'ends_on' => '2030-12-31', 'reference' => 'Tax explicitly agreed', 'terms' => $terms])->assertCreated()->json('data');
        $this->accept($g);
        $sale = $this->checkout();
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame([[['share_receivable', 'share_income', 1800], ['share_receivable', 'share_output_tax', 180]]], $this->source($this->center->id, 'commercial_accrual'));
        $this->assertSame([[['share_expense', 'share_payable', 1800], ['share_input_tax', 'share_payable', 180]]], $this->source($this->partner->id, 'commercial_accrual'));
        $this->refund($sale);
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame([['share_income', 'share_receivable', 900], ['share_output_tax', 'share_receivable', 90]], $this->source($this->center->id, 'commercial_accrual')[1]);
    }

    public function test_zero_share_creates_no_fake_journal_or_unresolvable_review_event(): void
    {
        $d = $this->received(20, 20, 0);
        $this->configure($this->center, $this->owner);
        $terms = $this->terms($d);
        $terms['supplier_basis_points'] = 0;
        $g = $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/commercial-agreements', ['operation_id' => (string) Str::uuid(), 'kind' => 'consignment', 'contract_id' => $this->contract()->id, 'order_id' => $d['order']['id'], 'starts_on' => now('Asia/Jakarta')->format('Y-m-d'), 'ends_on' => '2030-12-31', 'reference' => 'Explicit zero supplier share', 'terms' => $terms])->assertCreated()->json('data');
        $this->accept($g);
        $sale = $this->checkout();
        app(ConsignmentAutomation::class)->accrue();
        $this->refund($sale);
        app(ConsignmentAutomation::class)->accrue();
        $this->assertSame([], $this->source($this->center->id, 'commercial_accrual'));
        $this->assertDatabaseCount('accounting_commercial_positions', 1);
        $this->assertDatabaseCount('accounting_journals', 0);
    }
}
