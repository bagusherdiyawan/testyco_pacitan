<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserReactivationWebTest extends TestCase
{
    private function fake(mixed $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function auth(): array
    {
        return ['api_token' => 'fixture-private', 'api_user' => ['id' => 1, 'name' => 'Lead'], 'api_expires_at' => time() + 1000];
    }

    private function context(bool $can = true): array
    {
        return ['organization' => ['id' => 'ORG', 'name' => 'Mitra <script>bad</script>', 'active' => true], 'user' => ['id' => 2, 'name' => '<img src=x onerror=bad>', 'email' => 'member@example.test', 'active' => false, 'access_version' => 1, 'role' => 'cashier', 'membership_count' => 1, 'stores' => [['id' => 'S1', 'organization_id' => 'ORG', 'name' => 'Toko <b>snapshot</b>', 'active' => true]]], 'can_reactivate' => $can];
    }

    private function body(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Reviewed return to work'];
    }

    private function ack(): array
    {
        return ['data' => ['organization_id' => 'ORG', 'user' => ['id' => 2, 'active' => true, 'access_version' => 2]]];
    }

    private string $show = '/app/organizations/ORG/users/2/reactivation';

    private string $post = '/app/organizations/ORG/users/2/reactivate';

    private string $slot = 'user_reactivation_pending.2';

    public function test_review_requires_session_and_escapes_scoped_snapshot_and_capability(): void
    {
        $this->get($this->show)->assertRedirect('/login');
        Http::preventStrayRequests();
        $this->fake(['*' => Http::response(['data' => $this->context()])]);
        $this->withSession($this->auth())->get($this->show)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Konfirmasi reaktivasi akun')->assertSee('&lt;img', false)->assertDontSee('<img', false)->assertDontSee('fixture-private')->assertSee('Versi akses 1');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/organizations/ORG/users/2/reactivation') && $r->hasHeader('Authorization', 'Bearer fixture-private'));
        $this->fake(['*' => Http::response(['data' => $this->context(false)])]);
        $this->get($this->show)->assertOk()->assertDontSee('Konfirmasi reaktivasi akun');
    }

    public function test_organization_lists_review_link_for_inactive_nonowner_member_only(): void
    {
        $this->fake([
            '*/organizations/ORG' => Http::response(['data' => ['id' => 'ORG', 'name' => 'Mitra', 'type' => 'partner']]),
            '*/organizations/ORG/stores' => Http::response(['data' => []]),
            '*/organizations/ORG/audit' => Http::response(['data' => []]),
            '*/organizations/ORG/users' => Http::response(['data' => [
                ['id' => 2, 'name' => 'Member', 'email' => 'member@example.test', 'role' => 'cashier', 'active' => false],
                ['id' => 3, 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'owner', 'active' => false],
                ['id' => 1, 'name' => 'Self', 'email' => 'self@example.test', 'role' => 'partner_lead', 'active' => false],
            ]]),
        ]);
        $this->withSession($this->auth())->get('/app/organizations/ORG')->assertOk()
            ->assertSee('/app/organizations/ORG/users/2/reactivation', false)
            ->assertDontSee('/app/organizations/ORG/users/3/reactivation', false)
            ->assertDontSee('/app/organizations/ORG/users/1/reactivation', false);
    }

    public function test_foreign_envelope_and_denial_are_not_rendered_as_success(): void
    {
        $this->withSession($this->auth());
        foreach (['organization', 'user'] as $part) {
            $d = $this->context();
            $d[$part]['id'] = 'OTHER';
            $this->fake(['*' => Http::response(['data' => $d])]);
            $this->get($this->show)->assertStatus(503);
        }
        $d = $this->context();
        $d['user']['stores'][0]['organization_id'] = 'OTHER';
        $this->fake(['*' => Http::response(['data' => $d])]);
        $this->get($this->show)->assertStatus(503);
        $this->fake(['*' => Http::response(['message' => 'Forbidden'], 403)]);
        $this->get($this->show)->assertForbidden();
    }

    public function test_confirmation_rechecks_version_and_sends_only_validated_payload(): void
    {
        Http::preventStrayRequests();
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response(['data' => $this->context()]) : Http::response($this->ack()));
        $body = $this->body();
        $this->withSession($this->auth())->post($this->post, [...$body, 'role' => 'owner', 'active' => true, 'url' => 'https://untrusted.test'])->assertRedirect($this->show)->assertSessionMissing($this->slot);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === $body && str_ends_with($r->url(), '/organizations/ORG/users/2/reactivate'));
    }

    public function test_unknown_keeps_exact_review_uuid_body_through_later_denial_and_ignores_retry_input(): void
    {
        Http::preventStrayRequests();
        $count = 0;
        $this->fake(function ($r) use (&$count) {
            if ($r->method() === 'GET') {
                return Http::response(['data' => $this->context()]);
            }
            $count++;

            return match ($count) {
                1 => Http::response([], 503), 2 => Http::response(['message' => 'Denied'], 403), default => Http::response($this->ack())
            };
        });
        $body = $this->body();
        $this->withSession($this->auth())->post($this->post, $body)->assertRedirect($this->show)->assertSessionHas($this->slot.'.uncertain', true);
        $this->get($this->show)->assertOk()->assertSee('Ulangi request yang sama')->assertSee('Reviewed return to work')->assertDontSee('Konfirmasi reaktivasi akun')->assertDontSee('fixture-private');
        $this->post($this->post, [...$body, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->post('/app/organizations/OTHER/users/02/reactivate', $body)->assertConflict();
        $this->get('/app/organizations/OTHER/users/02/reactivation')->assertRedirect($this->show);
        $this->post($this->show.'/retry', ['version' => 99, 'reason' => 'tampered'])->assertRedirect($this->show)->assertSessionHas($this->slot.'.body', $body);
        $this->post($this->show.'/retry', ['operation_id' => (string) Str::uuid()])->assertRedirect($this->show)->assertSessionMissing($this->slot);
        Http::assertSentCount(4);
        foreach (Http::recorded() as [$r]) {
            if ($r->method() === 'POST') {
                $this->assertSame($body, $r->data());
            }
        }
    }

    public function test_bad_ack_is_unknown_and_401_clears_session_without_restoring_payload(): void
    {
        foreach (['scope', 'version', 'user', 'active'] as $field) {
            $result = $this->ack();
            if ($field === 'scope') {
                $result['data']['organization_id'] = 'OTHER';
            }
            if ($field === 'version') {
                $result['data']['user']['access_version'] = 999;
            }
            if ($field === 'user') {
                $result['data']['user']['id'] = 999;
            }
            if ($field === 'active') {
                $result['data']['user']['active'] = false;
            }
            $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response(['data' => $this->context()]) : Http::response($result));
            $this->withSession($this->auth())->post($this->post, $this->body())->assertRedirect($this->show)->assertSessionHas($this->slot.'.uncertain', true);
            $this->fake(['*' => Http::response([], 401)]);
            $this->post($this->show.'/retry')->assertRedirect('/login')->assertSessionMissing('api_token')->assertSessionMissing('user_reactivation_pending');
        }
    }

    public function test_invalid_input_stale_review_and_known_error_do_not_hold_new_requests(): void
    {
        Http::preventStrayRequests();
        $this->withSession($this->auth())->post($this->post, [...$this->body(), 'reason' => ''])->assertSessionHasErrors('reason');
        Http::assertNothingSent();
        $d = $this->context();
        $d['user']['access_version'] = 2;
        $this->fake(['*' => Http::response(['data' => $d])]);
        $this->post($this->post, $this->body())->assertConflict()->assertSessionMissing($this->slot);
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response(['data' => $this->context()]) : Http::response(['errors' => ['reason' => ['Denied input']]], 422));
        $this->post($this->post, $this->body())->assertSessionHasErrors('reason')->assertSessionMissing($this->slot);
    }

    public function test_throttle_is_uncertain_and_successful_account_switch_discards_old_pending(): void
    {
        $this->fake(fn ($r) => $r->method() === 'GET' ? Http::response(['data' => $this->context()]) : Http::response([], 429));
        $this->withSession($this->auth())->post($this->post, $this->body())->assertRedirect($this->show)->assertSessionHas($this->slot.'.uncertain', true);
        $this->fake(['*/auth/login' => Http::response(['token' => 'new-fixture', 'user' => ['id' => 9], 'expires_in' => 1000])]);
        $this->post('/login', ['email' => 'other@example.test', 'password' => 'ExamplePassword123'])->assertRedirect('/app')->assertSessionMissing('user_reactivation_pending');
    }
}
