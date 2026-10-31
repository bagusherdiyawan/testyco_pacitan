<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockLedgerWebTest extends TestCase
{
    private const BATCH = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private function sessionData(): array
    {
        return ['api_token' => 'fixture-private-token', 'api_expires_at' => time() + 3600, 'api_user' => ['id' => 1, 'name' => 'Manager']];
    }

    private function payload(array $changes = []): array
    {
        return array_replace_recursive([
            'store' => ['id' => 'S1', 'name' => 'Pacitan <script>bad</script>', 'active' => true], 'observed_at' => '2026-10-31T03:00:00Z',
            'data' => [['id' => 'E1', 'store_id' => 'S1', 'inventory_batch_id' => self::BATCH, 'sku' => 'OLD8', 'name' => '<script>history</script>', 'batch_code' => 'LOT-A', 'condition' => 'frozen', 'pack_size' => 8, 'expires_on' => '2030-01-01', 'quantity_pieces' => -8, 'bucket' => 'usable', 'source_type' => 'shipment_dispatch', 'source_id' => 'LINE-REF', 'actor_id' => 7, 'note' => '<img src=x onerror=bad()>', 'occurred_at' => '2026-10-30T17:00:00+00:00']],
            'current_page' => 1, 'total' => 201, 'per_page' => 100, 'next_page_url' => 'https://untrusted.test/collect?token=leak',
        ], $changes);
    }

    public function test_session_required_and_api_denial_or_expiry_is_not_empty_history(): void
    {
        Http::preventStrayRequests();
        $path = '/app/stores/S1/inventory/ledger';
        $this->get($path)->assertRedirect('/login');
        Http::fake(['*' => Http::sequence()->push(['message' => 'Denied'], 403)->push([], 401)]);
        $this->withSession($this->sessionData())->get($path)->assertForbidden()->assertDontSee('Tidak ada pergerakan pada filter ini.');
        $this->get($path)->assertRedirect('/login')->assertSessionMissing('api_token');
    }

    public function test_filters_use_wib_scoped_whitelist_local_pagination_and_private_response(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->payload(['current_page' => 2]), 200, ['Set-Cookie' => 'backend-secret=not-for-browser'])]);
        $query = ['from_day' => '2026-10-30', 'to_day' => '2026-10-31', 'batch_id' => self::BATCH, 'q' => 'LOT-A', 'source_type' => 'shipment_dispatch', 'bucket' => 'usable', 'page' => 2, 'store_id' => 'OTHER', 'organization_id' => 'OTHER', 'api_token' => 'attacker', 'next_page_url' => 'https://untrusted.test'];
        $response = $this->withSession([...$this->sessionData(), '_old_input' => ['q' => 'unrelated-form']])->get('/app/stores/S1/inventory/ledger?'.http_build_query($query))->assertOk()
            ->assertSee('Halaman 2 dari 3')->assertSee('page=3')->assertSee('batch_id='.self::BATCH)->assertSee('q=LOT-A')->assertSee('source_type=shipment_dispatch')->assertSee('bucket=usable')
            ->assertSee('2026-10-30 — 2026-10-31 WIB')->assertSee('value="LOT-A"', false)->assertSee('Lihat semua batch')
            ->assertDontSee('unrelated-form')->assertDontSee('fixture-private-token')->assertDontSee('untrusted.test')->assertDontSee('attacker')->assertDontSee('backend-secret');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('not-for-browser', implode(',', $response->headers->all('set-cookie')));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/api/v1/stores/S1/inventory/ledger'
            && $r->hasHeader('Authorization', 'Bearer fixture-private-token')
            && $r->data() == ['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-11-01T00:00:00+07:00', 'batch_id' => self::BATCH, 'q' => 'LOT-A', 'source_type' => 'shipment_dispatch', 'bucket' => 'usable', 'page' => 2]);
    }

    public function test_historical_metadata_signed_pieces_utc_and_references_are_escaped_without_prices(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload())->push($this->payload(['data' => [['quantity_pieces' => 16, 'bucket' => 'quarantined', 'source_type' => 'shipment_receipt']]]))]);
        $path = '/app/stores/S1/inventory/ledger';
        $this->withSession($this->sessionData())->get($path)->assertOk()->assertSee('-8 biji')->assertSee('Utuh')->assertSee('Isi kemasan historis: 8 biji')
            ->assertSee('31 Oct 2026 00:00:00')->assertSee('Referensi sumber: LINE-REF')->assertSee('Pelaku #7')->assertSee('bukan saldo akhir atau saldo berjalan')
            ->assertDontSee('<script>bad</script>', false)->assertDontSee('<script>history</script>', false)->assertDontSee('<img src=x onerror=bad()>', false)->assertSee('&lt;script&gt;history&lt;/script&gt;', false)
            ->assertDontSee('wholesale_price')->assertDontSee('retail_price');
        $this->get($path)->assertOk()->assertSee('+16 biji')->assertSee('Karantina')->assertSee('Penerimaan pesanan');
        Http::assertSentCount(2);
    }

    public function test_invalid_calendar_filter_types_and_source_bucket_do_not_call_api(): void
    {
        Http::fake();
        foreach ([['from_day' => '2026-02-30'], ['from_day' => '2026-10-31', 'to_day' => '2026-10-30'], ['from_day' => '2026-10-01', 'to_day' => '2026-11-01'], ['batch_id' => 'bad'], ['page' => 0], ['q' => str_repeat('a', 101)], ['source_type' => 'unknown'], ['bucket' => 'unknown'], ['q' => ['forged']]] as $params) {
            $this->withSession($this->sessionData())->getJson('/app/stores/S1/inventory/ledger?'.http_build_query($params))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_wrong_store_row_batch_and_foreign_batch_denial_do_not_render_old_or_empty_data(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload(['store' => ['id' => 'OTHER']]))->push($this->payload(['data' => [['store_id' => 'OTHER']]]))->push($this->payload(['data' => [['inventory_batch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW']]]))->push([], 404)->push([], 503)]);
        $path = '/app/stores/S1/inventory/ledger?batch_id='.self::BATCH;
        $this->withSession($this->sessionData());
        foreach ([503, 503, 503, 404, 503] as $status) {
            $this->get($path)->assertStatus($status)->assertDontSee('Tidak ada pergerakan pada filter ini.')->assertDontSee('LINE-REF');
        }
    }

    public function test_default_wib_day_inactive_store_empty_history_and_connection_failure(): void
    {
        $empty = $this->payload(['store' => ['active' => false], 'total' => 0]);
        $empty['data'] = [];
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30T17:00:00Z'));
        try {
            Http::fake(['*' => Http::response($empty)]);
            $this->withSession($this->sessionData())->get('/app/stores/S1/inventory/ledger')->assertOk()->assertSee('2026-10-31 — 2026-10-31 WIB')->assertSee('Toko nonaktif')->assertSee('Tidak ada pergerakan pada filter ini.');
            Http::assertSent(fn ($r) => $r['from'] === '2026-10-31T00:00:00+07:00' && $r['to'] === '2026-11-01T00:00:00+07:00');
        } finally {
            CarbonImmutable::setTestNow();
        }
        Http::fake(fn () => throw new ConnectionException('Unavailable'));
        $this->get('/app/stores/S1/inventory/ledger')->assertStatus(503)->assertDontSee('Tidak ada pergerakan pada filter ini.');
    }

    public function test_store_and_batch_navigation_hints_follow_context_and_do_not_grant_authority(): void
    {
        $allowed = true;
        Http::fake(function ($r) use (&$allowed) {
            if (str_contains($r->url(), 'operations-context')) {
                return Http::response(['data' => ['id' => 'S1', 'name' => 'Toko', 'active' => true, 'can_order' => false, 'can_intake' => false, 'can_manage_stock' => $allowed]]);
            }

            return Http::response(['data' => [['id' => self::BATCH, 'sku' => 'OLD8', 'name' => 'Historis', 'batch_code' => 'LOT-A', 'expires_on' => '2030-01-01', 'pack_size' => 8, 'usable_pieces' => 16, 'quarantined_pieces' => 0]]]);
        });
        $this->withSession($this->sessionData())->get('/app/stores/S1/operations')->assertOk()->assertSee('/app/stores/S1/inventory/ledger')->assertSee('batch_id='.self::BATCH)->assertSee('Riwayat batch ini');
        $allowed = false;
        $this->get('/app/stores/S1/operations')->assertOk()->assertDontSee('/app/stores/S1/inventory/ledger')->assertDontSee('Riwayat batch ini');
    }
}
