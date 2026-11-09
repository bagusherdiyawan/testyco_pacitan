<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountingOperatorWebTest extends TestCase
{
    private function auth(): array
    {
        return ['api_token' => 'private-test', 'api_user' => ['id' => 1, 'name' => 'Owner'], 'api_expires_at' => time() + 1000];
    }

    private function fake($f): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($f);
    }

    private function base(string $section): array
    {
        return ['organization_id' => 'ORG', 'section' => $section, 'can_manage' => true, 'data' => [], 'forms' => [], 'current_page' => 1, 'next_page_url' => null, 'filter_stores' => [], 'filter_materials' => []];
    }

    private function f($key, $kind, $options = []): array
    {
        return ['key' => $key, 'label' => $key, 'kind' => $kind, 'options' => $options, 'optional' => false];
    }

    public function test_manual_journal_fields_review_before_post_and_use_exact_money_maps(): void
    {
        $c = $this->base('journals');
        $c['forms'] = [['key' => 'journal-', 'operation' => 'journal', 'label' => 'Ajukan jurnal manual', 'fields' => [$this->f('debits', 'money_map', ['CASH' => 'Kas', 'CAPITAL' => 'Modal']), $this->f('credits', 'money_map', ['CASH' => 'Kas', 'CAPITAL' => 'Modal']), $this->f('evidence', 'text')], 'fixed' => [], 'subject_id' => null, 'expected_id' => null, 'expected_version' => null]];
        $sent = [];
        $this->fake(function ($r) use ($c, &$sent) {
            if ($r->method() === 'GET') {
                return Http::response($c);
            }$sent[] = $r->data();

            return Http::response(['data' => ['id' => 'J1', 'organization_id' => 'ORG', 'version' => 1, 'operation_id' => $r['operation_id']]], 201);
        });
        $this->withSession($this->auth())->get('/app/organizations/ORG/erp?section=journals')->assertOk()->assertSee('Ajukan jurnal manual')->assertDontSee('private-test');
        $this->post('/app/organizations/ORG/erp/review', ['section' => 'journals', 'form_key' => 'journal-', 'fixed_json' => '[]', 'values' => ['debits' => ['CASH' => '1000', 'CAPITAL' => ''], 'credits' => ['CASH' => '', 'CAPITAL' => '1000'], 'evidence' => 'Bukti awal']])->assertRedirect();
        $this->assertCount(0, $sent);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->assertCount(1, $sent);
        $this->assertSame(['CASH' => '1000'], $sent[0]['debits']);
        $this->assertSame(['CAPITAL' => '1000'], $sent[0]['credits']);
    }

    public function test_operator_view_keeps_store_navigation_and_rejects_financial_forms(): void
    {
        $store = (string) Str::ulid();
        $c = $this->base('productions');
        $c['can_manage'] = false;
        $c['can_operate'] = true;
        $c['allowed_sections'] = ['productions', 'lots', 'recipes'];
        $c['filter_stores'] = [$store => 'Gudang produksi'];
        $c['data'] = [['id' => 'P', 'organization_id' => 'ORG', 'store_id' => $store, 'summary' => 'Rencana produksi', 'forms' => []]];
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->get('/app/organizations/ORG/erp?section=productions&store_id='.$store)->assertOk()->assertSee('Rencana produksi')->assertDontSee('Jurnal debit/kredit')->assertSee('store_id='.$store, false);
        $c['forms'] = [['key' => 'bad', 'operation' => 'journal', 'label' => 'Invalid', 'fields' => [], 'fixed' => []]];
        $this->fake(['*' => Http::response($c)]);
        $this->get('/app/organizations/ORG/erp?section=productions&store_id='.$store)->assertStatus(503);
    }

    public function test_trial_balance_has_explicit_period_and_preserves_escaped_results(): void
    {
        $id = (string) Str::ulid();
        $c = $this->base('trial_balance');
        $c['filter_periods'] = [$id => 'November2026'];
        $c['data'] = [['id' => 'TOTAL', 'organization_id' => 'ORG', 'summary' => 'Debit 1000 = kredit 1000 <script>bad</script>', 'forms' => []]];
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->get('/app/organizations/ORG/erp?section=trial_balance&period_id='.$id)->assertOk()->assertSee('November2026')->assertSee('Debit 1000')->assertDontSee('<script>bad</script>', false);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r['period_id'] === $id);
    }
}
