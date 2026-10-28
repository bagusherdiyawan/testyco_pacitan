<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockMinimumWebTest extends TestCase
{
    private function sessionData(): array
    {
        return ['api_token' => 'test-token', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Pengelola']];
    }

    private function report(bool $manage = true): array
    {
        return ['data' => [['product_id' => 'P1', 'sku' => 'AYAM', 'name' => '<script>bad</script>', 'available_pieces' => 12, 'usable_pieces' => 10, 'opened_pieces' => 2, 'ready_pieces' => 0, 'minimum_pieces' => 20, 'version' => 3, 'is_low' => true, 'shortfall_pieces' => 8]], 'can_manage' => $manage, 'observed_at' => '2026-10-28T10:00:00+00:00', 'store' => ['id' => 'S1', 'name' => 'Pacitan'], 'current_page' => 1, 'last_page' => 2, 'total' => 51];
    }

    public function test_requires_login_and_does_not_hide_backend_denial(): void
    {
        $this->get('/app/stores/S1/stock-minimums')->assertRedirect('/login');
        Http::fake(['*' => Http::response(['message' => 'Denied'], 403)]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/stock-minimums')->assertForbidden();
    }

    public function test_report_shows_stock_warning_configuration_and_safe_pagination(): void
    {
        Http::fake(['*' => Http::response($this->report())]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/stock-minimums?low_only=1&q=AYAM')->assertOk()->assertSee('Kekurangan 8 biji')->assertSee('Simpan ambang')->assertSee('name="version" value="3"', false)->assertSee('page=2')->assertDontSee('<script>bad</script>', false);
        Http::assertSent(fn ($r) => $r['low_only'] === '1' && $r['q'] === 'AYAM');
    }

    public function test_cashier_sees_warning_without_configuration_form(): void
    {
        Http::fake(['*' => Http::response($this->report(false))]);
        $this->withSession($this->sessionData())->get('/app/stores/S1/stock-minimums')->assertOk()->assertSee('Stok rendah')->assertDontSee('Simpan ambang');
    }

    public function test_whitelists_configuration_and_preserves_null_disable_and_operation_key(): void
    {
        Http::fake(['*' => Http::response(['data' => ['version' => 4]])]);
        $this->withSession($this->sessionData())->post('/app/stores/S1/stock-minimums/P1', ['operation_id' => 'KEY', 'version' => 3, 'minimum_pieces' => '', 'reason' => 'Nonaktif', 'available_pieces' => 999, 'organization_id' => 'FOREIGN'])->assertRedirect('/app/stores/S1/stock-minimums')->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r['minimum_pieces'] === null && $r['operation_id'] === 'KEY' && $r['version'] === 3 && ! isset($r['available_pieces']) && ! isset($r['organization_id']));
    }

    public function test_validation_and_stale_conflict_are_not_reported_as_success(): void
    {
        Http::fake(['*' => Http::sequence()->push(['errors' => ['minimum_pieces' => ['Tidak valid']]], 422)->push(['message' => 'Ambang berubah'], 409)]);
        $this->withSession($this->sessionData())->post('/app/stores/S1/stock-minimums/P1', [])->assertSessionHasErrors('minimum_pieces')->assertSessionMissing('status');
        $this->withSession($this->sessionData())->post('/app/stores/S1/stock-minimums/P1', [])->assertConflict();
    }

    public function test_failed_form_input_is_not_copied_into_other_sku_forms(): void
    {
        $report = $this->report();
        $report['data'][] = [...$report['data'][0], 'product_id' => 'P2', 'sku' => 'SAPI', 'minimum_pieces' => 30];
        Http::fake(['*' => Http::response($report)]);
        $this->withSession([...$this->sessionData(), '_old_input' => ['minimum_pieces' => 999, 'reason' => 'Wrong SKU', 'operation_id' => 'REUSED']])->get('/app/stores/S1/stock-minimums')->assertOk()->assertDontSee('value="999"', false)->assertDontSee('value="REUSED"', false)->assertDontSee('value="Wrong SKU"', false)->assertSee('value="20"', false)->assertSee('value="30"', false);
    }
}
