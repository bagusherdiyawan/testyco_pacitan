<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BatchExpiryWebTest extends TestCase
{
    private function sessionData(): array
    {
        return ['api_token' => 'fixture-private-token', 'api_expires_at' => time() + 3600];
    }

    private function payload(array $changes = []): array
    {
        return array_replace_recursive([
            'store' => ['id' => 'S1', 'name' => 'Pacitan', 'active' => true], 'observed_at' => '2026-11-01T03:00:00Z',
            'data' => [['id' => 'B1', 'store_id' => 'S1', 'sku' => 'OLD8', 'name' => '<script>history</script>', 'batch_code' => 'LOT-A', 'expires_on' => '2026-11-01', 'condition' => 'frozen', 'pack_size' => 8, 'usable_pieces' => 0, 'opened_pieces' => 0, 'ready_pieces' => 0, 'quarantined_pieces' => 16, 'waste_pieces' => 8]],
            'total' => 201, 'per_page' => 100, 'current_page' => 1, 'next_page_url' => 'https://untrusted.test/collect',
        ], $changes);
    }

    public function test_session_required_api_denial_and_expiry_remain_errors(): void
    {
        $path = '/app/stores/S1/inventory/batch-expiry';
        $this->get($path)->assertRedirect('/login');
        Http::fake(['*' => Http::sequence()->push([], 403)->push([], 401)]);
        $this->withSession($this->sessionData())->get($path)->assertForbidden()->assertDontSee('Tidak ada batch pada filter ini.');
        $this->get($path)->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_calendar_dates_are_whitelisted_inclusive_without_an_invented_horizon_or_31_day_limit(): void
    {
        Http::fake(['*' => Http::response($this->payload(['current_page' => 2]), 200, ['Set-Cookie' => 'backend-secret=not-for-browser'])]);
        $q = ['expires_from' => '2026-11-01', 'expires_to' => '2030-01-01', 'with_balance' => 1, 'q' => 'LOT-A', 'page' => 2, 'store_id' => 'OTHER', 'organization_id' => 'OTHER', 'api_token' => 'attacker'];
        $r = $this->withSession([...$this->sessionData(), '_old_input' => ['q' => 'unrelated-form']])->get('/app/stores/S1/inventory/batch-expiry?'.http_build_query($q))->assertOk()
            ->assertSee('Halaman 2 dari 3')->assertSee('page=3')->assertSee('expires_to=2030-01-01')->assertSee('with_balance=1')->assertSee('q=LOT-A')->assertSee('value="LOT-A"', false)
            ->assertDontSee('unrelated-form')->assertDontSee('fixture-private-token')->assertDontSee('attacker')->assertDontSee('untrusted.test')->assertDontSee('backend-secret');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('not-for-browser', implode(',', $r->headers->all('set-cookie')));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/v1/stores/S1/inventory/batches' && $r->hasHeader('Authorization', 'Bearer fixture-private-token') && $r->data() == ['expires_from' => '2026-11-01', 'expires_to' => '2030-01-01', 'with_balance' => '1', 'q' => 'LOT-A', 'page' => 2]);
    }

    public function test_default_has_no_cutoff_and_upper_bound_alone_is_allowed_with_historical_escaped_metadata(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload())->push($this->payload())]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/inventory/batch-expiry')->assertOk()->assertSee('tanpa batas')->assertSee('Tanggal kedaluwarsa kemasan: 2026-11-01')->assertSee('Isi kemasan historis 8 biji')->assertSee('Karantina 16')->assertSee('Susut kumulatif 8')
            ->assertSee('&lt;script&gt;history&lt;/script&gt;', false)->assertDontSee('<script>history</script>', false)->assertDontSee('wholesale_price')->assertDontSee('retail_price');
        $this->get('/app/stores/S1/inventory/batch-expiry?expires_to=2026-11-01')->assertOk();
        Http::assertSent(fn ($r) => $r->data() == ['page' => 1]);
        Http::assertSent(fn ($r) => $r->data() == ['expires_to' => '2026-11-01', 'page' => 1]);
    }

    public function test_invalid_dates_range_balance_and_query_do_not_call_api(): void
    {
        Http::fake();
        foreach ([['expires_to' => '2026-02-30'], ['expires_from' => '2026-11-02', 'expires_to' => '2026-11-01'], ['page' => 0], ['with_balance' => 'bad'], ['q' => str_repeat('a', 101)], ['q' => ['bad']]] as $q) {
            $this->withSession($this->sessionData())->getJson('/app/stores/S1/inventory/batch-expiry?'.http_build_query($q))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_wrong_store_envelope_or_row_and_server_failure_are_not_empty_results(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload(['store' => ['id' => 'OTHER']]))->push($this->payload(['data' => [['store_id' => 'OTHER']]]))->push([], 503)]);
        $this->withSession($this->sessionData());
        foreach (range(1, 3) as $i) {
            $this->get('/app/stores/S1/inventory/batch-expiry')->assertStatus(503)->assertDontSee('Tidak ada batch pada filter ini.')->assertDontSee('LOT-A');
        }
    }

    public function test_inactive_store_empty_and_connection_failure_are_distinct(): void
    {
        $empty = $this->payload(['store' => ['active' => false], 'total' => 0]);
        $empty['data'] = [];
        Http::fake(['*' => Http::response($empty)]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/inventory/batch-expiry')->assertOk()->assertSee('Toko nonaktif')->assertSee('Tidak ada batch pada filter ini.');
        Http::fake(fn () => throw new ConnectionException('Unavailable'));
        $this->get('/app/stores/S1/inventory/batch-expiry')->assertStatus(503)->assertDontSee('Tidak ada batch pada filter ini.');
    }
}
