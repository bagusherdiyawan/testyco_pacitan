<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdentityCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $name = 'Mitra A', string $type = 'partner'): Organization
    {
        return Organization::create(compact('name', 'type'));
    }

    private function user(Organization $org, string $role, array $stores = []): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        foreach ($stores as $s) {
            DB::table('store_user')->insert(['store_id' => $s->id, 'user_id' => $u->id]);
        }

        return $u;
    }

    private function store(Organization $o, string $name = 'A1'): Store
    {
        return Store::create(['organization_id' => $o->id, 'name' => $name]);
    }

    private function productData(array $overrides = []): array
    {
        return [...['sku' => 'AYAM-8', 'name' => 'Dimsum ayam', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'wholesale_price' => 18000, 'retail_price' => 3000, 'active' => true], ...$overrides];
    }

    public function test_business_endpoints_require_authentication_even_without_accept_header(): void
    {
        $this->get('/api/v1/stores')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_login_issues_expiring_hashed_token_and_logout_revokes_only_current_token(): void
    {
        $u = User::factory()->create(['active' => true, 'password' => 'StrongPassword123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'StrongPassword123', 'device_name' => 'test'])->assertOk()->assertJsonMissingPath('user.password')->json('token');
        $row = DB::table('personal_access_tokens')->first();
        $this->assertNotEquals($token, $row->token);
        $this->assertNotNull($row->expires_at);
        $other = $u->createToken('other', ['*'], now()->addHour())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        // Clear Sanctum's cached guard between HTTP requests in the same test process.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_inactive_unknown_and_wrong_password_fail_with_same_message(): void
    {
        $u = User::factory()->create(['active' => false, 'password' => 'StrongPassword123']);
        foreach ([[$u->email, 'StrongPassword123'], ['unknown@example.test', 'StrongPassword123'], [$u->email, 'wrong']] as [$email,$password]) {
            $this->postJson('/api/v1/auth/login', compact('email', 'password') + ['device_name' => 'test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $u = User::factory()->create(['active' => true]);
        $token = $u->createToken('old', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/stores')->assertUnauthorized();
    }

    public function test_disabled_account_loses_existing_token(): void
    {
        $u = User::factory()->create(['active' => true]);
        $token = $u->createToken('test')->plainTextToken;
        $u->forceFill(['active' => false])->save();
        $this->withToken($token)->getJson('/api/v1/stores')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_store_lists_are_scoped_to_organization_and_cashier_assignment(): void
    {
        $a = $this->organization();
        $b = $this->organization('Mitra B');
        $a1 = $this->store($a);
        $a2 = $this->store($a, 'A2');
        $b1 = $this->store($b, 'B1');
        Sanctum::actingAs($this->user($a, 'partner_lead'));
        $this->getJson('/api/v1/stores')->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $b1->id]);
        Sanctum::actingAs($this->user($a, 'cashier', [$a1]));
        $this->getJson('/api/v1/stores')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a1->id);
        $this->getJson("/api/v1/stores/$a2->id/catalog")->assertForbidden();
        $this->getJson("/api/v1/stores/$b1->id/catalog")->assertForbidden();
    }

    public function test_center_admin_can_onboard_partners_but_cannot_read_their_business_catalog(): void
    {
        $center = $this->organization('Pusat', 'center');
        Sanctum::actingAs($owner = $this->user($center, 'owner'));
        $id = $this->postJson('/api/v1/organizations', ['name' => 'Partner baru'])->assertCreated()->json('data.id');
        $store = $this->postJson("/api/v1/organizations/$id/stores", ['name' => 'Toko baru'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/stores/$store/catalog")->assertForbidden();
        $this->getJson("/api/v1/organizations/$id/products")->assertForbidden();
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $owner->id, 'organization_id' => $id, 'action' => 'store.created']);
    }

    public function test_partner_lead_cannot_create_organizations_or_manage_another_partner(): void
    {
        $a = $this->organization();
        $b = $this->organization('B');
        Sanctum::actingAs($this->user($a, 'partner_lead'));
        $this->postJson('/api/v1/organizations', ['name' => 'Unauthorized'])->assertForbidden();
        $this->getJson("/api/v1/organizations/$b->id/users")->assertForbidden();
        $this->getJson("/api/v1/organizations/$b->id/audit")->assertForbidden();
        $this->postJson("/api/v1/organizations/$b->id/stores", ['name' => 'bad'])->assertForbidden();
    }

    public function test_user_provisioning_validates_role_store_ownership_and_ignores_mass_assignment(): void
    {
        $a = $this->organization();
        $b = $this->organization('B');
        $s = $this->store($a);
        $other = $this->store($b);
        Sanctum::actingAs($this->user($a, 'partner_lead'));
        $data = ['name' => 'Kasir', 'email' => 'kasir@example.test', 'password' => 'SecurePassword123', 'role' => 'cashier', 'store_ids' => [$s->id], 'active' => false];
        $this->postJson("/api/v1/organizations/$a->id/users", [...$data, 'role' => 'owner'])->assertUnprocessable();
        $this->postJson("/api/v1/organizations/$a->id/users", [...$data, 'store_ids' => [$other->id]])->assertUnprocessable();
        $id = $this->postJson("/api/v1/organizations/$a->id/users", $data)->assertCreated()->assertJsonMissingPath('data.password')->json('data.id');
        $u = User::findOrFail($id);
        $this->assertTrue($u->active);
        $this->assertTrue(Hash::check('SecurePassword123', $u->password));
        $this->assertDatabaseHas('store_user', ['user_id' => $id, 'store_id' => $s->id]);
        $audit = DB::table('audit_entries')->where('action', 'user.created')->first();
        $this->assertStringNotContainsString('SecurePassword123', $audit->after);
    }

    public function test_membership_changes_revoke_tokens_and_cannot_escalate_or_cross_assign(): void
    {
        $o = $this->organization();
        $a = $this->store($o);
        $b = $this->store($o, 'A2');
        $cashier = $this->user($o, 'cashier', [$a]);
        $cashier->createToken('old');
        Sanctum::actingAs($this->user($o, 'partner_lead'));
        $url = "/api/v1/organizations/$o->id/users/$cashier->id/membership";
        $this->patchJson($url, ['role' => 'owner', 'store_ids' => [$b->id]])->assertUnprocessable();
        $this->patchJson($url, ['role' => 'store_manager', 'store_ids' => [$b->id]])->assertNoContent();
        $this->assertDatabaseMissing('store_user', ['user_id' => $cashier->id, 'store_id' => $a->id]);
        $this->assertDatabaseHas('store_user', ['user_id' => $cashier->id, 'store_id' => $b->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disabled_organization_prevents_existing_member_access_to_catalog(): void
    {
        $o = $this->organization();
        $s = $this->store($o);
        $u = $this->user($o, 'partner_lead');
        $o->update(['active' => false]);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/stores')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/stores/$s->id/catalog")->assertForbidden();
    }

    public function test_disabling_store_keeps_history_and_blocks_new_operational_requests(): void
    {
        $o = $this->organization();
        $s = $this->store($o);
        Sanctum::actingAs($this->user($o, 'partner_lead'));
        $this->patchJson("/api/v1/stores/$s->id", ['active' => false])->assertOk();
        $this->getJson("/api/v1/stores/$s->id/catalog")->assertConflict();
        $this->getJson('/api/v1/stores')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_entries', ['action' => 'store.updated', 'subject_id' => $s->id]);
    }

    public function test_cashier_cannot_manage_users_stores_prices_or_audit(): void
    {
        $o = $this->organization();
        $s = $this->store($o);
        Sanctum::actingAs($this->user($o, 'cashier', [$s]));
        $this->getJson("/api/v1/organizations/$o->id/users")->assertForbidden();
        $this->postJson("/api/v1/organizations/$o->id/stores", ['name' => 'Bad'])->assertForbidden();
        $this->postJson("/api/v1/organizations/$o->id/products", $this->productData())->assertForbidden();
        $this->getJson("/api/v1/organizations/$o->id/audit")->assertForbidden();
    }

    public function test_catalog_crud_is_scoped_and_preserves_disabled_products_without_exposing_wholesale_to_cashier(): void
    {
        $o = $this->organization();
        $other = $this->organization('Other');
        $s = $this->store($o);
        $cashier = $this->user($o, 'cashier', [$s]);
        Sanctum::actingAs($lead = $this->user($o, 'partner_lead'));
        $id = $this->postJson("/api/v1/organizations/$o->id/products", [...$this->productData(), 'organization_id' => $other->id])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('products', ['id' => $id, 'organization_id' => $o->id]);
        $this->postJson("/api/v1/organizations/$o->id/products", $this->productData())->assertUnprocessable();
        Sanctum::actingAs($this->user($other, 'partner_lead'));
        $this->putJson("/api/v1/organizations/$other->id/products/$id", $this->productData())->assertNotFound();
        $this->putJson("/api/v1/organizations/$o->id/products/$id", $this->productData())->assertForbidden();
        Sanctum::actingAs($cashier);
        $this->getJson("/api/v1/stores/$s->id/catalog")->assertOk()->assertJsonPath('data.0.retail_price', 3000)->assertJsonMissingPath('data.0.wholesale_price');
        Sanctum::actingAs($lead);
        $this->putJson("/api/v1/organizations/$o->id/products/$id", $this->productData(['active' => false, 'pack_size' => 10]))->assertOk();
        $this->getJson("/api/v1/organizations/$o->id/products")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/stores/$s->id/catalog")->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_entries', ['action' => 'product.updated', 'subject_id' => $id]);
    }

    public function test_catalog_rejects_negative_money_fractional_quantity_and_invalid_units(): void
    {
        $o = $this->organization();
        Sanctum::actingAs($this->user($o, 'partner_lead'));
        foreach ([['retail_price' => -1], ['pack_size' => 0], ['portion_size' => 1.5], ['condition' => 'invalid']] as $invalid) {
            $this->postJson("/api/v1/organizations/$o->id/products", $this->productData($invalid))->assertUnprocessable();
        }
    }

    public function test_deactivation_preserves_user_audit_and_revokes_sessions(): void
    {
        $o = $this->organization();
        $u = $this->user($o, 'cashier');
        $u->createToken('existing');
        Sanctum::actingAs($lead = $this->user($o, 'partner_lead'));
        $this->postJson("/api/v1/organizations/$o->id/users/$u->id/deactivate")->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $u->id, 'active' => false]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson("/api/v1/organizations/$o->id/users/$lead->id/deactivate")->assertForbidden();
    }

    public function test_password_change_requires_current_password_revokes_all_tokens_and_logs_no_passwords(): void
    {
        $o = $this->organization();
        $u = $this->user($o, 'partner_lead');
        $u->forceFill(['password' => 'OldSecurePassword123'])->save();
        $u->createToken('first');
        $u->createToken('second');
        Sanctum::actingAs($u);
        $payload = ['current_password' => 'wrong', 'password' => 'NewSecurePassword456', 'password_confirmation' => 'NewSecurePassword456'];
        $this->postJson('/api/v1/auth/password', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->postJson('/api/v1/auth/password', [...$payload, 'current_password' => 'OldSecurePassword123'])->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('NewSecurePassword456', $u->fresh()->password));
        $audit = DB::table('audit_entries')->where('action', 'user.password_changed')->first();
        $this->assertNull($audit->before);
        $this->assertNull($audit->after);
    }

    public function test_center_management_audit_does_not_leak_partner_prices(): void
    {
        $center = $this->organization('Pusat', 'center');
        $owner = $this->user($center, 'owner');
        $partner = $this->organization();
        $lead = $this->user($partner, 'partner_lead');
        Sanctum::actingAs($lead);
        $this->postJson("/api/v1/organizations/$partner->id/products", $this->productData())->assertCreated();
        $this->getJson("/api/v1/organizations/$partner->id/audit")->assertJsonCount(1, 'data');
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/organizations/$partner->id/audit")->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/organizations/$center->id", ['active' => false])->assertConflict();
    }

    public function test_bootstrap_has_no_default_password_and_refuses_to_overwrite_existing_center(): void
    {
        $this->artisan('identity:bootstrap', ['--name' => 'Pemilik', '--email' => 'owner@example.test'])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil dan angka)', 'BootstrapPassword123')
            ->expectsQuestion('Ulangi kata sandi', 'BootstrapPassword123')->assertSuccessful();
        $this->assertDatabaseHas('organizations', ['type' => 'center']);
        $this->assertDatabaseHas('organization_user', ['role' => 'owner']);
        $this->artisan('identity:bootstrap', ['--name' => 'Other', '--email' => 'other@example.test'])->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }
}
