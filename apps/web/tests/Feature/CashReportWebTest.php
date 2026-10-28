<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CashReportWebTest extends TestCase
{
    private function sessionData(): array
    {
        return ['api_token' => 'test-token', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Lead']];
    }

    private function summary(): array
    {
        return ['organization' => ['name' => 'Mitra A'], 'from' => '2026-10-26T17:00:00Z', 'to' => '2026-10-28T17:00:00Z', 'observed_at' => '2026-10-28T03:00:00Z', 'totals' => ['transaction_count' => 2, 'gross_sales' => 18000, 'discount_amount' => 0, 'net_sales' => 18000], 'available_stores' => [['id' => 'S1', 'name' => 'Pacitan', 'active' => true]], 'by_store' => [['store_name' => '<script>bad</script>', 'active' => false, 'transaction_count' => 2, 'gross_sales' => 18000, 'discount_amount' => 0, 'net_sales' => 18000]]];
    }

    public function test_requires_login_and_preserves_backend_denial(): void
    {
        $this->get('/app/organizations/O1/reports/cash-sales')->assertRedirect('/login');
        Http::fake(['*' => Http::response(['message' => 'Denied'], 403)]);
        $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales')->assertForbidden();
        $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales/export')->assertForbidden();
    }

    public function test_report_converts_wib_days_preserves_product_pagination_and_escapes_names(): void
    {
        Http::fake(['*/products*' => Http::response(['data' => [['sku' => 'AYAM', 'name' => 'Historical', 'condition' => 'frozen', 'sold_pieces' => 8, 'transaction_count' => 2, 'gross_sales' => 18000]], 'total' => 51, 'per_page' => 50, 'current_page' => 1, 'last_page' => 2]), '*' => Http::response(['data' => $this->summary()])]);
        $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales?from_day=2026-10-27&to_day=2026-10-28')->assertOk()->assertSee('Rp 18.000')->assertSee('8 biji')->assertSee('10:00:00')->assertSee('page=2')->assertDontSee('<script>bad</script>', false);
        Http::assertSent(fn ($r) => $r['from'] === '2026-10-27T00:00:00+07:00' && $r['to'] === '2026-10-29T00:00:00+07:00');
    }

    public function test_csv_proxy_keeps_private_attachment_and_does_not_reencode_bytes_or_copy_credentials(): void
    {
        $csv = "\xEF\xBB\xBFnumber,total\nPOS-1,12000\n";
        Http::fake(['*' => Http::response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Set-Cookie' => 'secret=not-for-browser'])]);
        $response = $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales/export?from_day=2026-10-27&to_day=2026-10-28')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="cash-sales.csv"');
        $this->assertSame($csv, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('not-for-browser', implode(',', $response->headers->all('set-cookie')));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1/organizations/O1/reports/cash-sales/export') && ! isset($r['organization_id']));
    }

    public function test_invalid_range_and_query_do_not_call_backend(): void
    {
        Http::fake();
        $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales?from_day=2026-10-29&to_day=2026-10-28')->assertUnprocessable();
        $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales?from_day=2026-09-01&to_day=2026-10-28')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_export_size_error_is_visible_and_wrong_media_type_is_not_a_download(): void
    {
        Http::fake(['*' => Http::sequence()->push(['errors' => ['period' => ['Persempit periode']]], 422)->push(['data' => []], 200, ['Content-Type' => 'application/json'])]);
        $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales/export')->assertSessionHasErrors('period');
        $this->withSession($this->sessionData())->get('/app/organizations/O1/reports/cash-sales/export')->assertStatus(503);
    }
}
