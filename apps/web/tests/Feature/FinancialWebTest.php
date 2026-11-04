<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialWebTest extends TestCase
{
    private function auth(): array
    {
        return ['api_token' => 'private-fixture', 'api_user' => ['id' => 1, 'name' => 'Staff'], 'api_expires_at' => time() + 1000];
    }

    private function fake(mixed $v): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($v);
    }

    private function page(array $rows = []): array
    {
        return ['data' => $rows, 'current_page' => 1, 'per_page' => 30, 'total' => count($rows), 'last_page' => 1];
    }

    private function billing(): array
    {
        return ['order_id' => 'O1', 'organization_id' => 'C1', 'partner_id' => 'P1', 'party' => 'source', 'can_create' => true, 'can_confirm' => false, 'order_lines' => [], 'shipments' => [], 'cases' => [], 'contracts' => [], 'invoices' => $this->page(), 'reports' => $this->page()];
    }

    private function invoice(): array
    {
        return ['id' => 'I1', 'supply_order_id' => 'O1', 'organization_id' => 'C1', 'partner_id' => 'P1', 'version' => 1, 'status' => 'pending', 'mode' => 'buyout', 'basis' => 'dispatched', 'rounding' => 'half_up', 'due_on' => '2026-12-31', 'number' => 'INV-1', 'subtotal' => 100000, 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_basis_points' => 0, 'tax_amount' => 0, 'total_amount' => 100000, 'note' => 'Terms <script>bad</script>', 'lines' => [], 'payments' => [], 'credits' => [], 'credit_amount' => 0, 'collected_amount' => 0, 'refunded_amount' => 0, 'outstanding_amount' => 100000, 'customer_credit_amount' => 0];
    }

    public function test_billing_page_scope_local_pagination_and_escaped_financial_snapshots(): void
    {
        $url = '/app/orders/O1/billing';
        $c = $this->billing();
        $c['invoices'] = $this->page([$this->invoice()]);
        $c['invoices']['next_page_url'] = 'https://evil.invalid/token';
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->get($url)->assertOk()->assertSee('INV-1')->assertSee('100.000')->assertDontSee('<script>bad</script>', false)->assertDontSee('evil.invalid')->assertDontSee('private-fixture');
        $c['invoices']['data'][0]['partner_id'] = 'OTHER';
        $this->fake(['*' => Http::response($c)]);
        $this->get($url)->assertStatus(503);
    }

    public function test_invoice_proposal_unknown_keeps_required_terms_uuid_through_denial_then_401_cleans_account(): void
    {
        $url = '/app/orders/O1/billing';
        $n = 0;
        $b = ['action' => 'invoice', 'operation_id' => (string) Str::uuid(), 'mode' => 'buyout', 'basis' => 'dispatched', 'shipment_ids' => [(string) Str::ulid()], 'rounding' => 'half_up', 'due_on' => '2026-12-31', 'discount_amount' => 0, 'shipping_amount' => 0, 'tax_basis_points' => 0, 'note' => 'Terms <script>bad</script>'];
        $this->fake(function ($r) use (&$n) {
            if ($r->method() === 'GET') {
                return Http::response($this->billing());
            }$n++;

            return Http::response([], $n === 1 ? 503 : 403);
        });
        $this->withSession($this->auth())->post($url, [...$b, 'total_amount' => 1])->assertRedirect($url)->assertSessionHas('billing_pending.O1.uncertain', true);
        $f = session('billing_pending.O1.body');
        $this->assertArrayNotHasKey('total_amount', $f);
        $this->get($url)->assertOk()->assertSee('Ulangi request yang sama')->assertDontSee('<script>bad</script>', false);
        $this->post($url.'/retry', ['tax_basis_points' => 9999])->assertRedirect($url)->assertSessionHas('billing_pending.O1.body', $f);
        foreach (Http::recorded(fn ($r) => $r->method() === 'POST') as [$r]) {
            $this->assertSame($f, $r->data());
        }$this->fake(['*' => Http::response([], 401)]);
        $this->post($url.'/retry')->assertRedirect('/login')->assertSessionMissing('billing_pending')->assertSessionMissing('api_token');
    }

    public function test_manual_payment_freezes_method_reference_and_exact_amount_across_retry(): void
    {
        $url = '/app/pos/intents/INTENT/pay';
        $b = ['operation_id' => (string) Str::uuid(), 'payment_method' => 'qris_manual', 'payment_reference' => 'QRIS-123', 'verified' => '1', 'verified_amount' => '12000', 'total_amount' => 1];
        $n = 0;
        $this->fake(function ($r) use (&$n) {
            $n++;
            if ($n < 3) {
                return Http::response([], $n === 1 ? 503 : 403);
            }

            return Http::response(['data' => ['id' => 'SALE', 'sale_intent_id' => 'INTENT', 'payment_method' => 'qris_manual', 'payment_reference' => 'QRIS-123', 'total_amount' => 12000]]);
        });
        $this->withSession($this->auth())->post($url, $b)->assertRedirect('/app/pos/intents/INTENT')->assertSessionHas('pos_payment_pending.INTENT.uncertain', true);
        $f = session('pos_payment_pending.INTENT.body');
        $this->get('/app/pos/intents/INTENT')->assertOk()->assertSee('QRIS-123');
        $this->post('/app/pos/intents/INTENT/cancel', ['reason' => 'change'])->assertConflict();
        $this->post($url, ['payment_reference' => 'EDIT'])->assertRedirect('/app/pos/intents/INTENT')->assertSessionHas('pos_payment_pending.INTENT.body', $f);
        $this->post($url)->assertRedirect('/app/pos/sales/SALE')->assertSessionMissing('pos_payment_pending.INTENT');
        foreach (Http::recorded() as [$r]) {
            $this->assertSame($f, $r->data());
        }$this->assertTrue($f['verified']);
        $this->assertSame(12000, $f['verified_amount']);
        $this->assertArrayNotHasKey('total_amount', $f);
    }

    private function refundContext(string $line, string $shift): array
    {
        return ['sale' => ['id' => 'SALE', 'store_id' => 'STORE', 'version' => 1, 'payment_method' => 'cash', 'lines' => [['id' => $line, 'sale_id' => 'SALE']]], 'refunds' => [], 'open_shifts' => [['id' => $shift, 'store_id' => 'STORE', 'version' => 3, 'status' => 'open']]];
    }

    public function test_refund_preview_never_posts_money_then_confirmation_freezes_server_amount_and_stock(): void
    {
        $url = '/app/pos/sales/SALE/refunds';
        $line = (string) Str::ulid();
        $shift = (string) Str::ulid();
        $c = $this->refundContext($line, $shift);
        $review = ['sale_id' => 'SALE', 'store_id' => 'STORE', 'version' => 1, 'shift_id' => $shift, 'shift_version' => 3, 'payment_method' => 'cash', 'amount' => 2666, 'stock_disposition' => 'quarantine', 'reference' => 'REF-1', 'reason' => 'Checked', 'review_hash' => str_repeat('a', 64), 'lines' => [['sale_line_id' => $line, 'quantity' => 1, 'sku' => 'OLD', 'unit' => 'piece', 'amount' => 2666, 'allocations' => [['batch_code' => 'LOT', 'pieces' => 1]]]]];
        $n = 0;
        $this->fake(function ($r) use ($c, $review, $shift, &$n) {
            if ($r->method() === 'GET') {
                return Http::response($c);
            }if (str_ends_with($r->url(), '/preview')) {
                return Http::response(['data' => $review]);
            }$n++;

            return $n === 1 ? Http::response(['data' => ['sale_id' => 'OTHER']]) : Http::response(['data' => ['sale_id' => 'SALE', 'sale_version' => 2, 'refund' => ['id' => 'RF1', 'sale_id' => 'SALE', 'cash_shift_id' => $shift, 'payment_method' => 'cash', 'amount' => 2666, 'reference' => 'REF-1', 'stock_disposition' => 'quarantine', 'lines' => $review['lines']]]]);
        });
        $b = ['version' => 1, 'shift' => $shift.':3', 'stock_disposition' => 'quarantine', 'reference' => 'REF-1', 'reason' => 'Checked', 'lines' => [['sale_line_id' => $line, 'quantity' => 1]], 'amount' => 99999];
        $this->withSession($this->auth())->post($url.'/preview', $b)->assertRedirect($url)->assertSessionHas('refund_review.SALE');
        $this->assertSame(0, $n);
        $this->get($url)->assertOk()->assertSee('2.666')->assertSee('karantina');
        $this->post($url.'/confirm')->assertSessionHasErrors('verified');
        $this->post($url.'/confirm', ['verified' => 1, 'amount' => 99999])->assertRedirect($url)->assertSessionHas('refund_pending.SALE.uncertain', true);
        $f = session('refund_pending.SALE.body');
        $this->assertArrayNotHasKey('amount', $f);
        $this->post($url.'/preview', $b)->assertConflict();
        $this->post($url.'/retry', ['lines' => []])->assertRedirect($url)->assertSessionMissing('refund_pending.SALE');
        $posts = Http::recorded(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/preview'));
        $this->assertCount(2, $posts);
        foreach ($posts as [$r]) {
            $this->assertSame($f, $r->data());
        }
    }

    public function test_voucher_creation_scopes_funding_whitelists_and_preserves_unknown_payload(): void
    {
        $org = (string) Str::ulid();
        $store = (string) Str::ulid();
        $url = '/app/organizations/'.$org.'/vouchers';
        $b = ['operation_id' => (string) Str::uuid(), 'code' => 'SAVE', 'funding_organization_id' => $org, 'kind' => 'fixed', 'value' => 1000, 'maximum_discount' => 1000, 'minimum_subtotal' => 0, 'usage_limit' => 1, 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-05', 'store_ids' => [$store], 'restore_policy' => 'never', 'terms' => 'Own funding'];
        $n = 0;
        $this->fake(function ($r) use (&$n, $org, $b) {
            $n++;

            return $n === 1 ? Http::response([], 429) : Http::response(['data' => [...$b, 'id' => 'V1', 'organization_id' => $org, 'version' => 1, 'product_ids' => []]]);
        });
        $this->withSession($this->auth())->post($url, [...$b, 'used_count' => 0])->assertRedirect($url)->assertSessionHas('voucher_pending.'.$org.'.uncertain', true);
        $f = session('voucher_pending.'.$org.'.body');
        $this->assertArrayNotHasKey('used_count', $f);
        $this->assertSame([], $f['product_ids']);
        $this->post($url.'/retry', ['value' => 1])->assertRedirect($url)->assertSessionMissing('voucher_pending.'.$org);
        foreach (Http::recorded() as [$r]) {
            $this->assertSame($f, $r->data());
        }
    }
}
