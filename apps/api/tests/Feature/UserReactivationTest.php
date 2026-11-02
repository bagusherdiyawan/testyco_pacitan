<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserReactivationTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $type = 'partner'): Organization
    {
        return Organization::create(['name' => 'Organization '.Str::random(5), 'type' => $type]);
    }

    private function user(Organization $org, string $role, bool $active = true): User
    {
        $u = User::factory()->create(['active' => $active]);
        DB::table('organization_user')->insert(['user_id' => $u->id, 'organization_id' => $org->id, 'role' => $role]);

        return $u->refresh();
    }

    private function body(int $version = 1): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'reason' => 'Reviewed return to work'];
    }

    private function path(Organization $o, User $u): string
    {
        return "/api/v1/organizations/$o->id/users/$u->id";
    }

    public function test_reactivation_keeps_assignments_and_password_revokes_tokens_and_replays_historical_ack(): void
    {
        $o = $this->org();
        $lead = $this->user($o, 'partner_lead');
        $u = $this->user($o, 'cashier', false);
        $store = Store::create(['organization_id' => $o->id, 'name' => 'A1']);
        DB::table('store_user')->insert(['user_id' => $u->id, 'store_id' => $store->id]);
        $hash = $u->password;
        $u->createToken('residual');
        Sanctum::actingAs($lead);
        $p = $this->path($o, $u);
        $this->getJson("$p/reactivation")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.can_reactivate', true)->assertJsonPath('data.user.stores.0.id', $store->id)->assertJsonMissingPath('data.user.password');
        $v = $this->body();
        $this->postJson("$p/reactivate", $v)->assertOk()->assertJsonPath('data.user.active', true)->assertJsonPath('data.user.access_version', 2)->assertJsonMissingPath('data.user.password');
        $this->postJson("$p/reactivate", $v)->assertOk()->assertJsonPath('data.user.access_version', 2);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('operation_receipts', 1);
        $this->assertDatabaseHas('store_user', ['user_id' => $u->id, 'store_id' => $store->id]);
        $this->assertSame($hash, $u->refresh()->password);
        $entry = DB::table('audit_entries')->where('action', 'user.reactivated')->sole();
        $this->assertSame(['active' => false, 'access_version' => 1], json_decode($entry->before, true));
        $this->assertSame('Reviewed return to work', json_decode($entry->after, true)['reason']);
        $this->postJson("$p/deactivate")->assertNoContent();
        $this->postJson("$p/reactivate", $v)->assertOk()->assertJsonPath('data.user.active', true)->assertJsonPath('data.user.access_version', 2);
        $this->assertFalse($u->refresh()->active);
        $this->assertSame(3, $u->access_version);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'user.reactivated')->count());
        $this->postJson("$p/reactivate", [...$v, 'reason' => 'Different payload'])->assertConflict();
        $this->postJson("$p/reactivate", $this->body(1))->assertConflict();
        $this->postJson("$p/reactivate", $this->body(3))->assertOk()->assertJsonPath('data.user.access_version', 4);
    }

    public function test_denied_roles_and_foreign_parent_do_not_create_receipts_or_activate(): void
    {
        $o = $this->org();
        $other = $this->org();
        $u = $this->user($o, 'cashier', false);
        $p = $this->path($o, $u);
        foreach (['cashier', 'warehouse', 'store_manager'] as $role) {
            Sanctum::actingAs($this->user($o, $role));
            $this->getJson("$p/reactivation")->assertForbidden();
            $this->postJson("$p/reactivate", $this->body())->assertForbidden();
        }
        Sanctum::actingAs($this->user($other, 'partner_lead'));
        $this->postJson("$p/reactivate", $this->body())->assertForbidden();
        $this->getJson("/api/v1/organizations/$other->id/users/$u->id/reactivation")->assertNotFound();
        $this->postJson("/api/v1/organizations/$other->id/users/$u->id/reactivate", $this->body())->assertNotFound();
        $this->assertFalse($u->refresh()->active);
        $this->assertDatabaseCount('operation_receipts', 0);
    }

    public function test_owner_anywhere_and_self_cannot_change_global_status(): void
    {
        $o = $this->org();
        $center = $this->org('center');
        $actor = $this->user($center, 'admin');
        $u = $this->user($o, 'cashier', false);
        DB::table('organization_user')->insert(['user_id' => $u->id, 'organization_id' => $center->id, 'role' => 'owner']);
        Sanctum::actingAs($actor);
        $p = $this->path($o, $u);
        $this->getJson("$p/reactivation")->assertOk()->assertJsonPath('data.can_reactivate', false);
        $this->postJson("$p/reactivate", $this->body())->assertForbidden();
        $this->postJson("$p/deactivate")->assertForbidden();
        $self = $this->path($center, $actor);
        $this->postJson("$self/reactivate", $this->body())->assertForbidden();
        $this->postJson("$self/deactivate")->assertForbidden();
        $this->patchJson("/api/v1/organizations/$center->id/users/0$actor->id/membership", ['role' => 'admin', 'store_ids' => []])->assertForbidden();
        $this->postJson("/api/v1/organizations/$center->id/users/0$actor->id/reactivate", $this->body())->assertForbidden();
        $this->assertSame(1, $actor->refresh()->access_version);
        $this->assertDatabaseCount('operation_receipts', 0);
    }

    public function test_global_multi_org_reactivation_requires_central_admin_and_active_review_org(): void
    {
        $o = $this->org();
        $other = $this->org();
        $center = $this->org('center');
        $u = $this->user($o, 'cashier', false);
        DB::table('organization_user')->insert(['user_id' => $u->id, 'organization_id' => $other->id, 'role' => 'store_manager']);
        Sanctum::actingAs($this->user($o, 'partner_lead'));
        $p = $this->path($o, $u);
        $this->getJson("$p/reactivation")->assertOk()->assertJsonPath('data.can_reactivate', false);
        $this->postJson("$p/reactivate", $this->body())->assertForbidden();
        $this->postJson("$p/deactivate")->assertForbidden();
        Sanctum::actingAs($this->user($center, 'admin'));
        $o->update(['active' => false]);
        $this->getJson("$p/reactivation")->assertOk()->assertJsonPath('data.can_reactivate', false);
        $this->postJson("$p/reactivate", $this->body())->assertConflict();
        $this->assertDatabaseCount('operation_receipts', 0);
        $o->update(['active' => true]);
        $this->postJson("$p/reactivate", $this->body())->assertOk();
        $this->assertDatabaseHas('organization_user', ['user_id' => $u->id, 'organization_id' => $other->id, 'role' => 'store_manager']);
    }

    public function test_membership_changes_invalidate_review_and_activation_does_not_change_role_or_assignments(): void
    {
        $o = $this->org();
        $u = $this->user($o, 'cashier', false);
        $s = Store::create(['organization_id' => $o->id, 'name' => 'New assignment']);
        Sanctum::actingAs($this->user($o, 'partner_lead'));
        $p = $this->path($o, $u);
        $this->getJson("$p/reactivation")->assertJsonPath('data.user.access_version', 1);
        $this->patchJson("$p/membership", ['role' => 'store_manager', 'store_ids' => [$s->id]])->assertNoContent();
        $this->assertFalse($u->refresh()->active);
        $this->assertSame(2, $u->access_version);
        $this->postJson("$p/reactivate", $this->body(1))->assertConflict();
        $this->getJson("$p/reactivation")->assertJsonPath('data.user.role', 'store_manager')->assertJsonPath('data.user.stores.0.id', $s->id);
        $this->postJson("$p/reactivate", [...$this->body(2), 'role' => 'owner', 'store_ids' => []])->assertOk();
        $this->assertDatabaseHas('organization_user', ['user_id' => $u->id, 'role' => 'store_manager']);
        $this->assertDatabaseHas('store_user', ['user_id' => $u->id, 'store_id' => $s->id]);
    }

    public function test_input_active_state_and_used_migration_guard_and_onboarding_audit(): void
    {
        $o = $this->org();
        $u = $this->user($o, 'cashier');
        $center = $this->org('center');
        Sanctum::actingAs($this->user($center, 'owner'));
        $p = $this->path($o, $u);
        $this->getJson("$p/reactivation")->assertJsonPath('data.can_reactivate', false);
        $this->postJson("$p/reactivate", $this->body())->assertConflict();
        foreach ([[], ['version' => 0], ['operation_id' => 'bad'], ['reason' => ''], ['reason' => str_repeat('x', 2001)]] as $patch) {
            $body = $patch === [] ? [] : [...$this->body(), ...$patch];
            $this->postJson("$p/reactivate", $body)->assertUnprocessable();
        }
        $this->postJson("$p/deactivate")->assertNoContent();
        $this->postJson("$p/reactivate", $this->body(2))->assertOk();
        Audit::record(null, $o->id, 'sale.completed', 'unrelated', null, ['retail_price' => 999]);
        $this->getJson("/api/v1/organizations/$o->id/audit?action=user.reactivated")->assertOk()->assertJsonPath('visibility', 'onboarding')->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.password');
        $this->getJson("/api/v1/organizations/$o->id/audit?action=sale.completed")->assertJsonPath('total', 0);
        $migration = require database_path('migrations/2026_11_02_000007_add_user_access_version.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_old_real_tokens_do_not_resurrect_and_user_must_login_again(): void
    {
        $o = $this->org();
        $lead = $this->user($o, 'partner_lead');
        $u = $this->user($o, 'cashier');
        $old = $u->createToken('old')->plainTextToken;
        $admin = $lead->createToken('admin')->plainTextToken;
        $password = 'StrongPassword123';
        $u->forceFill(['password' => $password])->save();
        $p = $this->path($o, $u);
        $this->withToken($admin)->postJson("$p/deactivate")->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($admin)->postJson("$p/reactivate", $this->body(2))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => $password, 'device_name' => 'fresh'])->assertOk()->assertJsonPath('user.active', true)->assertJsonMissingPath('user.password');
    }
}
