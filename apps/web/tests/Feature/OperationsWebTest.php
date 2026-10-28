<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationsWebTest extends TestCase
{
    private function apiSession(): array
    {
        return ['api_token' => 'private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Pimpinan']];
    }

    private function order(array $changes = []): array
    {
        return [...['id' => 'O1', 'store_id' => 'S1', 'organization_id' => 'A', 'supplier_organization_id' => 'C', 'source_store_id' => null, 'destination_name' => 'Toko Pacitan', 'destination_address' => 'Pacitan', 'kind' => 'wholesale', 'status' => 'awaiting_approval', 'version' => 1, 'delivery_mode' => 'delivery', 'note' => null, 'decision_note' => null, 'total_amount' => 360000, 'access_side' => 'recipient', 'can_approve' => true, 'can_process' => false, 'lines' => [['id' => 'L1', 'supplier_product_id' => 'PC', 'local_product_id' => 'PA', 'sku' => 'AYAM-8', 'local_sku' => 'TOKO-8', 'name' => 'Ayam', 'quantity_packs' => 20, 'pack_size' => 8, 'unit_price' => 18000]]], ...$changes];
    }

    public function test_operations_pages_require_web_session(): void
    {
        $this->get('/app/stores/S1/operations')->assertRedirect('/login');
        $this->get('/app/supply/orders')->assertRedirect('/login');
    }

    public function test_store_page_renders_actual_stock_and_preserves_quarantine_separation(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/operations-context' => Http::response(['data' => ['id' => 'S1', 'name' => 'Toko Pacitan', 'active' => true, 'can_order' => true, 'can_intake' => false]]), '*/orders' => Http::response(['data' => [$this->order()]]), '*/inventory/batches' => Http::response(['data' => [['id' => 'B1', 'sku' => 'AYAM-8', 'name' => 'Ayam', 'batch_code' => 'B-001', 'expires_on' => '2030-01-01', 'pack_size' => 8, 'usable_pieces' => 144, 'quarantined_pieces' => 16]]])]);
        $this->withSession($this->apiSession())->get('/app/stores/S1/operations')->assertOk()->assertSee('144')->assertSee('Karantina: 16')->assertSee('B-001')->assertDontSee('Penerimaan fisik gudang pusat');
    }

    public function test_order_form_and_detail_render_approval_controls_only_for_authorized_roles(): void
    {
        Http::fake(['*/operations-context' => Http::response(['data' => ['id' => 'S1', 'name' => 'Pacitan']]), '*/supply-catalog' => Http::response(['data' => [['id' => 'PC', 'sku' => 'AYAM-8', 'name' => 'Ayam', 'pack_size' => 8, 'wholesale_price' => 18000]]]), '*/catalog' => Http::response(['data' => [['id' => 'PA', 'sku' => 'TOKO-8', 'name' => 'Ayam toko', 'pack_size' => 8, 'condition' => 'frozen']]]), '*/orders/O1' => Http::response(['data' => $this->order()]), '*/shipments' => Http::response(['data' => []])]);
        $this->withSession($this->apiSession())->get('/app/stores/S1/orders/new')->assertOk()->assertSee('AYAM-8')->assertSee('TOKO-8')->assertSee('operation_id');
        $this->get('/app/orders/O1')->assertOk()->assertSee('Setujui dan kirim ke pusat')->assertDontSee('Konfirmasi pengiriman');

    }

    public function test_submission_uses_api_computed_order_and_does_not_forward_forged_prices_or_tenant(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 'O1']], 201)]);
        $this->withSession($this->apiSession())->post('/app/stores/S1/orders', ['operation_id' => 'OP', 'delivery_mode' => 'pickup', 'lines' => [['supplier_product_id' => 'PC', 'local_product_id' => 'PA', 'quantity_packs' => 20]], 'total_amount' => 1, 'organization_id' => 'FORGED', 'status' => 'approved'])->assertRedirect('/app/orders/O1');
        Http::assertSent(fn ($r) => ! isset($r['total_amount']) && ! isset($r['organization_id']) && ! isset($r['status']) && $r['operation_id'] === 'OP');
    }

    public function test_dispatch_form_filters_only_zero_allocations_and_keeps_stable_operation_id(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 201)]);
        $this->withSession($this->apiSession())->post('/app/orders/O1/shipments', ['operation_id' => 'OP', 'version' => 3, 'handler' => 'Petugas', 'lines' => [['order_line_id' => 'L1', 'allocations' => [['batch_id' => 'B0', 'quantity_packs' => 0], ['batch_id' => 'B1', 'quantity_packs' => 18]]]]])->assertRedirect('/app/orders/O1');
        Http::assertSent(fn ($r) => $r['operation_id'] === 'OP' && count($r['lines'][0]['allocations']) === 1 && $r['lines'][0]['allocations'][0]['batch_id'] === 'B1');
    }

    public function test_receipt_form_never_prefills_good_quantity_to_shipped_amount(): void
    {
        $o = $this->order(['status' => 'dispatched', 'version' => 4]);
        $shipment = ['id' => 'SH1', 'handler' => 'Petugas', 'received_at' => null, 'lines' => [['id' => 'SL1', 'batch_code' => 'B-001', 'quantity_packs' => 20, 'pack_size' => 8]]];
        Http::fake(['*/orders/O1' => Http::response(['data' => $o]), '*/shipments' => Http::response(['data' => [$shipment]])]);
        $this->withSession($this->apiSession())->get('/app/orders/O1')->assertOk()->assertSee('Konfirmasi jumlah aktual')->assertSee('Diterima rusak / karantina')->assertDontSee('name="lines[0][good_packs]" type="number" min="0" max="20" value="20"', false);
    }

    public function test_warehouse_dispatch_screen_renders_matching_batch_allocations(): void
    {
        Http::fake(['*/orders/O1' => Http::response(['data' => $this->order(['source_store_id' => 'SC', 'status' => 'processing', 'version' => 3, 'access_side' => 'supplier', 'can_approve' => false])]), '*/shipments' => Http::response(['data' => []]), '*/inventory/batches' => Http::response(['data' => [['id' => 'B1', 'product_id' => 'PC', 'pack_size' => 8, 'usable_pieces' => 160, 'batch_code' => 'B-001', 'expires_on' => '2030-01-01']]])]);
        $this->withSession($this->apiSession())->get('/app/orders/O1')->assertOk()->assertSee('B-001')->assertSee('Konfirmasi pengiriman')->assertDontSee('Konfirmasi jumlah aktual');
    }

    public function test_detail_hides_approval_controls_without_permission(): void
    {
        Http::fake(['*/orders/O1' => Http::response(['data' => $this->order(['can_approve' => false])]), '*/shipments' => Http::response(['data' => []])]);
        $this->withSession($this->apiSession())->get('/app/orders/O1')->assertOk()->assertDontSee('Setujui dan kirim ke pusat');
    }
}
