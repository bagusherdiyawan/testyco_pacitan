<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PosWebTest extends TestCase
{
    private function apiSession(): array
    {
        return ['api_token' => 'private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Kasir']];
    }

    private function sale(): array
    {
        return ['id' => 'SALE', 'sale_intent_id' => 'INTENT', 'store_id' => 'STORE', 'number' => 'POS-123', 'store_name' => 'Toko Pacitan', 'store_address' => 'Pacitan', 'cashier_name' => 'Kasir', 'sold_at' => '2026-10-28T03:00:00Z', 'currency' => 'IDR', 'status' => 'completed', 'payment_method' => 'cash', 'subtotal' => 12000, 'discount_amount' => 0, 'total_amount' => 12000, 'cash_received' => 20000, 'change_amount' => 8000, 'lines' => [['sku' => 'AYAM', 'name' => 'Ayam lama', 'quantity' => 4, 'unit' => 'piece', 'pieces_per_unit' => 1, 'unit_price' => 3000, 'line_total' => 12000]]];
    }

    public function test_pos_requires_session_and_renders_open_shift_without_exposing_token(): void
    {
        $this->get('/app/stores/STORE/pos')->assertRedirect('/login');
        Http::preventStrayRequests();
        Http::fake(['*/pos/context' => Http::response(['data' => ['store' => ['id' => 'STORE', 'name' => 'Toko Pacitan', 'active' => true], 'shift' => null, 'pending_intents' => [], 'recent_sales' => [], 'can_manage' => false]]), '*/shifts' => Http::response(['data' => []])]);
        $this->withSession($this->apiSession())->get('/app/stores/STORE/pos')->assertOk()->assertSee('Buka shift kas')->assertDontSee('private');
    }

    public function test_cart_post_forwards_only_server_inputs_and_explicit_stock_selection(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'INTENT']], 201)]);
        $this->withSession($this->apiSession())->post('/app/stores/STORE/pos/intents', ['operation_id' => 'OP', 'shift_id' => 'SHIFT', 'total_amount' => 1, 'lines' => [['product_id' => 'PRODUCT', 'unit' => 'piece', 'quantity' => 4, 'stock' => 'BATCH:ready', 'unit_price' => 1]]])->assertRedirect('/app/pos/intents/INTENT');
        Http::assertSent(fn ($r) => ! isset($r['total_amount']) && ! isset($r['lines'][0]['unit_price']) && $r['lines'][0]['allocations'][0]['batch_id'] === 'BATCH' && $r['lines'][0]['allocations'][0]['quantity'] === 4);
    }

    public function test_malformed_cart_is_validation_error_instead_of_server_failure(): void
    {
        $this->withSession($this->apiSession())->post('/app/stores/STORE/pos/intents', ['lines' => 'invalid'])->assertSessionHasErrors('lines');
    }

    public function test_paid_intent_get_redirects_to_existing_receipt_without_posting_payment(): void
    {
        Http::fake(['*' => Http::response(['data' => ['intent' => ['id' => 'INTENT'], 'sale' => $this->sale(), 'is_owner' => true]])]);
        $this->withSession($this->apiSession())->get('/app/pos/intents/INTENT')->assertRedirect('/app/pos/sales/SALE');
        Http::assertSent(fn ($r) => $r->method() === 'GET');
        Http::assertSentCount(1);
    }

    public function test_payment_only_forwards_cash_and_operation_key(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->sale()])]);
        $this->withSession($this->apiSession())->post('/app/pos/intents/INTENT/pay', ['operation_id' => 'OP', 'payment_method' => 'cash', 'cash_received' => 20000, 'total_amount' => 1, 'change_amount' => 19999])->assertRedirect('/app/pos/sales/SALE');
        Http::assertSent(fn ($r) => $r['cash_received'] === 20000 && ! isset($r['total_amount']) && ! isset($r['change_amount']));
    }

    public function test_receipt_uses_persisted_prices_total_change_and_jakarta_time(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->sale()])]);
        $this->withSession($this->apiSession())->get('/app/pos/sales/SALE')->assertOk()->assertSee('Ayam lama')->assertSee('12.000')->assertSee('8.000')->assertSee('28 Oct 2026 10:00')->assertSee('WIB')->assertSee('Cetak melalui browser');
        Http::assertSentCount(1);
    }

    public function test_shift_close_passes_expected_version_and_physical_count_not_expected_cash(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'SHIFT']])]);
        $this->withSession($this->apiSession())->post('/app/pos/shifts/SHIFT/close', ['operation_id' => 'OP', 'version' => 3, 'counted_cash' => 275000, 'closing_note' => 'Selisih', 'expected_cash' => 0])->assertRedirect('/app/pos/shifts/SHIFT');
        Http::assertSent(fn ($r) => $r['version'] === 3 && $r['counted_cash'] === 275000 && ! isset($r['expected_cash']));
    }

    public function test_report_day_maps_to_explicit_wib_calendar_range(): void
    {
        Http::fake(['*' => Http::response(['data' => ['transaction_count' => 1, 'gross_sales' => 12000, 'discount_amount' => 0, 'net_sales' => 12000, 'cash_sales' => 12000, 'from' => '2026-10-27T17:00:00Z', 'to' => '2026-10-28T17:00:00Z']])]);
        $this->withSession($this->apiSession())->get('/app/stores/STORE/pos/report?day=2026-10-28')->assertOk()->assertSee('12000')->assertSee('bukan kebijakan pergantian shift');
        Http::assertSent(function ($r) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['from'] === '2026-10-28T00:00:00+07:00' && $q['to'] === '2026-10-29T00:00:00+07:00';
        });
    }

    public function test_pending_intent_page_has_cash_confirmation_and_explicit_cancel(): void
    {
        Http::fake(['*' => Http::response(['data' => ['intent' => ['id' => 'INTENT', 'store_id' => 'STORE', 'status' => 'pending', 'total_amount' => 12000, 'items' => [['sku' => 'AYAM', 'name' => 'Ayam', 'quantity' => 4, 'unit' => 'piece', 'unit_price' => 3000, 'line_total' => 12000]]], 'sale' => null, 'is_owner' => true]])]);
        $this->withSession($this->apiSession())->get('/app/pos/intents/INTENT')->assertOk()->assertSee('Konfirmasi pembayaran tunai')->assertSee('Batalkan draft')->assertSee('QRIS diverifikasi manual')->assertSee('Nominal aktual terverifikasi');
    }

    public function test_receipt_history_is_paginated_and_reads_api_scope(): void
    {
        Http::fake(['*' => Http::response(['store' => ['id' => 'STORE', 'name' => 'Toko Pacitan', 'active' => true], 'scope' => 'own', 'data' => [$this->sale()], 'total' => 100, 'per_page' => 50, 'current_page' => 2])]);
        $this->withSession($this->apiSession())->get('/app/stores/STORE/pos/sales?page=2')->assertOk()->assertSee('POS-123')->assertSee('Halaman 2 dari 2');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'page=2'));
    }
}
