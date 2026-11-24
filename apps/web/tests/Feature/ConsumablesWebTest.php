<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsumablesWebTest extends TestCase
{
    private const ORG = '01HZ8Y6Q3K5M7N9P2R4S6T8V0A';

    private const STORE = '01HZ8Y6Q3K5M7N9P2R4S6T8V0B';

    private const ITEM = '01HZ8Y6Q3K5M7N9P2R4S6T8V0C';

    private const OP = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    /** Each call replaces earlier fakes so later stubs are not shadowed by the first ones. */
    private function fakeApi(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function login(): array
    {
        return ['api_token' => 'private', 'api_expires_at' => time() + 1000, 'api_user' => ['id' => 1, 'name' => 'Pengelola']];
    }

    private function catalogue(string $org = self::ORG): array
    {
        return ['data' => ['organization_id' => $org, 'items' => [['id' => self::ITEM, 'organization_id' => $org, 'name' => '<b>Kotak</b>', 'unit' => 'pcs', 'active' => true, 'version' => 2]], 'rules' => [['id' => 'R1', 'organization_id' => $org, 'service_type' => 'takeaway', 'consumable_item_id' => self::ITEM, 'basis' => 'portion', 'quantity' => 1]]]];
    }

    private function stock(array $balances = [-3]): array
    {
        return ['data' => ['store_id' => self::STORE, 'organization_id' => self::ORG, 'items' => array_map(fn ($b) => ['id' => self::ITEM, 'name' => 'Kotak', 'unit' => 'pcs', 'balance' => $b, 'short' => $b < 0], $balances), 'recent' => [['id' => 'E1', 'consumable_item_id' => self::ITEM, 'delta' => -3, 'reason_type' => 'sale', 'amount' => null, 'note' => 'takeaway', 'created_at' => '2026-11-18T05:00:00Z']]]];
    }

    public function test_pages_require_login_and_backend_denial_is_not_hidden(): void
    {
        $this->get('/app/organizations/'.self::ORG.'/consumables')->assertRedirect('/login');
        $this->get('/app/stores/'.self::STORE.'/consumables')->assertRedirect('/login');
        $this->fakeApi(['*' => Http::response(['message' => 'Denied'], 403)]);
        $this->withSession($this->login())->get('/app/organizations/'.self::ORG.'/consumables')->assertForbidden();
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumables')->assertForbidden();
    }

    public function test_catalogue_renders_items_and_rules_escaped_and_refuses_another_organizations_data(): void
    {
        $this->fakeApi(['*' => Http::response($this->catalogue())]);
        $this->withSession($this->login())->get('/app/organizations/'.self::ORG.'/consumables')->assertOk()->assertSee('Bawa pulang')->assertSee('per porsi terjual')->assertSee('&lt;b&gt;Kotak&lt;/b&gt;', false)->assertDontSee('<b>Kotak</b>', false)->assertSee('name="version" value="2"', false);
        $this->fakeApi(['*' => Http::response($this->catalogue('01HZ8Y6Q3K5M7N9P2R4S6T8V0Z'))]);
        $this->withSession($this->login())->get('/app/organizations/'.self::ORG.'/consumables')->assertStatus(503);
    }

    public function test_item_and_rule_forms_forward_only_validated_fields(): void
    {
        $this->fakeApi(['*' => Http::response(['data' => ['id' => 'X']], 201)]);
        $this->withSession($this->login())->post('/app/organizations/'.self::ORG.'/consumables/items', ['operation_id' => self::OP, 'name' => 'Kantong', 'unit' => 'pcs', 'organization_id' => 'EVIL'])->assertRedirect('/app/organizations/'.self::ORG.'/consumables');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/consumables/items') && $r['name'] === 'Kantong' && ! isset($r['organization_id']));
        $this->post('/app/organizations/'.self::ORG.'/consumables/rules', ['operation_id' => self::OP, 'service_type' => 'takeaway', 'consumable_item_id' => self::ITEM, 'basis' => 'portion', 'quantity' => '2'])->assertRedirect();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/consumables/rules') && $r['quantity'] === 2 && $r['basis'] === 'portion');
        $this->post('/app/organizations/'.self::ORG.'/consumables/rules', ['operation_id' => self::OP, 'service_type' => 'delivery', 'consumable_item_id' => self::ITEM, 'basis' => 'portion', 'quantity' => 1])->assertSessionHasErrors('service_type');
        $this->post('/app/organizations/'.self::ORG.'/consumables/items/'.self::ITEM.'/status', ['operation_id' => self::OP, 'version' => '2', 'active' => '0', 'reason' => 'Tidak dipakai'])->assertRedirect();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/status') && $r['version'] === 2 && $r['active'] === false);
    }

    public function test_stock_page_warns_about_shortage_and_offers_cash_out_only_with_an_open_shift(): void
    {
        $this->fakeApi(['*/pos/context' => Http::response(['data' => ['shift' => ['id' => '01HZ8Y6Q3K5M7N9P2R4S6T8V0D', 'version' => 4]]]), '*' => Http::response($this->stock())]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumables')->assertOk()->assertSee('Stok kurang: Kotak')->assertSee('Kurang')->assertSee('name="record_cash"', false)->assertSee('name="shift_version" value="4"', false)->assertSee('Penjualan');
        $this->fakeApi(['*/pos/context' => Http::response(['data' => ['shift' => null]]), '*' => Http::response($this->stock([5]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumables')->assertOk()->assertSee('Cukup')->assertDontSee('name="record_cash"', false)->assertSee('tidak mengurangi kas');
        $this->fakeApi(['*/pos/context' => Http::response(['message' => 'x'], 500), '*' => Http::response($this->stock([0]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumables')->assertOk()->assertSee('Habis');
        $this->fakeApi(['*' => Http::response(array_replace_recursive($this->stock(), ['data' => ['store_id' => 'OTHER']]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumables')->assertStatus(503);
    }

    public function test_purchase_sends_cash_fields_only_when_the_cash_out_box_is_ticked(): void
    {
        $this->fakeApi(['*' => Http::response(['data' => ['balance' => 5]], 201)]);
        $base = ['operation_id' => self::OP, 'consumable_item_id' => self::ITEM, 'quantity' => '10', 'amount' => '15000', 'note' => 'Toko plastik', 'shift_id' => '01HZ8Y6Q3K5M7N9P2R4S6T8V0D', 'shift_version' => '4', 'balance' => 9999];
        $this->withSession($this->login())->post('/app/stores/'.self::STORE.'/consumables/purchases', $base)->assertRedirect('/app/stores/'.self::STORE.'/consumables')->assertSessionHas('status', 'Pembelian dicatat: stok bertambah.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/purchases') && $r['quantity'] === 10 && $r['amount'] === 15000 && ! isset($r['shift_id']) && ! isset($r['balance']));
        $this->post('/app/stores/'.self::STORE.'/consumables/purchases', [...$base, 'operation_id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3302', 'record_cash' => '1'])->assertSessionHas('status', 'Pembelian dicatat: stok bertambah dan kas keluar tercatat.');
        Http::assertSent(fn ($r) => $r['operation_id'] === '3f2504e0-4f89-41d3-9a0c-0305e82c3302' && $r['shift_id'] === '01HZ8Y6Q3K5M7N9P2R4S6T8V0D' && $r['shift_version'] === 4);
        $this->post('/app/stores/'.self::STORE.'/consumables/purchases', ['operation_id' => self::OP, 'quantity' => 0])->assertSessionHasErrors(['consumable_item_id', 'quantity']);
    }

    public function test_physical_count_requires_a_reason_and_forwards_an_integer(): void
    {
        $this->fakeApi(['*' => Http::response(['data' => ['balance' => 7]], 201)]);
        $this->withSession($this->login())->post('/app/stores/'.self::STORE.'/consumables/counts', ['operation_id' => self::OP, 'consumable_item_id' => self::ITEM, 'counted' => '7'])->assertSessionHasErrors('reason');
        Http::assertNothingSent();
        $this->post('/app/stores/'.self::STORE.'/consumables/counts', ['operation_id' => self::OP, 'consumable_item_id' => self::ITEM, 'counted' => '7', 'reason' => 'Hitung malam'])->assertRedirect('/app/stores/'.self::STORE.'/consumables');
        Http::assertSent(fn ($r) => $r['counted'] === 7 && $r['reason'] === 'Hitung malam');
    }

    public function test_cart_forwards_the_service_type_only_when_valid(): void
    {
        $this->fakeApi(['*' => Http::response(['data' => ['id' => 'INTENT']], 201)]);
        $cart = ['operation_id' => 'OP', 'shift_id' => 'SHIFT', 'lines' => [['product_id' => 'P', 'unit' => 'portion', 'quantity' => 2, 'stock' => 'BATCH:ready']]];
        $this->withSession($this->login())->post('/app/stores/STORE/pos/intents', [...$cart, 'service_type' => 'takeaway'])->assertRedirect('/app/pos/intents/INTENT');
        Http::assertSent(fn ($r) => $r['service_type'] === 'takeaway');
        $this->post('/app/stores/STORE/pos/intents', $cart)->assertRedirect('/app/pos/intents/INTENT');
        Http::assertSent(fn ($r) => ! isset($r['service_type']));
        $this->post('/app/stores/STORE/pos/intents', [...$cart, 'service_type' => 'delivery'])->assertSessionHasErrors('service_type');
    }

    public function test_receipt_shows_service_type_and_flags_a_supply_shortage_without_printing_it(): void
    {
        $sale = ['id' => 'SALE', 'sale_intent_id' => 'INTENT', 'store_id' => 'STORE', 'store_name' => 'Booth', 'store_address' => 'Pacitan', 'number' => 'POS-1', 'cashier_name' => 'Kasir', 'sold_at' => '2026-11-18T03:00:00Z', 'currency' => 'IDR', 'status' => 'completed', 'payment_method' => 'cash', 'subtotal' => 24000, 'discount_amount' => 0, 'total_amount' => 24000, 'cash_received' => 30000, 'change_amount' => 6000, 'service_type' => 'takeaway', 'consumption' => [['name' => 'Kotak', 'unit' => 'pcs', 'quantity' => 2, 'shortfall' => 1], ['name' => 'Sendok', 'unit' => 'pcs', 'quantity' => 1, 'shortfall' => 0]], 'lines' => [['sku' => 'AYAM', 'name' => 'Ayam', 'quantity' => 2, 'unit' => 'portion', 'pieces_per_unit' => 4, 'unit_price' => 12000, 'line_total' => 24000]]];
        $this->fakeApi(['*' => Http::response(['data' => $sale])]);
        $this->withSession($this->login())->get('/app/pos/sales/SALE')->assertOk()->assertSee('Bawa pulang')->assertSee('Kotak 2 pcs')->assertSee('stok kurang 1')->assertSee('Sendok 1 pcs')->assertSee('class="no-print">Kemasan dan alat makan', false);
    }

    private const SHIPMENT = '01HZ8Y6Q3K5M7N9P2R4S6T8V0E';

    private const LINE = '01HZ8Y6Q3K5M7N9P2R4S6T8V0F';

    private function shipments(bool $center, string $status = 'dispatched', string $store = self::STORE): array
    {
        $line = ['id' => self::LINE, 'name' => 'Kotak', 'unit' => 'pcs', 'quantity_sent' => 40, 'quantity_received' => $status === 'received' ? 35 : null];

        return ['store_id' => $store, 'organization_id' => self::ORG, 'is_center' => $center, 'can_send' => $center, 'can_receive' => ! $center, 'destinations' => $center ? [['id' => '01HZ8Y6Q3K5M7N9P2R4S6T8V0G', 'name' => 'Booth A1', 'organization_name' => 'Mitra A']] : [], 'data' => [['id' => self::SHIPMENT, 'status' => $status, 'note' => '<i>Surat jalan</i>', 'version' => 1, 'dispatched_at' => '2026-11-18T05:00:00Z', 'received_at' => null, 'receive_note' => $status === 'received' ? 'Lima penyok' : null, 'source_store_id' => $center ? $store : '01HZ8Y6Q3K5M7N9P2R4S6T8V0H', 'destination_store_id' => $center ? '01HZ8Y6Q3K5M7N9P2R4S6T8V0G' : $store, 'source_store_name' => 'Gudang Pusat', 'destination_store_name' => 'Booth A1', 'direction' => $center ? 'outgoing' : 'incoming', 'lines' => [$line]]]];
    }

    public function test_shipments_page_requires_login_and_passes_backend_denial_through(): void
    {
        $this->get('/app/stores/'.self::STORE.'/consumable-shipments')->assertRedirect('/login');
        $this->fakeApi(['*' => Http::response(['message' => 'Denied'], 403)]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumable-shipments')->assertForbidden();
    }

    public function test_center_sees_the_send_form_and_booth_managers_see_the_receive_form_with_name_matched_mapping(): void
    {
        $this->fakeApi(['*/consumable-shipments' => Http::response($this->shipments(true)), '*/consumables' => Http::response($this->stock([60]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumable-shipments')->assertOk()->assertSee('Kirim ke booth')->assertSee('Booth A1 · Mitra A')->assertSee('Dalam perjalanan')->assertDontSee('Konfirmasi penerimaan')->assertSee('&lt;i&gt;Surat jalan&lt;/i&gt;', false);
        $this->fakeApi(['*/consumable-shipments' => Http::response($this->shipments(false)), '*/consumables' => Http::response($this->stock([0]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumable-shipments')->assertOk()->assertSee('Konfirmasi penerimaan')->assertDontSee('Kirim ke booth')->assertSee('name="version" value="1"', false)->assertSee('value="'.self::ITEM.'" selected', false);
        $cashier = $this->shipments(false);
        $cashier['can_receive'] = false;
        $this->fakeApi(['*/consumable-shipments' => Http::response($cashier), '*/consumables' => Http::response($this->stock([0]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumable-shipments')->assertOk()->assertDontSee('Konfirmasi penerimaan');
    }

    public function test_received_shipments_show_the_shortage_and_other_stores_data_is_refused(): void
    {
        $this->fakeApi(['*/consumable-shipments' => Http::response($this->shipments(false, 'received')), '*/consumables' => Http::response($this->stock([35]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumable-shipments')->assertOk()->assertSee('Sudah diterima')->assertSee('diterima 35')->assertSee('kurang 5')->assertSee('Lima penyok');
        $foreign = $this->shipments(false);
        $foreign['data'][0]['destination_store_id'] = '01HZ8Y6Q3K5M7N9P2R4S6T8V0Y';
        $foreign['data'][0]['source_store_id'] = '01HZ8Y6Q3K5M7N9P2R4S6T8V0X';
        $this->fakeApi(['*/consumable-shipments' => Http::response($foreign), '*/consumables' => Http::response($this->stock([0]))]);
        $this->withSession($this->login())->get('/app/stores/'.self::STORE.'/consumable-shipments')->assertStatus(503);
    }

    public function test_dispatch_drops_empty_rows_and_refuses_an_empty_shipment_without_calling_the_api(): void
    {
        $this->fakeApi(['*' => Http::response(['data' => ['id' => self::SHIPMENT]], 201)]);
        $dest = '01HZ8Y6Q3K5M7N9P2R4S6T8V0G';
        $rows = [['consumable_item_id' => self::ITEM, 'quantity' => '40'], ['consumable_item_id' => '', 'quantity' => ''], ['consumable_item_id' => self::ITEM, 'quantity' => '']];
        $this->withSession($this->login())->post('/app/stores/'.self::STORE.'/consumable-shipments', ['operation_id' => self::OP, 'destination_store_id' => $dest, 'note' => 'Mingguan', 'lines' => $rows, 'unit_price' => 1])->assertRedirect('/app/stores/'.self::STORE.'/consumable-shipments');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/consumable-shipments') && count($r['lines']) === 1 && $r['lines'][0]['quantity'] === 40 && ! isset($r['unit_price']));
        $this->fakeApi(['*' => Http::response(['data' => []], 201)]);
        $this->withSession($this->login())->post('/app/stores/'.self::STORE.'/consumable-shipments', ['operation_id' => self::OP, 'destination_store_id' => $dest, 'lines' => [['consumable_item_id' => '', 'quantity' => '']]])->assertSessionHasErrors('lines');
        Http::assertNothingSent();
    }

    public function test_receive_forwards_integers_and_returns_to_the_store_page(): void
    {
        $this->fakeApi(['*' => Http::response(['data' => ['status' => 'received']])]);
        $body = ['operation_id' => self::OP, 'store_id' => self::STORE, 'version' => '1', 'note' => 'Lima penyok', 'lines' => [['line_id' => self::LINE, 'destination_item_id' => self::ITEM, 'quantity_received' => '35']], 'status' => 'received'];
        $this->withSession($this->login())->post('/app/consumable-shipments/'.self::SHIPMENT.'/receive', $body)->assertRedirect('/app/stores/'.self::STORE.'/consumable-shipments');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/receive') && $r['version'] === 1 && $r['lines'][0]['quantity_received'] === 35 && ! isset($r['status']) && ! isset($r['store_id']));
        $this->post('/app/consumable-shipments/'.self::SHIPMENT.'/receive', [...$body, 'lines' => [['line_id' => self::LINE, 'quantity_received' => '35']]])->assertSessionHasErrors('lines.0.destination_item_id');
    }

    public function test_a_business_rule_refusal_from_the_api_shows_its_reason_instead_of_a_generic_error(): void
    {
        $this->fakeApi(['*' => Http::response(['message' => 'Alasan selisih wajib diisi bila jumlah diterima kurang.'], 422)]);
        $body = ['operation_id' => self::OP, 'store_id' => self::STORE, 'version' => '1', 'lines' => [['line_id' => self::LINE, 'destination_item_id' => self::ITEM, 'quantity_received' => '35']]];
        $this->withSession($this->login())->post('/app/consumable-shipments/'.self::SHIPMENT.'/receive', $body)->assertSessionHasErrors(['form' => 'Alasan selisih wajib diisi bila jumlah diterima kurang.']);
        $this->fakeApi(['*' => Http::response([], 422)]);
        $this->post('/app/consumable-shipments/'.self::SHIPMENT.'/receive', $body)->assertSessionHasErrors(['form' => 'Data tidak valid.']);
    }
}
