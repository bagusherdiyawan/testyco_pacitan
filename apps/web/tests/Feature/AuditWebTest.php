<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuditWebTest extends TestCase
{
    private function sessionData(): array
    {
        return ['api_token' => 'fixture-private-token', 'api_expires_at' => time() + 3600, 'api_user' => ['id' => 1, 'name' => 'Lead']];
    }

    private function payload(array $changes = []): array
    {
        return array_replace_recursive([
            'organization' => ['id' => 'O1', 'name' => 'Mitra <script>bad</script>'],
            'visibility' => 'organization', 'observed_at' => '2026-10-30T03:00:00Z',
            'data' => [['id' => 'A1', 'organization_id' => 'O1', 'action' => 'product.updated', 'subject_id' => 'P1', 'actor_id' => 7, 'occurred_at' => '2026-10-29T17:00:00+00:00', 'before' => json_encode(['name' => '<script>history</script>', 'wholesale_price' => 18000]), 'after' => ['name' => '<img src=x onerror=bad()>', 'wholesale_price' => 20000]]],
            'total' => 151, 'per_page' => 50, 'current_page' => 3,
            'next_page_url' => 'https://untrusted.test/collect?token=leak',
        ], $changes);
    }

    public function test_login_is_required_and_api_denial_or_revocation_is_preserved(): void
    {
        Http::preventStrayRequests();
        $path = '/app/organizations/O1/audit';
        $this->get($path)->assertRedirect('/login');
        Http::fake(['*' => Http::sequence()->push(['message' => 'Denied'], 403)->push(['message' => 'Expired'], 401)]);
        $this->withSession($this->sessionData())->get($path)->assertForbidden()->assertDontSee('Tidak ada audit pada filter ini.');
        $this->get($path)->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_query_is_scoped_and_whitelisted_wib_converted_and_pagination_is_local(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->payload(), 200, ['Set-Cookie' => 'backend-secret=not-for-browser'])]);
        $query = ['from_day' => '2026-10-30', 'to_day' => '2026-10-31', 'action' => 'product.updated', 'subject_id' => 'P1', 'actor_id' => 7, 'page' => 3, 'organization_id' => 'other', 'visibility' => 'organization', 'api_token' => 'attacker', 'next_page_url' => 'https://untrusted.test'];
        $response = $this->withSession([...$this->sessionData(), '_old_input' => ['action' => 'unrelated-form']])->get('/app/organizations/O1/audit?'.http_build_query($query))->assertOk()
            ->assertSee('Halaman 3 dari 4')->assertSee('page=4')->assertSee('action=product.updated')->assertSee('value="product.updated"', false)->assertDontSee('unrelated-form')
            ->assertSee('2026-10-30 — 2026-10-31 WIB')->assertSee('30 Oct 2026 00:00:00')->assertSee('Pelaku #7')
            ->assertDontSee('fixture-private-token')->assertDontSee('untrusted.test')->assertDontSee('attacker')->assertDontSee('backend-secret');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('not-for-browser', implode(',', $response->headers->all('set-cookie')));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/v1/organizations/O1/audit'
            && $r->hasHeader('Authorization', 'Bearer fixture-private-token')
            && $r->data() == ['action' => 'product.updated', 'subject_id' => 'P1', 'actor_id' => '7', 'from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-11-01T00:00:00+07:00', 'page' => 3]);
    }

    public function test_snapshots_and_names_are_escaped_and_null_or_legacy_json_is_readable(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload(['current_page' => 1]))->push($this->payload(['current_page' => 1, 'data' => [['actor_id' => null, 'before' => null, 'after' => 'legacy <svg onload=bad()>']]]))]);
        $this->withSession($this->sessionData())->get('/app/organizations/O1/audit')->assertOk()
            ->assertSee('Perubahan sebelum / sesudah')->assertSee('wholesale_price')->assertSee('18000')->assertSee('20000')
            ->assertDontSee('<script>bad</script>', false)->assertDontSee('<script>history</script>', false)->assertDontSee('<img src=x onerror=bad()>', false)->assertSee('&lt;script&gt;history&lt;/script&gt;', false);
        $this->get('/app/organizations/O1/audit')->assertOk()->assertSee('Pelaku tidak tersedia')->assertSee('Tidak ada snapshot')->assertSee('legacy &lt;svg onload=bad()&gt;', false)->assertDontSee('<svg onload=bad()>', false);
    }

    public function test_invalid_calendar_ranges_and_filter_types_do_not_call_api(): void
    {
        Http::fake();
        foreach ([['from_day' => '2026-02-30'], ['from_day' => '2026-10-31', 'to_day' => '2026-10-30'], ['from_day' => '2026-10-01', 'to_day' => '2026-11-01'], ['actor_id' => -1], ['actor_id' => 'bad'], ['page' => 0], ['action' => str_repeat('a', 81)], ['subject_id' => str_repeat('a', 81)], ['action' => ['forged']]] as $params) {
            $this->withSession($this->sessionData())->getJson('/app/organizations/O1/audit?'.http_build_query($params))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_onboarding_scope_and_empty_history_are_distinct_from_unavailable_or_mismatched_data(): void
    {
        $empty = $this->payload(['visibility' => 'onboarding', 'current_page' => 1, 'total' => 0]);
        $empty['data'] = [];
        Http::fake(['*' => Http::sequence()->push($empty)->push($this->payload(['organization' => ['id' => 'OTHER'], 'current_page' => 1]))->push($this->payload(['data' => [['organization_id' => 'OTHER']], 'current_page' => 1]))->push([], 503)]);
        $path = '/app/organizations/O1/audit';
        $this->withSession($this->sessionData())->get($path)->assertOk()->assertSee('hanya data onboarding')->assertSee('Tidak ada audit pada filter ini.')->assertDontSee('wholesale_price');
        foreach (range(1, 3) as $i) {
            $this->get($path)->assertStatus(503)->assertDontSee('Tidak ada audit pada filter ini.')->assertDontSee('wholesale_price');
        }
    }

    public function test_default_day_uses_wib_and_connection_failure_does_not_look_like_an_empty_result(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29T17:00:00Z'));
        try {
            Http::fake(['*' => Http::response($this->payload(['current_page' => 1]))]);
            $this->withSession($this->sessionData())->get('/app/organizations/O1/audit')->assertOk()->assertSee('2026-10-30 — 2026-10-30 WIB');
            Http::assertSent(fn ($r) => $r['from'] === '2026-10-30T00:00:00+07:00' && $r['to'] === '2026-10-31T00:00:00+07:00' && $r['page'] === 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
        Http::fake(fn () => throw new ConnectionException('Unavailable'));
        $this->get('/app/organizations/O1/audit')->assertStatus(503)->assertDontSee('Tidak ada audit pada filter ini.');
    }
}
