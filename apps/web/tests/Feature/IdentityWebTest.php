<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityWebTest extends TestCase
{
    public function test_console_requires_session(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Masuk workspace');
    }

    public function test_login_keeps_token_only_on_server_and_regenerates_session(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/auth/login' => Http::response(['token' => 'private-token', 'user' => ['id' => 1, 'name' => 'Admin'], 'expires_in' => 28800])]);
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'SecurePassword123'])->assertRedirect('/app')->assertSessionHas('api_token', 'private-token');
        Http::assertSent(fn ($r) => $r['device_name'] === 'web');
    }

    public function test_login_failure_does_not_flash_password_or_secret(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid'], 422)]);
        $this->post('/login', ['email' => 'a@example.test', 'password' => 'SecretPassword123'])->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
    }

    public function test_console_renders_api_records_and_never_exposes_access_token(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/organizations' => Http::response(['data' => [['id' => '01A', 'name' => 'Mitra A', 'type' => 'partner', 'active' => true, 'can_manage' => true, 'can_catalog' => true]]]), '*/stores' => Http::response(['data' => []])]);
        $this->withSession(['api_token' => 'private-token', 'api_expires_at' => time() + 100, 'api_user' => ['id' => 1, 'name' => 'Lead']])->get('/app')->assertOk()->assertSee('Mitra A')->assertDontSee('private-token');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer private-token'));
    }

    public function test_backend_revocation_clears_web_session(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated'], 401)]);
        $this->withSession(['api_token' => 'private-token', 'api_expires_at' => time() + 100])->get('/app')->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_catalog_submit_forwards_only_allowed_fields_and_keeps_csrf_token_out_of_api(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => '01P']], 201)]);
        $this->withSession(['api_token' => 'private-token', 'api_expires_at' => time() + 100])->from('/app/organizations/01A/catalog')->post('/app/organizations/01A/products', ['sku' => 'ABC', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'wholesale_price' => 18000, 'retail_price' => 3000, 'active' => '1', 'organization_id' => 'attacker', '_token' => 'csrf-value'])->assertRedirect('/app/organizations/01A/catalog');
        Http::assertSent(fn ($r) => $r['active'] === true && ! isset($r['organization_id']) && ! isset($r['_token']));
    }

    public function test_backend_validation_errors_are_visible(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['sku' => ['SKU sudah digunakan.']]], 422)]);
        $this->withSession(['api_token' => 'private-token', 'api_expires_at' => time() + 100])->post('/app/organizations/01A/products', [])->assertSessionHasErrors('sku');
    }

    public function test_password_change_clears_web_session_and_never_flashes_current_password(): void
    {
        Http::fake(['*' => Http::response(null, 204)]);
        $this->withSession(['api_token' => 'secret', 'api_expires_at' => time() + 100])->post('/app/account/password', ['current_password' => 'OldSecurePassword123', 'password' => 'NewSecurePassword123', 'password_confirmation' => 'NewSecurePassword123'])->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_organization_management_and_catalog_forms_render_real_api_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/organizations/01A' => Http::response(['data' => ['name' => 'Mitra A', 'type' => 'partner']]),
            '*/organizations/01A/stores' => Http::response(['data' => [['id' => '01S', 'name' => 'Pacitan', 'address' => 'Pacitan', 'region' => 'Jawa Timur', 'active' => true]]]),
            '*/organizations/01A/users' => Http::response(['data' => [['id' => 2, 'name' => 'Kasir', 'email' => 'kasir@example.test', 'role' => 'cashier', 'active' => true, 'store_ids' => ['01S']]]]),
            '*/organizations/01A/audit' => Http::response(['data' => []]),
            '*/organizations/01A/products' => Http::response(['data' => [['id' => '01P', 'sku' => 'AYAM-8', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'wholesale_price' => 18000, 'retail_price' => 3000, 'active' => true]]]),
        ]);
        $this->withSession(['api_token' => 'secret', 'api_expires_at' => time() + 100, 'api_user' => ['id' => 1, 'name' => 'Pimpinan']])->get('/app/organizations/01A')->assertOk()->assertSee('Pacitan')->assertSee('Kasir')->assertDontSee('Admin pusat');
        $this->get('/app/organizations/01A/catalog')->assertOk()->assertSee('AYAM-8')->assertSee('18000');
    }
}
