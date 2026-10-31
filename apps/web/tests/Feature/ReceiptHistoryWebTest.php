<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReceiptHistoryWebTest extends TestCase
{
    private function sessionData(): array
    {
        return ['api_token' => 'fixture-private-token', 'api_expires_at' => time() + 3600, 'api_user' => ['id' => 1, 'name' => 'Cashier']];
    }

    private function payload(array $changes = []): array
    {
        return array_replace_recursive([
            'store' => ['id' => 'S1', 'name' => 'Toko sekarang', 'active' => true], 'scope' => 'own',
            'data' => [['id' => 'SALE1', 'store_id' => 'S1', 'number' => 'POS-OLD', 'store_name' => 'Nama toko historis', 'cashier_name' => 'Kasir historis', 'sold_at' => '2026-10-31T17:00:00Z', 'total_amount' => 12000]],
            'total' => 101, 'per_page' => 50, 'current_page' => 1, 'next_page_url' => 'https://untrusted.test/collect?token=leak',
        ], $changes);
    }

    public function test_session_and_api_denial_or_expiry_are_preserved(): void
    {
        $path = '/app/stores/S1/pos/sales';
        $this->get($path)->assertRedirect('/login');
        Http::fake(['*' => Http::sequence()->push(['message' => 'Denied'], 403)->push([], 401)]);
        $this->withSession($this->sessionData())->get($path)->assertForbidden()->assertDontSee('Tidak ada struk pada filter ini.');
        $this->get($path)->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_default_and_blank_dates_preserve_all_history_and_number_search_can_span_all_dates(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload())->push($this->payload(['scope' => 'store']))]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/pos/sales')->assertOk()->assertSee('Periode diterapkan: seluruh riwayat.')->assertSee('Cakupan: struk saya.');
        $this->get('/app/stores/S1/pos/sales?from_day=&to_day=&q=POS-OLD')->assertOk()->assertSee('seluruh kasir toko')->assertSee('Nomor struk diterapkan: POS-OLD');
        Http::assertSent(fn ($r) => $r->data() == ['page' => 1]);
        Http::assertSent(fn ($r) => $r->data() == ['q' => 'POS-OLD', 'page' => 1]);
        Http::assertSentCount(2);
    }

    public function test_paired_wib_filters_local_pagination_and_private_token_cookie_boundary(): void
    {
        Http::fake(['*' => Http::response($this->payload(['current_page' => 2]), 200, ['Set-Cookie' => 'backend-secret=not-for-browser'])]);
        $query = ['from_day' => '2026-10-31', 'to_day' => '2026-11-01', 'q' => 'POS-OLD', 'page' => 2, 'store_id' => 'OTHER', 'cashier_id' => 99, 'scope' => 'store', 'api_token' => 'attacker', 'next_page_url' => 'https://untrusted.test'];
        $response = $this->withSession([...$this->sessionData(), '_old_input' => ['q' => 'unrelated-form']])->get('/app/stores/S1/pos/sales?'.http_build_query($query))->assertOk()
            ->assertSee('Halaman 2 dari 3')->assertSee('page=3')->assertSee('from_day=2026-10-31')->assertSee('to_day=2026-11-01')->assertSee('q=POS-OLD')
            ->assertSee('2026-10-31 — 2026-11-01 WIB')->assertSee('value="POS-OLD"', false)->assertSee('Rp 12.000')->assertSee('01 Nov 2026 00:00:00')
            ->assertDontSee('unrelated-form')->assertDontSee('fixture-private-token')->assertDontSee('attacker')->assertDontSee('untrusted.test')->assertDontSee('backend-secret');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('not-for-browser', implode(',', $response->headers->all('set-cookie')));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/v1/stores/S1/sales' && $r->hasHeader('Authorization', 'Bearer fixture-private-token')
            && $r->data() == ['from' => '2026-10-31T00:00:00+07:00', 'to' => '2026-11-02T00:00:00+07:00', 'q' => 'POS-OLD', 'page' => 2]);
    }

    public function test_historical_summary_is_escaped_and_receipt_link_is_local_without_any_payment_request(): void
    {
        Http::fake(['*' => Http::response($this->payload(['store' => ['name' => '<script>current</script>'], 'data' => [['number' => '<svg onload=bad()>', 'store_name' => '<script>history</script>', 'cashier_name' => '<img src=x onerror=bad()>']]]))]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/pos/sales')->assertOk()->assertSee('/app/pos/sales/SALE1')->assertSee('Buka struk tersimpan')
            ->assertSee('&lt;script&gt;history&lt;/script&gt;', false)->assertDontSee('<script>history</script>', false)->assertDontSee('<script>current</script>', false)->assertDontSee('<img src=x onerror=bad()>', false)->assertDontSee('<svg onload=bad()>', false);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        Http::assertSentCount(1);
    }

    public function test_wrong_store_rows_or_unknown_scope_and_api_failure_are_not_empty_results(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload(['store' => ['id' => 'OTHER']]))->push($this->payload(['data' => [['store_id' => 'OTHER']]]))->push($this->payload(['scope' => 'unknown']))->push([], 503)]);
        $this->withSession($this->sessionData());
        foreach (range(1, 4) as $i) {
            $this->get('/app/stores/S1/pos/sales')->assertStatus(503)->assertDontSee('Tidak ada struk pada filter ini.')->assertDontSee('POS-OLD');
        }
    }

    public function test_invalid_or_unpaired_dates_and_bad_filters_do_not_call_api(): void
    {
        Http::fake();
        foreach ([['from_day' => '2026-11-01'], ['to_day' => '2026-11-01'], ['from_day' => '2026-02-30', 'to_day' => '2026-03-01'], ['from_day' => '2026-11-02', 'to_day' => '2026-11-01'], ['from_day' => '2026-10-01', 'to_day' => '2026-11-01'], ['page' => 0], ['q' => str_repeat('a', 101)], ['q' => ['forged']]] as $params) {
            $this->withSession($this->sessionData())->getJson('/app/stores/S1/pos/sales?'.http_build_query($params))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_inactive_empty_history_remains_readable_and_connection_failure_is_not_empty(): void
    {
        $empty = $this->payload(['store' => ['active' => false], 'total' => 0]);
        $empty['data'] = [];
        Http::fake(['*' => Http::response($empty)]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/pos/sales')->assertOk()->assertSee('Toko nonaktif')->assertSee('Tidak ada struk pada filter ini.');
        Http::fake(fn () => throw new ConnectionException('Unavailable'));
        $this->get('/app/stores/S1/pos/sales')->assertStatus(503)->assertDontSee('Tidak ada struk pada filter ini.');
    }
}
