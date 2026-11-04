<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercialCoordinationWebTest extends TestCase
{
    private function auth(): array
    {
        return ['api_token' => 'PRIVATE-FIXTURE', 'api_user' => ['id' => 1, 'name' => 'Leader'], 'api_expires_at' => time() + 1000];
    }

    private function fake(mixed $f): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($f);
    }

    private function page(array $rows = []): array
    {
        return ['data' => $rows, 'current_page' => 1, 'per_page' => 50, 'last_page' => 1, 'total' => count($rows)];
    }

    public function test_sponsor_form_is_responsive_scoped_and_escapes_contracts(): void
    {
        $options = ['organization_id' => 'ORG', 'organization_type' => 'partner', 'contracts' => [['id' => 'CONTRACT', 'title' => '<script>bad</script>', 'mode' => 'buyout', 'starts_on' => '2026-01-01', 'ends_on' => '2030-01-01']], 'orders' => [], 'has_more_contracts' => false, 'has_more_orders' => false, 'selected_order' => null, 'stores' => [['id' => 'STORE', 'name' => 'Retail']], 'products' => []];
        $this->fake(fn ($r) => Http::response(str_contains($r->url(), '/options') ? $options : ['organization_id' => 'ORG', ...$this->page()]));
        $this->withSession($this->auth())->get('/app/organizations/ORG/commercial-agreements')->assertOk()->assertSee('Bagian diskon dibayar sponsor')->assertDontSee('<script>bad</script>', false)->assertDontSee('PRIVATE-FIXTURE');
        $options['organization_id'] = 'OTHER';
        $this->fake(['*' => Http::response($options)]);
        $this->get('/app/organizations/ORG/commercial-agreements')->assertStatus(503);
    }

    public function test_standing_agreement_unknown_retry_preserves_terms_and_uuid_through_denial(): void
    {
        $org = (string) Str::ulid();
        $url = '/app/organizations/'.$org.'/commercial-agreements';
        $n = 0;
        $this->fake(function ($r) use (&$n) {
            $n++;

            return Http::response([], $n === 1 ? 503 : 403);
        });
        $body = ['operation_id' => (string) Str::uuid(), 'kind' => 'consignment', 'contract_id' => (string) Str::ulid(), 'order_id' => (string) Str::ulid(), 'starts_on' => '2026-11-04', 'ends_on' => '2030-12-31', 'reference' => 'Signed <script>bad</script>', 'terms' => ['basis' => 'net_sales', 'supplier_basis_points' => '3000', 'tax_basis_points' => '0', 'loss_policy' => 'zero_floor', 'bundle_basis' => 'reject', 'movement_policy' => 'original_receipt_store_only', 'rounding' => 'floor_magnitude_per_line', 'refund_policy' => 'reverse_proportionally', 'frequency' => 'continuous', 'data_scope' => 'allocated_receipt_totals']];
        $this->withSession($this->auth())->post($url, $body)->assertRedirect($url)->assertSessionHas('commercial_agreement_pending.'.$org.'.uncertain', true);
        $f = session('commercial_agreement_pending.'.$org.'.body');
        $this->assertSame(3000, $f['terms']['supplier_basis_points']);
        $this->assertSame([], $f['terms']['costs']);
        $this->get($url)->assertOk()->assertDontSee('<script>bad</script>', false);
        $this->post($url.'/retry', ['terms' => ['supplier_basis_points' => 1]])->assertRedirect($url)->assertSessionHas('commercial_agreement_pending.'.$org.'.body', $f);
        foreach (Http::recorded() as [$r]) {
            $this->assertSame($f, $r->data());
        }
        $this->fake(['*' => Http::response([], 401)]);
        $this->post($url.'/retry')->assertRedirect('/login')->assertSessionMissing('commercial_agreement_pending');
    }

    public function test_acceptance_requires_both_financial_and_data_scope_consent(): void
    {
        $url = '/app/organizations/ORG/commercial-agreements/AGREEMENT';
        $b = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'action' => 'accept', 'reason' => 'Reviewed'];
        $this->fake(['*' => Http::response(['data' => ['id' => 'AGREEMENT', 'organization_id' => 'SOURCE', 'partner_id' => 'ORG', 'version' => 2, 'status' => 'accepted']])]);
        $this->withSession($this->auth())->post($url, $b)->assertSessionHasErrors(['consent', 'sharing_consented']);
        Http::assertNothingSent();
        $this->post($url, [...$b, 'consent' => 1, 'sharing_consented' => 1])->assertRedirect($url)->assertSessionMissing('commercial_agreement_pending.ORG');
        Http::assertSent(fn ($r) => $r['consent'] === true && $r['sharing_consented'] === true && ! isset($r['action']));
    }

    public function test_recall_decision_unknown_keeps_actual_revision_evidence_and_actor_scope(): void
    {
        $url = '/app/organizations/ORG/recalls/CAMPAIGN';
        $n = 0;
        $this->fake(function ($r) use (&$n) {
            $n++;

            return Http::response([], $n === 1 ? 503 : 403);
        });
        $b = ['operation_id' => (string) Str::uuid(), 'action' => 'hold', 'target_id' => (string) Str::ulid(), 'version' => 1, 'expected_revision' => 4, 'observed_pieces' => 20, 'evidence_reference' => 'Actual count', 'reason' => 'Goods physically held'];
        $this->withSession($this->auth())->post($url, $b)->assertRedirect($url)->assertSessionHas('recall_pending.ORG.uncertain', true);
        $f = session('recall_pending.ORG.body');
        $this->get($url)->assertOk()->assertSee('Goods physically held');
        $this->post('/app/organizations/ORG/recalls/retry', ['expected_revision' => 99])->assertRedirect($url)->assertSessionHas('recall_pending.ORG.body', $f);
        foreach (Http::recorded() as [$r]) {
            $this->assertSame($f, $r->data());
        }
    }

    public function test_recipient_cannot_render_foreign_target_or_follow_remote_pagination(): void
    {
        $c = ['organization_id' => 'ORG', 'campaign' => ['id' => 'CAMPAIGN', 'instructions' => 'Inspect', 'status' => 'active', 'version' => 1], 'is_source' => false, 'targets' => $this->page([['id' => 'TARGET', 'organization_id' => 'OTHER']])];
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->get('/app/organizations/ORG/recalls/CAMPAIGN')->assertStatus(503);
    }

    public function test_browser_receipt_offers_explicit_thermal_width_and_print_never_posts_a_sale(): void
    {
        $sale = ['id' => 'SALE', 'store_id' => 'STORE', 'store_name' => 'Store', 'store_address' => 'Address', 'cashier_name' => 'Cashier', 'sold_at' => '2026-11-04T00:00:00Z', 'number' => 'POS-1', 'currency' => 'IDR', 'status' => 'completed', 'payment_method' => 'cash', 'subtotal' => 6000, 'discount_amount' => 1000, 'total_amount' => 5000, 'cash_received' => 10000, 'change_amount' => 5000, 'lines' => [['sku' => 'AYAM', 'name' => 'Ayam', 'quantity' => 2, 'unit' => 'piece', 'pieces_per_unit' => 1, 'unit_price' => 3000, 'line_total' => 6000]]];
        $this->fake(['*' => Http::response(['data' => $sale])]);
        $this->withSession($this->auth())->get('/app/pos/sales/SALE')->assertOk()->assertSee('58 mm')->assertSee('80 mm')->assertSee('data-print-receipt', false)->assertSee('5.000');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
    }
}
