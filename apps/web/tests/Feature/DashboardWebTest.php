<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardWebTest extends TestCase
{
    private const ORG = '01HZ8Y6Q3K5M7N9P2R4S6T8V0A';

    private const OTHER = '01HZ8Y6Q3K5M7N9P2R4S6T8V0B';

    private function login(): array
    {
        return ['api_token' => 'private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Pengelola']];
    }

    private function org(string $id, string $name, string $role = 'partner_lead', bool $active = true): array
    {
        return ['id' => $id, 'name' => $name, 'type' => 'partner', 'active' => $active, 'role' => $role, 'can_manage' => true, 'can_catalog' => true];
    }

    private function trend(string $org = self::ORG, int $transactions = 4): array
    {
        $days = [];
        foreach (range(12, 18) as $d) {
            $days[] = ['date' => '2026-11-'.$d, 'transactions' => $d === 18 ? $transactions : 0, 'cash' => $d === 18 ? 24000 : 0, 'noncash' => $d === 18 ? 13000 : 0, 'refunds' => $d === 18 ? 16000 : 0];
        }

        return ['data' => ['organization' => ['id' => $org, 'name' => 'Mitra QA'], 'days' => $days, 'totals' => ['transactions' => $transactions, 'cash' => 24000, 'noncash' => 13000, 'refunds' => 16000, 'revenue' => 37000, 'net_revenue' => 21000], 'top_products' => [['sku' => 'AYAM', 'name' => 'Dimsum <b>Ayam</b>', 'sold_pieces' => 12, 'gross_sales' => 37000]]]];
    }

    private function fakeApi(array $orgs, mixed $trend): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/reports/sales-trend*' => $trend instanceof \Closure ? $trend : Http::response($trend['body'] ?? $trend, $trend['status'] ?? 200),
            '*/organizations*' => Http::response(['data' => $orgs, 'total' => count($orgs), 'per_page' => 50, 'current_page' => 1, 'last_page' => 1]),
            '*/stores*' => Http::response(['data' => [], 'total' => 0, 'per_page' => 50, 'current_page' => 1, 'last_page' => 1]),
        ]);
    }

    public function test_dashboard_shows_totals_charts_and_escapes_product_names(): void
    {
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA')], $this->trend());
        $this->withSession($this->login())->get('/app')->assertOk()->assertSee('Ringkasan penjualan')->assertSee('Rp 37.000')->assertSee('tunai Rp 24.000')->assertSee('transfer Rp 13.000')->assertSee('Rp 16.000')->assertSee('omzet bersih Rp 21.000')->assertSee('id="chart-trend"', false)->assertSee('/dashboard-charts.js', false)->assertSee('Dimsum <b>Ayam<\/b>', false)->assertDontSee('<b>Ayam</b>', false);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/organizations/'.self::ORG.'/reports/sales-trend') && str_contains($r['from'], '+07:00') && str_contains($r['to'], '+07:00'));
    }

    public function test_the_requested_organization_must_be_one_the_user_can_report_on(): void
    {
        $orgs = [$this->org(self::ORG, 'Mitra QA'), $this->org(self::OTHER, 'Mitra Lain', 'store_manager')];
        $this->fakeApi($orgs, $this->trend(self::OTHER));
        $this->withSession($this->login())->get('/app?org='.self::OTHER)->assertOk()->assertSee('Rp 37.000');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/organizations/'.self::OTHER.'/reports/sales-trend'));
        $this->fakeApi($orgs, $this->trend());
        $this->get('/app?org=01HZ8Y6Q3K5M7N9P2R4S6T8V0Z')->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/organizations/'.self::ORG.'/reports/sales-trend'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '0Z/reports'));
    }

    public function test_dashboard_is_hidden_when_the_user_cannot_report_or_the_api_fails_or_returns_another_organization(): void
    {
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA', 'cashier')], $this->trend());
        $this->withSession($this->login())->get('/app')->assertOk()->assertDontSee('Ringkasan penjualan')->assertSee('Workspace organisasi');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sales-trend'));
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA', 'owner', false)], $this->trend());
        $this->get('/app')->assertOk()->assertDontSee('Ringkasan penjualan');
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA')], ['body' => ['message' => 'Denied'], 'status' => 403]);
        $this->get('/app')->assertOk()->assertDontSee('Ringkasan penjualan')->assertSee('Mitra QA');
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA')], $this->trend(self::OTHER));
        $this->get('/app')->assertOk()->assertDontSee('Ringkasan penjualan');
    }

    public function test_an_empty_week_says_so_instead_of_showing_misleading_zeros_only(): void
    {
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA')], $this->trend(self::ORG, 0));
        $this->withSession($this->login())->get('/app')->assertOk()->assertSee('Belum ada penjualan dalam 7 hari terakhir');
    }

    public function test_the_console_shell_has_navigation_breadcrumb_and_logout_and_loads_nothing_from_third_parties(): void
    {
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA', 'cashier')], $this->trend());
        $html = $this->withSession($this->login())->get('/app')->assertOk()->assertSee('Testyco · Operasional')->assertSee('Pesanan pusat')->assertSee('Inbox')->assertSee('Kata sandi')->assertSee('Keluar')->assertSee('Pengelola')->assertSee('aria-current="page"', false)->assertSee('<li class="breadcrumb-item text-sm active" aria-current="page">Workspace organisasi</li>', false)->getContent();
        foreach (['fonts.googleapis.com', 'kit.fontawesome.com', 'buttons.github.io', 'unsplash', 'cdn.'] as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
        preg_match_all('/<(?:script|link)[^>]+(?:src|href)="([^"]+)"/', $html, $m);
        foreach ($m[1] as $url) {
            $this->assertStringStartsWith('/', $url, 'Asset must be same-origin: '.$url);
        }
    }

    public function test_no_inline_script_is_rendered_by_the_shell(): void
    {
        $this->fakeApi([$this->org(self::ORG, 'Mitra QA', 'cashier')], $this->trend());
        $this->withSession($this->login())->get('/app')->assertOk()->assertDontSee('<script>', false);
    }
}
