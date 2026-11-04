<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryControlsWebTest extends TestCase
{
    private function auth(): array
    {
        return ['api_token' => 'PRIVATE-TOKEN', 'api_user' => ['id' => 1, 'name' => 'Manager'], 'api_expires_at' => time() + 1000];
    }

    private function fake(mixed $f): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($f);
    }

    private function page(array $rows = []): array
    {
        return ['data' => $rows, 'current_page' => 1, 'per_page' => 50, 'total' => count($rows), 'last_page' => 1];
    }

    public function test_mfa_login_clears_old_account_pending_and_issues_session_only_after_second_factor(): void
    {
        $this->fake(function ($r) {
            return str_ends_with($r->url(), '/auth/login') ? Http::response(['requires_mfa' => true, 'challenge' => str_repeat('c', 64), 'expires_in' => 120]) : Http::response(['token' => 'NEW-TOKEN', 'user' => ['id' => 2, 'name' => 'Second'], 'expires_in' => 28800]);
        });
        $this->withSession([...$this->auth(), 'food_pending' => ['OLD' => ['body' => 'private']]])->post('/login', ['email' => 'second@example.test', 'password' => 'ExamplePassword123'])->assertRedirect('/login/mfa')->assertSessionMissing('api_token')->assertSessionMissing('food_pending');
        $this->get('/login/mfa')->assertOk()->assertDontSee(str_repeat('c', 64))->assertDontSee('ExamplePassword123');
        $this->post('/login/mfa', ['code' => '123456'])->assertRedirect('/app')->assertSessionHas('api_user.id', 2)->assertSessionMissing('mfa_challenge');
        $this->fake(['*' => Http::response(['token' => 'THIRD-TOKEN', 'user' => ['id' => 3, 'name' => 'Third'], 'expires_in' => 28800])]);
        $this->withSession(['mfa_enrollment' => ['encrypted' => 'OLD-SECRET'], 'food_pending' => ['OLD' => 'private']])->post('/login', ['email' => 'third@example.test', 'password' => 'ExamplePassword123'])->assertRedirect('/app')->assertSessionHas('api_user.id', 3)->assertSessionMissing('mfa_enrollment')->assertSessionMissing('food_pending');
    }

    public function test_password_reset_preserves_confirmation_and_never_flashes_sensitive_inputs(): void
    {
        $this->fake(['*' => Http::response([], 204)]);
        $this->get('/reset-password')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('/reset-password.js', false);
        $this->post('/reset-password', ['email' => 'user@example.test', 'token' => 'RESET-FIXTURE', 'password' => 'ResetPassword123', 'password_confirmation' => 'ResetPassword123', 'code' => '654321'])->assertRedirect('/login');
        Http::assertSent(fn ($r) => $r['password_confirmation'] === 'ResetPassword123' && $r['token'] === 'RESET-FIXTURE');
        $this->assertFalse(session()->has('_old_input.password'));
    }

    public function test_mfa_enrollment_session_is_encrypted_and_unknown_enable_clears_session(): void
    {
        $this->fake(['*' => Http::response(['data' => ['secret' => 'SECRET-FIXTURE', 'recovery_codes' => array_fill(0, 10, 'RECOVERY-FIXTURE'), 'version' => 1]])]);
        $this->withSession($this->auth())->post('/app/account/security/mfa/enroll', ['current_password' => 'ExamplePassword123'])->assertRedirect('/app/account/security');
        $raw = json_encode(session('mfa_enrollment'));
        $this->assertStringNotContainsString('SECRET-FIXTURE', $raw);
        $this->assertStringNotContainsString('RECOVERY-FIXTURE', $raw);
        $this->fake(['*' => Http::response([], 503)]);
        $this->post('/app/account/security/mfa/enable', ['code' => '123456', 'codes_saved' => 1])->assertRedirect('/login')->assertSessionMissing('mfa_enrollment')->assertSessionMissing('api_token');
    }

    public function test_inbox_validates_actor_escapes_text_and_ignores_external_pagination(): void
    {
        $c = [...$this->page([['id' => 'NOTICE', 'title' => '<script>alert(1)</script>', 'local_path' => '/app/organizations/ORG', 'event_type' => 'food.recalled', 'created_at' => '2026-11-04', 'read_at' => null]]), 'user_id' => 1, 'unread_count' => 1, 'preferences' => ['version' => 0, 'email_enabled' => false], 'smtp_configured' => false, 'next_page_url' => 'https://evil.invalid/token'];
        $this->fake(['*' => Http::response($c)]);
        $this->withSession($this->auth())->get('/app/inbox')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('evil.invalid')->assertDontSee('PRIVATE-TOKEN');
        $c['user_id'] = 2;
        $this->fake(['*' => Http::response($c)]);
        $this->get('/app/inbox')->assertStatus(503);
    }

    public function test_food_unknown_retry_keeps_same_sop_and_uuid_through_denial(): void
    {
        $url = '/app/organizations/ORG/food-safety';
        $n = 0;
        $this->fake(function ($r) use (&$n) {
            $n++;

            return Http::response([], $n === 1 ? 503 : 403);
        });
        $body = ['operation_id' => (string) Str::uuid(), 'action' => 'policy', 'version' => 0, 'enabled' => 1, 'block_expired' => 1, 'fefo' => 'enforce', 'ready_life_minutes' => 60, 'reference' => 'Actual approved SOP'];
        $this->withSession($this->auth())->post($url, $body)->assertRedirect($url)->assertSessionHas('food_pending.ORG.uncertain', true);
        $f = session('food_pending.ORG.body');
        $this->get($url)->assertOk()->assertSee('Actual approved SOP');
        $this->post($url.'/retry', ['ready_life_minutes' => 999])->assertRedirect($url)->assertSessionHas('food_pending.ORG.body', $f);
        foreach (Http::recorded() as [$r]) {
            $this->assertSame($f, $r->data());
        }
        $this->fake(['*' => Http::response([], 401)]);
        $this->post($url.'/retry')->assertRedirect('/login')->assertSessionMissing('food_pending');
    }

    public function test_asset_unknown_create_preserves_identity_and_rejects_wrong_tenant_ack(): void
    {
        $url = '/app/organizations/ORG/assets';
        $n = 0;
        $b = ['operation_id' => (string) Str::uuid(), 'number' => 'ASSET-1', 'kind' => 'freezer', 'brand' => 'Brand', 'serial_number' => 'SERIAL1', 'condition_note' => 'Checked', 'ownership_reference' => 'Invoice original'];
        $this->fake(function ($r) use (&$n, $b) {
            $n++;

            return $n === 1 ? Http::response([], 503) : Http::response(['data' => [...$b, 'id' => 'ASSET', 'organization_id' => 'OTHER', 'version' => 1]]);
        });
        $this->withSession($this->auth())->post($url, $b)->assertRedirect($url)->assertSessionHas('asset_create_pending.ORG.uncertain', true);
        $f = session('asset_create_pending.ORG.body');
        $this->get($url)->assertOk()->assertSee('ASSET-1');
        $this->post($url.'/retry', ['serial_number' => 'EDIT'])->assertRedirect($url)->assertSessionHas('asset_create_pending.ORG.body', $f);
        foreach (Http::recorded() as [$r]) {
            $this->assertSame($f, $r->data());
        }
    }

    public function test_offline_conflict_review_requires_verified_cash_and_preserves_unknown_body(): void
    {
        $id = (string) Str::ulid();
        $url = '/app/stores/STORE/pos/offline';
        $origin = (string) Str::uuid();
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response(['data' => ['id' => $id, 'store_id' => 'STORE', 'status' => 'conflict', 'version' => 1, 'operation_id' => $origin]]) : Http::response([], 503));
        $b = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Actual return of physical cash', 'reconciliation_reference' => 'REF1'];
        $this->withSession($this->auth())->post($url.'/'.$id.'/void', $b)->assertSessionHasErrors('verified');
        $this->post($url.'/'.$id.'/void', [...$b, 'verified' => 1])->assertRedirect($url)->assertSessionHas('offline_review_pending.STORE.uncertain', true);
        $f = session('offline_review_pending.STORE.body');
        $this->post($url.'/retry', ['reason' => 'EDIT'])->assertRedirect($url)->assertSessionHas('offline_review_pending.STORE.body', $f);
        foreach (Http::recorded(fn ($r) => $r->method() === 'POST') as [$r]) {
            $this->assertSame($f, $r->data());
        }
    }

    public function test_commercial_csv_protects_formula_and_rejects_cross_org_rows(): void
    {
        $row = ['id' => 'DOC', 'organization_id' => 'ORG', 'source_type' => 'pos_sale', 'number' => '=DANGEROUS', 'occurred_at' => '2026-11-04T00:00:00Z', 'method' => 'cash', 'gross_amount' => 1000, 'discount_amount' => 0, 'refund_credit_amount' => 0, 'net_goods_amount' => 1000, 'cost_version' => 0, 'goods_cost' => null, 'operating_cost' => null, 'profit_amount' => null];
        $c = ['organization_id' => 'ORG', 'side' => 'seller', 'currency' => 'IDR', 'basis' => 'document_cohort_current_adjustments', 'data' => [$row], 'metrics' => [], 'balances' => [], 'observed_at' => '2026-11-04T00:00:00Z'];
        $this->fake(['*' => Http::response($c)]);
        $r = $this->withSession($this->auth())->get('/app/organizations/ORG/reports/commercial/export?from_day=2026-11-04&to_day=2026-11-04')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("'=DANGEROUS", $r->streamedContent());
        $c['data'][0]['organization_id'] = 'OTHER';
        $this->fake(['*' => Http::response($c)]);
        $this->get('/app/organizations/ORG/reports/commercial/export?from_day=2026-11-04&to_day=2026-11-04')->assertStatus(503);
    }
}
