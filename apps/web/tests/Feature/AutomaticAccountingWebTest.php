<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomaticAccountingWebTest extends TestCase
{
    public function test_policy_review_freezes_mapping_and_retries_exact_request_after_unknown_and_denial(): void
    {
        $form = ['key' => 'posting_policy-', 'operation' => 'posting_policy', 'label' => 'Ajukan pemetaan jurnal otomatis', 'fields' => [['key' => 'cash_account', 'label' => 'Kas', 'kind' => 'choice', 'options' => ['A' => 'Kas disetujui'], 'optional' => false], ['key' => 'note', 'label' => 'Bukti', 'kind' => 'text', 'options' => [], 'optional' => false]], 'fixed' => [], 'subject_id' => null, 'expected_id' => null, 'expected_version' => null];
        $c = ['organization_id' => 'ORG', 'section' => 'posting', 'can_manage' => true, 'allowed_sections' => ['posting', 'posting_events'], 'current_page' => 1, 'next_page_url' => null, 'data' => [], 'forms' => [$form], 'filter_stores' => [], 'filter_materials' => []];
        $requests = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($c, &$requests) {
            if ($r->method() === 'GET') {
                return Http::response($c);
            }
            $requests[] = $r->data();
            $n = count($requests);

            return Http::response($n < 3 ? ['message' => 'Unknown'] : ['data' => ['organization_id' => 'ORG', 'id' => 'POLICY', 'version' => 1, 'operation_id' => $r['operation_id']]], $n === 1 ? 503 : ($n === 2 ? 403 : 201));
        });
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1, 'name' => 'Owner'], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=posting')->assertOk()->assertSee('Kas disetujui')->assertDontSee('Resep produksi');
        $this->post('/app/organizations/ORG/erp/review', ['section' => 'posting', 'form_key' => $form['key'], 'fixed_json' => '[]', 'values' => ['cash_account' => 'A', 'note' => 'Bukti diperiksa']])->assertRedirect();
        $this->assertCount(0, $requests);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->assertNotNull(session('erp_pending.ORG'));
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertNotNull(session('erp_pending.ORG'));
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertSame($requests[0], $requests[1]);
        $this->assertSame($requests[0], $requests[2]);
        $this->assertNull(session('erp_pending.ORG'));
    }

    public function test_source_queue_escapes_evidence_and_rejects_foreign_rows(): void
    {
        $c = ['organization_id' => 'ORG', 'section' => 'posting_events', 'can_manage' => true, 'current_page' => 1, 'next_page_url' => null, 'data' => [['id' => 'E', 'organization_id' => 'ORG', 'summary' => 'Biaya belum diketahui <script>bad</script>', 'forms' => []]], 'forms' => [], 'filter_stores' => [], 'filter_materials' => []];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($c)]);
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=posting_events')->assertOk()->assertDontSee('<script>bad</script>', false);
        $c['data'][0]['organization_id'] = 'OTHER';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($c)]);
        $this->get('/app/organizations/ORG/erp?section=posting_events')->assertStatus(503);
    }

    public function test_wholesale_tax_freight_and_reviewed_base_version_stay_frozen_on_unknown_retry(): void
    {
        $fixed = ['base_policy_id' => 'BASE', 'base_policy_version' => 2];
        $form = ['key' => 'wholesale_policy-', 'operation' => 'wholesale_policy', 'label' => 'Ajukan integrasi jurnal wholesale', 'fields' => [
            ['key' => 'tax_treatment', 'label' => 'Pajak pembelian', 'kind' => 'choice', 'options' => ['recoverable' => 'Dapat dikreditkan', 'clearing' => 'Penampung biaya'], 'optional' => false],
            ['key' => 'freight_treatment', 'label' => 'Ongkir', 'kind' => 'choice', 'options' => ['expense' => 'Beban periode', 'clearing' => 'Penampung biaya'], 'optional' => false],
            ['key' => 'note', 'label' => 'Bukti', 'kind' => 'text', 'options' => [], 'optional' => false]], 'fixed' => $fixed, 'subject_id' => null, 'expected_id' => null, 'expected_version' => null];
        $c = ['organization_id' => 'ORG', 'section' => 'posting', 'can_manage' => true, 'allowed_sections' => ['posting'], 'current_page' => 1, 'next_page_url' => null, 'data' => [], 'forms' => [$form], 'filter_stores' => [], 'filter_materials' => []];
        $sent = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($c, &$sent) {
            if ($r->method() === 'GET') {
                return Http::response($c);
            }
            $sent[] = $r->data();

            return count($sent) === 1 ? Http::response(['message' => 'Unknown'], 503) : Http::response(['data' => ['id' => 'W', 'organization_id' => 'ORG', 'version' => 1, 'operation_id' => $r['operation_id']]], 201);
        });
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=posting')->assertOk()->assertSee('Pembukuan dan jurnal')->assertSee('Dapat dikreditkan');
        $v = ['section' => 'posting', 'form_key' => $form['key'], 'fixed_json' => json_encode($fixed), 'values' => ['tax_treatment' => 'recoverable', 'freight_treatment' => 'expense', 'note' => 'Bukti sendiri']];
        $this->post('/app/organizations/ORG/erp/review', $v)->assertRedirect();
        $this->assertCount(0, $sent);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame(2, $sent[0]['base_policy_version']);
        $this->assertSame('recoverable', $sent[0]['tax_treatment']);
        $this->assertSame('expense', $sent[0]['freight_treatment']);
    }
}
