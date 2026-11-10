<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DistributionSettlementWebTest extends TestCase
{
    public function test_missing_cost_agreement_keeps_consent_source_and_version_through_unknown_and_denial(): void
    {
        $id = (string) Str::ulid();
        $fixed = ['id' => $id, 'version' => 2, 'decision' => 'approve'];
        $form = ['key' => 'missing_decide-'.$id, 'operation' => 'missing_decide', 'label' => 'Setujui pembagian biaya hilang', 'fields' => [['key' => 'consent', 'label' => 'Porsi dan nominal diperiksa', 'kind' => 'consent', 'options' => [], 'optional' => false], ['key' => 'note', 'label' => 'Bukti pemeriksaan', 'kind' => 'text', 'options' => [], 'optional' => false]], 'fixed' => $fixed, 'subject_id' => $id, 'expected_id' => $id, 'expected_version' => 3];
        $c = ['organization_id' => 'ORG', 'section' => 'distribution_settlements', 'can_manage' => true, 'allowed_sections' => ['distribution_settlements'], 'current_page' => 1, 'next_page_url' => null, 'forms' => [], 'data' => [['id' => $id, 'organization_id' => 'ORG', 'summary' => 'Periode asal harus diperiksa <script>bad</script>', 'forms' => [$form]]], 'filter_stores' => [], 'filter_materials' => []];
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
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=distribution_settlements')->assertOk()->assertSee('Pembukuan dan jurnal')->assertDontSee('<script>bad</script>', false);
        $this->post('/app/organizations/ORG/erp/review', ['section' => 'distribution_settlements', 'form_key' => $form['key'], 'fixed_json' => json_encode($fixed), 'values' => ['consent' => '1', 'note' => 'Periode dan bukti asli diperiksa']])->assertRedirect();
        $this->assertCount(0, $sent);
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect();
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertNotNull(session('erp_pending.ORG'));
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect();
        $this->assertSame($sent[0], $sent[1]);
        $this->assertSame($sent[0], $sent[2]);
        $this->assertSame($id, $sent[0]['id']);
        $this->assertSame(2, $sent[0]['version']);
        $this->assertTrue($sent[0]['consent']);
        $this->assertSame('approve', $sent[0]['decision']);
        $this->assertNull(session('erp_pending.ORG'));
    }

    public function test_shared_loss_and_private_custody_rows_escape_evidence_and_reject_foreign_scope(): void
    {
        $c = ['organization_id' => 'ORG', 'section' => 'distribution_settlements', 'can_manage' => true, 'current_page' => 1, 'next_page_url' => null, 'data' => [['id' => 'E', 'organization_id' => 'ORG', 'summary' => 'Biaya belum diketahui <script>bad</script>', 'forms' => []]], 'forms' => [], 'filter_stores' => [], 'filter_materials' => []];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($c)]);
        $this->withSession(['api_token' => 'private', 'api_user' => ['id' => 1], 'api_expires_at' => time() + 1000])->get('/app/organizations/ORG/erp?section=distribution_settlements')->assertOk()->assertDontSee('<script>bad</script>', false);
        $c['data'][0]['organization_id'] = 'OTHER';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($c)]);
        $this->get('/app/organizations/ORG/erp?section=distribution_settlements')->assertStatus(503);
    }
}
