<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function test_invoice_valuation_retry_preserves_source_id_version_and_note_through_unknown_and_denial(): void
    {
        $id = (string) Str::ulid();
        $fixed = ['id' => $id, 'version' => 2];
        $form = ['key' => 'invoice_revalue_retry-'.$id, 'operation' => 'invoice_revalue_retry', 'label' => 'Antrekan ulang nilai invoice', 'fields' => [['key' => 'note', 'label' => 'Bukti pemeriksaan', 'kind' => 'text', 'options' => [], 'optional' => false]], 'fixed' => $fixed, 'subject_id' => $id, 'expected_id' => $id, 'expected_version' => 3];
        $c = ['organization_id' => 'ORG', 'section' => 'invoice_variances', 'can_manage' => true, 'allowed_sections' => ['invoice_variances'], 'current_page' => 1, 'next_page_url' => null, 'forms' => [], 'data' => [['id' => $id, 'organization_id' => 'ORG', 'summary' => 'Periode asal harus diperiksa <script>bad</script>', 'forms' => [$form]]], 'filter_stores' => [], 'filter_materials' => []];
        $sent = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($c, $id, &$sent) {
            if ($r->method() === 'GET') {
                return Http::response($c);
            }
            $sent[] = $r->data();
            $n = count($sent);

            return Http::response($n < 3 ? ['message' => 'Unknown'] : ['data' => ['id' => $id, 'organization_id' => 'ORG', 'version' => 3, 'operation_id' => $r['operation_id']]], $n === 1 ? 503 : ($n === 2 ? 403 : 201));
        });
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=invoice_variances')->assertOk()->assertSee('Pembukuan dan jurnal')->assertDontSee('<script>bad</script>', false);
        $this->post('/app/organizations/ORG/erp/review', ['section' => 'invoice_variances', 'form_key' => $form['key'], 'fixed_json' => json_encode($fixed), 'values' => ['note' => 'Periode dan bukti asli diperiksa']])->assertRedirect();
        $this->assertCount(0, $sent);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertNotNull(session('erp_pending.ORG'));
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame($sent[0], $sent[2]);
        $this->assertSame($id, $sent[0]['id']);
        $this->assertSame(2, $sent[0]['version']);
        $this->assertNull(session('erp_pending.ORG'));
    }

    public function test_closing_independent_approval_preserves_reviewed_snapshot_through_unknown_and_denial(): void
    {
        $id = (string) Str::ulid();
        $fixed = ['id' => $id, 'version' => 2, 'decision' => 'approve'];
        $form = ['key' => 'closing_decide-'.$id, 'operation' => 'closing_decide', 'label' => 'Setujui penutupan buku', 'fields' => [['key' => 'note', 'label' => 'Bukti pemeriksaan', 'kind' => 'text', 'options' => [], 'optional' => false]], 'fixed' => $fixed, 'subject_id' => $id, 'expected_id' => $id, 'expected_version' => 3];
        $c = ['organization_id' => 'ORG', 'section' => 'closings', 'can_manage' => true, 'allowed_sections' => ['closings'], 'current_page' => 1, 'next_page_url' => null, 'forms' => [], 'data' => [['id' => $id, 'organization_id' => 'ORG', 'summary' => 'Snapshot laba Rp220 harus diperiksa <script>bad</script>', 'forms' => [$form]]], 'filter_stores' => [], 'filter_materials' => []];
        $sent = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($c, $id, &$sent) {
            if ($r->method() === 'GET') {
                return Http::response($c);
            }
            $sent[] = $r->data();
            $n = count($sent);

            return Http::response($n < 3 ? ['message' => 'Unknown'] : ['data' => ['id' => $id, 'organization_id' => 'ORG', 'version' => 3, 'operation_id' => $r['operation_id']]], $n === 1 ? 503 : ($n === 2 ? 403 : 201));
        });
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=closings')->assertOk()->assertSee('Pembukuan dan jurnal')->assertDontSee('<script>bad</script>', false);
        $this->post('/app/organizations/ORG/erp/review', ['section' => 'closings', 'form_key' => $form['key'], 'fixed_json' => json_encode($fixed), 'values' => ['note' => 'Buku dan akun laba ditahan diperiksa']])->assertRedirect();
        $this->assertCount(0, $sent);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertNotNull(session('erp_pending.ORG'));
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame($sent[0], $sent[2]);
        $this->assertSame($id, $sent[0]['id']);
        $this->assertSame(2, $sent[0]['version']);
        $this->assertNull(session('erp_pending.ORG'));
    }

    public function test_commercial_recognition_and_reviewed_base_stay_frozen_on_unknown_retry(): void
    {
        $fixed = ['base_policy_id' => 'BASE', 'base_policy_version' => 2];
        $form = ['key' => 'commercial_policy-', 'operation' => 'commercial_policy', 'label' => 'Ajukan integrasi GL bagi hasil dan sponsor', 'fields' => [
            ['key' => 'tax_treatment', 'label' => 'Pajak pembelian', 'kind' => 'choice', 'options' => ['recoverable' => 'Dapat dikreditkan', 'clearing' => 'Penampung biaya'], 'optional' => false],
            ['key' => 'recognition_basis', 'label' => 'Pengakuan', 'kind' => 'choice', 'options' => ['contractual_rights' => 'Hak kontraktual', 'clearing' => 'Penampung biaya'], 'optional' => false],
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
        $v = ['section' => 'posting', 'form_key' => $form['key'], 'fixed_json' => json_encode($fixed), 'values' => ['tax_treatment' => 'recoverable', 'recognition_basis' => 'contractual_rights', 'note' => 'Bukti sendiri']];
        $this->post('/app/organizations/ORG/erp/review', $v)->assertRedirect();
        $this->assertCount(0, $sent);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame(2, $sent[0]['base_policy_version']);
        $this->assertSame('recoverable', $sent[0]['tax_treatment']);
        $this->assertSame('contractual_rights', $sent[0]['recognition_basis']);
    }

    public function test_financial_statement_period_filter_is_available_and_scope_guard_remains(): void
    {
        foreach (['income_statement', 'balance_sheet'] as $section) {
            $periodId = (string) Str::ulid();
            $c = ['organization_id' => 'ORG', 'section' => $section, 'can_manage' => true, 'allowed_sections' => [$section], 'current_page' => 1, 'next_page_url' => null, 'forms' => [], 'data' => [['id' => 'TOTAL', 'organization_id' => 'ORG', 'summary' => 'Laba periode Rp220', 'forms' => []]], 'filter_stores' => [], 'filter_materials' => [], 'filter_periods' => [$periodId => 'Oktober 2026'], 'selected_period_id' => $periodId];
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($c)]);
            $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section='.$section.'&period_id='.$periodId)->assertOk()->assertSee('name="period_id"', false)->assertSee('Oktober 2026')->assertSee('Laba periode Rp220')->assertDontSee('name="store_id"', false);
            $c['data'][0]['organization_id'] = 'OTHER';
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($c)]);
            $this->get('/app/organizations/ORG/erp?section='.$section.'&period_id='.$periodId)->assertStatus(503);
        }
    }

    public function test_consignor_retry_preserves_source_id_version_and_note_through_unknown_and_denial(): void
    {
        $id = (string) Str::ulid();
        $fixed = ['id' => $id, 'version' => 2];
        $form = ['key' => 'consignor_retry-'.$id, 'operation' => 'consignor_retry', 'label' => 'Periksa ulang aktivasi konsinyasi', 'fields' => [['key' => 'note', 'label' => 'Bukti pemeriksaan', 'kind' => 'text', 'options' => [], 'optional' => false]], 'fixed' => $fixed, 'subject_id' => $id, 'expected_id' => $id, 'expected_version' => 3];
        $c = ['organization_id' => 'ORG', 'section' => 'consignor_values', 'can_manage' => true, 'allowed_sections' => ['consignor_values'], 'current_page' => 1, 'next_page_url' => null, 'forms' => [], 'data' => [['id' => $id, 'organization_id' => 'ORG', 'summary' => 'Periode asal harus diperiksa <script>bad</script>', 'forms' => [$form]]], 'filter_stores' => [], 'filter_materials' => []];
        $sent = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($c, $id, &$sent) {
            if ($r->method() === 'GET') {
                return Http::response($c);
            }
            $sent[] = $r->data();
            $n = count($sent);

            return Http::response($n < 3 ? ['message' => 'Unknown'] : ['data' => ['id' => $id, 'organization_id' => 'ORG', 'version' => 3, 'operation_id' => $r['operation_id']]], $n === 1 ? 503 : ($n === 2 ? 403 : 201));
        });
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=consignor_values')->assertOk()->assertSee('Pembukuan dan jurnal')->assertDontSee('<script>bad</script>', false);
        $this->post('/app/organizations/ORG/erp/review', ['section' => 'consignor_values', 'form_key' => $form['key'], 'fixed_json' => json_encode($fixed), 'values' => ['note' => 'Periode dan bukti asli diperiksa']])->assertRedirect();
        $this->assertCount(0, $sent);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertNotNull(session('erp_pending.ORG'));
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame($sent[0], $sent[2]);
        $this->assertSame($id, $sent[0]['id']);
        $this->assertSame(2, $sent[0]['version']);
        $this->assertNull(session('erp_pending.ORG'));
    }

    public function test_consignor_queue_escapes_evidence_and_rejects_foreign_rows(): void
    {
        $c = ['organization_id' => 'ORG', 'section' => 'consignor_values', 'can_manage' => true, 'current_page' => 1, 'next_page_url' => null, 'data' => [['id' => 'E', 'organization_id' => 'ORG', 'summary' => 'Biaya belum diketahui <script>bad</script>', 'forms' => []]], 'forms' => [], 'filter_stores' => [], 'filter_materials' => []];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($c)]);
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=consignor_values')->assertOk()->assertDontSee('<script>bad</script>', false);
        $c['data'][0]['organization_id'] = 'OTHER';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($c)]);
        $this->get('/app/organizations/ORG/erp?section=consignor_values')->assertStatus(503);
    }
}
