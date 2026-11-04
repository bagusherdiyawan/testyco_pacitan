<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LedgerExportWebTest extends TestCase
{
    private const BATCH = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private function auth(): array
    {
        return ['api_token' => 'fixture-private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Manager']];
    }

    private function row(): array
    {
        return ['id' => 'E1', 'store_id' => 'S1', 'inventory_batch_id' => self::BATCH, 'sku' => 'HIST8', 'name' => 'Historical', 'batch_code' => 'LOT-A', 'condition' => 'frozen', 'pack_size' => 8, 'expires_on' => '2030-01-01', 'quantity_pieces' => -8, 'bucket' => 'usable', 'source_type' => 'inventory_open', 'source_id' => 'PHYSICAL-REF', 'actor_id' => 7, 'note' => '-8', 'occurred_at' => '2026-10-30T00:00:00+07:00'];
    }

    private function payload(array $rows): array
    {
        return ['data' => $rows, 'row_count' => count($rows), 'store' => ['id' => 'S1', 'name' => 'A1', 'active' => true], 'observed_at' => '2026-10-30T03:00:00Z'];
    }

    private function fake(mixed $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function path(array $q = []): string
    {
        return '/app/stores/S1/inventory/ledger/export?'.http_build_query(['from_day' => '2026-10-30', 'to_day' => '2026-10-31', ...$q]);
    }

    private function csv(mixed $r): array
    {
        $body = $r->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $s = fopen('php://memory', 'w+');
        fwrite($s, substr($body, 3));
        rewind($s);
        $rows = [];
        while (($row = fgetcsv($s, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($s);

        return $rows;
    }

    public function test_scoped_private_export_sends_applied_filters_only_and_preserves_signed_integer(): void
    {
        $this->get($this->path())->assertRedirect('/login');
        $this->fake(['*' => Http::response($this->payload([$this->row()]), 200, ['Set-Cookie' => 'api-private=never', 'Content-Disposition' => 'attachment; filename=foreign.csv'])]);
        $q = ['batch_id' => self::BATCH, 'source_type' => 'inventory_open', 'bucket' => 'usable', 'q' => 'HIST8', 'page' => 99, 'store_id' => 'OTHER', 'url' => 'https://untrusted.test', 'token' => 'attacker'];
        $r = $this->withSession($this->auth())->get($this->path($q))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('stock-ledger.csv', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('api-private', implode(',', $r->headers->all('set-cookie')));
        $rows = $this->csv($r);
        $this->assertSame(['entry_id', 'store_id', 'batch_id', 'sku', 'name', 'batch_code', 'condition', 'pack_size', 'expires_on', 'occurred_at_utc', 'actor_id', 'source_type', 'source_id', 'bucket', 'quantity_pieces', 'note'], $rows[0]);
        $this->assertSame('-8', $rows[1][14]);
        $this->assertSame("'-8", $rows[1][15]);
        $this->assertSame('2026-10-29T17:00:00+00:00', $rows[1][9]);
        $this->assertSame('8', $rows[1][7]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/v1/stores/S1/inventory/ledger/export'
            && $r->hasHeader('Authorization', 'Bearer fixture-private') && $r->data() == ['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-11-01T00:00:00+07:00', 'batch_id' => self::BATCH, 'source_type' => 'inventory_open', 'bucket' => 'usable', 'q' => 'HIST8']);
    }

    public function test_text_formula_quotes_and_null_are_handled_without_turning_deltas_into_text(): void
    {
        $row = [...$this->row(), 'sku' => "\u{FEFF}=1+1", 'name' => "quoted, \"name\"\nline", 'source_id' => '@SUM(1,2)', 'note' => null, 'quantity_pieces' => 8, 'retail_price' => 'secret-price'];
        $this->fake(['*' => Http::response($this->payload([$row]))]);
        $r = $this->withSession($this->auth())->get($this->path())->assertOk();
        $csv = $this->csv($r);
        $this->assertSame("'\u{FEFF}=1+1", $csv[1][3]);
        $this->assertSame($row['name'], $csv[1][4]);
        $this->assertSame("'@SUM(1,2)", $csv[1][12]);
        $this->assertSame('8', $csv[1][14]);
        $this->assertSame('', $csv[1][15]);
        $this->assertStringNotContainsString('secret-price', $r->streamedContent());
        $this->assertStringNotContainsString('fixture-private', $r->streamedContent());
    }

    public function test_foreign_store_batch_duplicate_wrong_types_times_and_oversized_payload_fail_before_csv(): void
    {
        $base = $this->payload([$this->row()]);
        $cases = [];
        $p = $base;
        $p['store']['id'] = 'OTHER';
        $cases[] = $p;
        $p = $base;
        $p['data'][0]['store_id'] = 'OTHER';
        $cases[] = $p;
        $p = $base;
        $p['data'][0]['inventory_batch_id'] = 'OTHER';
        $cases[] = $p;
        $p = $base;
        $p['data'][0]['quantity_pieces'] = '-8';
        $cases[] = $p;
        $p = $base;
        $p['data'][0]['quantity_pieces'] = 1.5;
        $cases[] = $p;
        $p = $base;
        $p['data'][0]['occurred_at'] = '2026-02-30T00:00:00Z';
        $cases[] = $p;
        $p = $base;
        $p['data'][0]['occurred_at'] = 'now +00:00';
        $cases[] = $p;
        $p = $base;
        $p['row_count'] = 0;
        $cases[] = $p;
        $p = $base;
        $p['data'][] = $this->row();
        $p['row_count'] = 2;
        $cases[] = $p;
        $p = $base;
        $p['padding'] = str_repeat('x', 9 * 1024 * 1024);
        $cases[] = $p;
        foreach ($cases as $payload) {
            $this->fake(['*' => Http::response($payload)]);
            $this->withSession($this->auth())->get($this->path(['batch_id' => self::BATCH]))->assertStatus(503)->assertHeaderMissing('Content-Disposition');
        }
    }

    public function test_invalid_calendar_source_bucket_batch_and_query_do_not_request_data(): void
    {
        $this->fake([]);
        $this->withSession($this->auth());
        foreach ([['from_day' => '2026-02-30'], ['from_day' => '2026-10-01', 'to_day' => '2026-11-01'], ['from_day' => '2026-11-01', 'to_day' => '2026-10-30'], ['source_type' => 'bogus'], ['bucket' => 'bogus'], ['batch_id' => 'bad'], ['q' => ['array']]] as $q) {
            $this->getJson($this->path($q))->assertUnprocessable();
        }
        $this->getJson('/app/stores/S1/inventory/ledger/export?from_day=2026-10-30')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_denial_limit_failure_network_and_revocation_are_not_empty_success(): void
    {
        foreach ([403, 404, 422, 503] as $status) {
            $this->fake(['*' => Http::response(['message' => 'Denied'], $status)]);
            $this->withSession($this->auth())->getJson($this->path())->assertStatus($status)->assertHeaderMissing('Content-Disposition');
        }
        $this->fake(['*' => Http::response(['errors' => ['export' => ['Persempit filter.']]], 422)]);
        $this->from('/app/stores/S1/inventory/ledger')->get($this->path())->assertRedirect('/app/stores/S1/inventory/ledger')->assertSessionHasErrors(['export' => 'Persempit filter.']);
        $this->fake(fn () => throw new ConnectionException('Lost'));
        $this->get($this->path())->assertStatus(503);
        $this->fake(['*' => Http::response([], 401)]);
        $this->get($this->path())->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_default_wib_empty_inactive_read_and_active_filter_link(): void
    {
        $p = $this->payload([]);
        $p['store']['active'] = false;
        $this->fake(['*' => Http::response($p)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29T17:00:00Z'));
        try {
            $r = $this->withSession($this->auth())->get('/app/stores/S1/inventory/ledger/export')->assertOk();
            $this->assertCount(1, $this->csv($r));
            Http::assertSent(fn ($r) => $r->data() === ['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-10-31T00:00:00+07:00']);
        } finally {
            CarbonImmutable::setTestNow();
        }
        $p = [...$this->payload([$this->row()]), 'total' => 201, 'per_page' => 100, 'current_page' => 2];
        $this->fake(['*' => Http::response($p)]);
        $q = ['from_day' => '2026-10-30', 'to_day' => '2026-10-31', 'batch_id' => self::BATCH, 'q' => 'OLD8', 'page' => 2, 'token' => 'attacker'];
        $r = $this->withSession([...$this->auth(), '_old_input' => ['q' => 'unrelated']])->get('/app/stores/S1/inventory/ledger?'.http_build_query($q))->assertOk();
        preg_match('/href="([^\"]*\/ledger\/export\?[^\"]*)"/', $r->getContent(), $matches);
        parse_str(parse_url(html_entity_decode($matches[1]), PHP_URL_QUERY), $filters);
        $this->assertSame(['from_day' => '2026-10-30', 'to_day' => '2026-10-31', 'batch_id' => self::BATCH, 'q' => 'OLD8'], $filters);
    }
}
