<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManufacturingErpWebTest extends TestCase
{
    private function auth(): array
    {
        return ['api_token' => 'private-erp-token', 'api_user' => ['id' => 1, 'name' => 'Owner'], 'api_expires_at' => time() + 1000];
    }

    private function fake(mixed $responses): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function context(): array
    {
        return ['organization_id' => 'ORG', 'section' => 'materials', 'can_manage' => true, 'current_page' => 1, 'next_page_url' => 'https://evil.test/private', 'total' => 31, 'per_page' => 30, 'forms' => [['key' => 'material-', 'operation' => 'material', 'label' => 'Daftarkan bahan', 'fields' => [['key' => 'name', 'label' => 'Nama bahan', 'kind' => 'text', 'optional' => false, 'options' => []]], 'fixed' => [], 'subject_id' => null, 'expected_id' => null, 'expected_version' => null]], 'filter_stores' => [], 'filter_materials' => [], 'data' => [['id' => 'M', 'organization_id' => 'ORG', 'summary' => 'Terigu <script>bad()</script>', 'forms' => []]]];
    }

    public function test_scoped_native_like_forms_escape_snapshots_and_keep_pagination_local(): void
    {
        $this->fake(['*' => Http::response($this->context())]);
        $this->withSession($this->auth())->get('/app/organizations/ORG/erp')->assertOk()->assertSee('Bahan baku, pembelian dan produksi')->assertSee('Terigu')->assertDontSee('<script>bad()</script>', false)->assertDontSee('evil.test')->assertDontSee('private-erp-token')->assertSee('page=2', false);
        $c = $this->context();
        $c['data'][0]['organization_id'] = 'FOREIGN';
        $this->fake(['*' => Http::response($c)]);
        $this->get('/app/organizations/ORG/erp')->assertStatus(503);
    }

    public function test_review_does_not_write_then_unknown_retry_keeps_exact_payload_through_later_denial(): void
    {
        $bodies = [];
        $this->fake(function ($r) use (&$bodies) {
            if ($r->method() === 'GET') {
                return Http::response($this->context());
            }
            $bodies[] = $r->data();

            return count($bodies) === 1 ? Http::response([], 503) : (count($bodies) === 2 ? Http::response(['message' => 'Denied'], 403) : Http::response(['data' => ['id' => 'M', 'organization_id' => 'ORG', 'version' => 1, 'operation_id' => $r['operation_id']]], 201));
        });
        $this->withSession($this->auth())->post('/app/organizations/ORG/erp/review', ['form_key' => 'material-', 'fixed_json' => '[]', 'values' => ['name' => 'Terigu']])->assertRedirect()->assertSessionHas('erp_candidate.ORG');
        $this->assertCount(0, $bodies);
        $this->get('/app/organizations/ORG/erp')->assertOk()->assertSee('Tinjauan sebelum dikirim')->assertSee('Terigu');
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect()->assertSessionHas('erp_pending.ORG');
        $this->get('/app/organizations/ORG/erp')->assertOk()->assertSee('Hasil belum pasti')->assertDontSee('Tinjau sebelum mengirim');
        $this->post('/app/organizations/ORG/erp/edit')->assertStatus(409);
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect()->assertSessionHas('erp_pending.ORG');
        $this->post('/app/organizations/ORG/erp/retry')->assertRedirect()->assertSessionMissing('erp_pending.ORG');
        $this->assertCount(3, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame($bodies[0], $bodies[2]);
    }

    public function test_changed_fixed_version_or_unknown_action_cannot_be_submitted(): void
    {
        $this->fake(['*' => Http::response($this->context())]);
        $this->withSession($this->auth())->post('/app/organizations/ORG/erp/review', ['form_key' => 'material-', 'fixed_json' => '{"version":99}', 'values' => ['name' => 'Terigu']])->assertStatus(409);
        $this->post('/app/organizations/ORG/erp/review', ['form_key' => 'unknown', 'fixed_json' => '[]', 'values' => ['name' => 'Terigu']])->assertStatus(409);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_quantity_and_money_maps_use_authorized_labels_and_keep_explicit_zero(): void
    {
        $c = $this->context();
        $c['forms'][0]['operation'] = 'purchase';
        $c['forms'][0]['key'] = 'purchase-';
        $c['forms'][0]['fields'] = [['key' => 'quantities', 'label' => 'Jumlah', 'kind' => 'quantity_map', 'optional' => false, 'options' => ['FLOUR' => 'Terigu (kg)', 'EGG' => 'Telur (pcs)']], ['key' => 'amounts', 'label' => 'Total harga', 'kind' => 'money_map', 'optional' => false, 'options' => ['FLOUR' => 'Terigu (kg)', 'EGG' => 'Telur (pcs)']]];
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->post('/app/organizations/ORG/erp/review', ['form_key' => 'purchase-', 'fixed_json' => '[]', 'values' => ['quantities' => ['FLOUR' => '1.25', 'EGG' => ''], 'amounts' => ['FLOUR' => '0', 'EGG' => '']]])->assertRedirect()->assertSessionHas('erp_candidate.ORG.body.quantities', ['FLOUR' => '1.25'])->assertSessionHas('erp_candidate.ORG.body.amounts', ['FLOUR' => '0']);
        $this->get('/app/organizations/ORG/erp')->assertOk()->assertSee('Terigu (kg): 1.25')->assertSee('Terigu (kg): 0');
    }

    public function test_401_clears_pending_and_changed_actor_cannot_replay(): void
    {
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response($this->context()) : Http::response([], 401));
        $this->withSession($this->auth())->post('/app/organizations/ORG/erp/review', ['form_key' => 'material-', 'fixed_json' => '[]', 'values' => ['name' => 'Terigu']])->assertRedirect();
        $this->post('/app/organizations/ORG/erp/confirm')->assertRedirect('/login')->assertSessionMissing('api_token')->assertSessionMissing('erp_pending.ORG');
        $this->withSession($this->auth())->post('/app/organizations/ORG/erp/review', ['form_key' => 'material-', 'fixed_json' => '[]', 'values' => ['name' => 'Terigu']])->assertRedirect();
        $this->withSession(['api_user' => ['id' => 2, 'name' => 'Other']])->post('/app/organizations/ORG/erp/confirm')->assertForbidden();
        Http::assertSentCount(3);
    }
}
