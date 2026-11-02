<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuditExportWebTest extends TestCase
{
    private function auth(): array
    {
        return ['api_token' => 'fixture-private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Lead']];
    }

    private function fake(mixed $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function row(): array
    {
        return ['id' => 'A1', 'organization_id' => 'O1', 'actor_id' => 7, 'action' => 'product.updated', 'subject_id' => 'P1', 'occurred_at' => '2026-10-30T00:00:00+07:00', 'before' => '{"sku":"HISTORICAL8"}', 'after' => null];
    }

    private function payload(array $rows): array
    {
        return ['data' => $rows, 'row_count' => count($rows), 'organization' => ['id' => 'O1', 'name' => 'Org'], 'visibility' => 'organization', 'observed_at' => '2026-10-30T03:00:00Z'];
    }

    private function path(array $params = []): string
    {
        return '/app/organizations/O1/audit/export?'.http_build_query(['from_day' => '2026-10-30', 'to_day' => '2026-10-31', ...$params]);
    }

    private function csv(mixed $response): array
    {
        $text = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($text, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function test_session_required_and_scoped_download_uses_only_whitelisted_filters_and_fixed_headers(): void
    {
        $this->get($this->path())->assertRedirect('/login');
        $this->fake(['*' => Http::response($this->payload([$this->row()]), 200, ['Set-Cookie' => 'api-private=never', 'Content-Disposition' => 'attachment; filename=untrusted.csv'])]);
        $r = $this->withSession($this->auth())->get($this->path(['action' => 'product.updated', 'actor_id' => 7, 'subject_id' => 'P1', 'page' => 99, 'organization' => 'OTHER', 'scope' => 'all', 'token' => 'attacker', 'url' => 'https://untrusted.test']));
        $r->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('audit.csv', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('api-private', implode(',', $r->headers->all('set-cookie')));
        $rows = $this->csv($r);
        $this->assertSame(['audit_id', 'organization_id', 'visibility', 'occurred_at_utc', 'actor_id', 'action', 'subject_id', 'before_json', 'after_json'], $rows[0]);
        $this->assertSame(['A1', 'O1', 'organization', '2026-10-29T17:00:00+00:00', '7', 'product.updated', 'P1', '{"sku":"HISTORICAL8"}', 'null'], $rows[1]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($q) => $q->method() === 'GET' && parse_url($q->url(), PHP_URL_PATH) === '/api/v1/organizations/O1/audit/export' && $q->hasHeader('Authorization', 'Bearer fixture-private') && $q->data() === ['action' => 'product.updated', 'subject_id' => 'P1', 'actor_id' => '7', 'from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-11-01T00:00:00+07:00']);
        $this->assertStringNotContainsString('fixture-private', $r->streamedContent());
    }

    public function test_formula_prefixes_unicode_controls_legacy_null_and_csv_quotes_are_safe_text(): void
    {
        $values = ['=1+1', " \t@SUM(1,2)", "\u{FEFF}＋1", '-cmd', "\nordinary", "legacy, \"quoted\"\nline", '{"safe":"=inside JSON"}'];
        $data = [];
        foreach ($values as $i => $v) {
            $data[] = [...$this->row(), 'id' => 'A'.$i, 'subject_id' => $v, 'before' => $v, 'actor_id' => null];
        }
        $this->fake(['*' => Http::response($this->payload($data))]);
        $r = $this->withSession($this->auth())->get($this->path())->assertOk();
        $csv = $this->csv($r);
        foreach ($values as $i => $v) {
            $expected = $i < 5 ? "'".$v : $v;
            $this->assertSame($expected, $csv[$i + 1][6]);
            $this->assertSame($expected, $csv[$i + 1][7]);
            $this->assertSame('', $csv[$i + 1][4]);
            $this->assertSame('null', $csv[$i + 1][8]);
        }
    }

    public function test_invalid_calendar_pair_range_and_types_never_call_backend(): void
    {
        $this->fake([]);
        $this->withSession($this->auth());
        foreach ([['from_day' => '2026-02-30'], ['from_day' => '2026-10-31', 'to_day' => '2026-10-30'], ['from_day' => '2026-10-01', 'to_day' => '2026-11-01'], ['actor_id' => 'bad'], ['action' => ['array']]] as $params) {
            $this->getJson($this->path($params))->assertUnprocessable();
        }
        $this->getJson('/app/organizations/O1/audit/export?from_day=2026-10-30')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_wrong_org_row_duplicate_metadata_or_oversized_payload_never_becomes_partial_csv(): void
    {
        $base = $this->payload([$this->row()]);
        $variants = [];
        $p = $base;
        $p['organization']['id'] = 'OTHER';
        $variants[] = $p;
        $p = $base;
        $p['data'][0]['organization_id'] = 'OTHER';
        $variants[] = $p;
        $p = $base;
        $p['row_count'] = 0;
        $variants[] = $p;
        $p = $base;
        $p['data'][] = $this->row();
        $p['row_count'] = 2;
        $variants[] = $p;
        $p = $base;
        $p['data'][0]['occurred_at'] = '2026-10-30 00:00:00';
        $variants[] = $p;
        $p = $base;
        $p['data'][0]['occurred_at'] = '2026-02-30T00:00:00Z';
        $variants[] = $p;
        $p = $base;
        $p['data'][0]['occurred_at'] = 'now +00:00';
        $variants[] = $p;
        $p = $base;
        $p['data'][0]['after'] = ['not' => 'raw snapshot'];
        $variants[] = $p;
        $p = $base;
        $p['padding'] = str_repeat('x', 9 * 1024 * 1024);
        $variants[] = $p;
        foreach ($variants as $payload) {
            $this->fake(['*' => Http::response($payload)]);
            $this->withSession($this->auth())->get($this->path())->assertStatus(503)->assertHeaderMissing('Content-Disposition');
        }
    }

    public function test_denial_limit_failure_connection_and_401_do_not_download_empty_success(): void
    {
        foreach ([403, 422, 503] as $status) {
            $this->fake(['*' => Http::response(['message' => 'Denied'], $status)]);
            $this->withSession($this->auth())->getJson($this->path())->assertStatus($status)->assertHeaderMissing('Content-Disposition');
        }
        $this->fake(['*' => Http::response(['errors' => ['export' => ['Persempit filter ekspor.']]], 422)]);
        $this->from('/app/organizations/O1/audit')->get($this->path())->assertRedirect('/app/organizations/O1/audit')->assertSessionHasErrors(['export' => 'Persempit filter ekspor.']);
        $this->fake(fn () => throw new ConnectionException('Lost'));
        $this->get($this->path())->assertStatus(503)->assertHeaderMissing('Content-Disposition');
        $this->fake(['*' => Http::response([], 401)]);
        $this->get($this->path())->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_authorized_onboarding_empty_export_has_header_only_and_reports_server_visibility(): void
    {
        $payload = $this->payload([]);
        $payload['visibility'] = 'onboarding';
        $this->fake(['*' => Http::response($payload)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29T17:00:00Z'));
        try {
            $r = $this->withSession($this->auth())->get('/app/organizations/O1/audit/export')->assertOk();
            $this->assertCount(1, $this->csv($r));
            Http::assertSent(fn ($q) => $q->data() === ['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-10-31T00:00:00+07:00']);
        } finally {
            CarbonImmutable::setTestNow();
        }
        $payload = $this->payload([[...$this->row(), 'action' => 'user.reactivated', 'before' => '{"active":false}']]);
        $payload['visibility'] = 'onboarding';
        $this->fake(['*' => Http::response($payload)]);
        $this->assertSame('onboarding', $this->csv($this->get($this->path())->assertOk())[1][2]);
    }

    public function test_export_link_uses_applied_filters_and_ignores_page_browser_scope_and_old_input(): void
    {
        $payload = [...$this->payload([$this->row()]), 'current_page' => 2, 'per_page' => 50, 'total' => 51];
        $this->fake(['*' => Http::response($payload)]);
        $r = $this->withSession([...$this->auth(), '_old_input' => ['action' => 'old']])->get('/app/organizations/O1/audit?'.http_build_query(['from_day' => '2026-10-30', 'to_day' => '2026-10-31', 'action' => 'product.updated', 'actor_id' => 7, 'page' => 2, 'scope' => 'all', 'token' => 'untrusted']));
        $r->assertOk()->assertSee('Unduh CSV audit dengan filter aktif');
        preg_match('/href="([^\"]*\/audit\/export\?[^\"]*)"/', $r->getContent(), $match);
        parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $query);
        $this->assertSame(['action' => 'product.updated', 'actor_id' => '7', 'from_day' => '2026-10-30', 'to_day' => '2026-10-31'], $query);
        $r->assertDontSee('token=untrusted')->assertDontSee('action=old');
    }
}
