<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Application\RecipientOrderActions;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $center;

    private Organization $partner;

    private Store $source;

    private Store $target;

    private Product $supplierProduct;

    private Product $localProduct;

    private User $owner;

    private User $lead;

    private User $manager;

    private User $cashier;

    private User $warehouse;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $this->partner = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $other = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $this->source = Store::create(['name' => 'Gudang', 'organization_id' => $this->center->id, 'address' => 'Pacitan']);
        $this->target = Store::create(['name' => 'A1', 'organization_id' => $this->partner->id, 'address' => 'Ponorogo']);
        $this->owner = $this->user($this->center, 'owner');
        $this->warehouse = $this->user($this->center, 'warehouse', [$this->source]);
        $this->lead = $this->user($this->partner, 'partner_lead');
        $this->manager = $this->user($this->partner, 'store_manager', [$this->target]);
        $this->cashier = $this->user($this->partner, 'cashier', [$this->target]);
        $this->outsider = $this->user($other, 'partner_lead');
        $data = ['sku' => 'AYAM-8', 'name' => 'Dimsum Ayam', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 18000];
        $this->supplierProduct = Product::create([...$data, 'organization_id' => $this->center->id]);
        $this->localProduct = Product::create([...$data, 'organization_id' => $this->partner->id]);
    }

    private function user(Organization $org, string $role, array $stores = []): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        foreach ($stores as $s) {
            DB::table('store_user')->insert(['user_id' => $u->id, 'store_id' => $s->id]);
        }

        return $u;
    }

    private function act(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function body(int $quantity = 20): array
    {
        return ['operation_id' => (string) Str::uuid(), 'delivery_mode' => 'delivery', 'note' => 'Permintaan toko', 'lines' => [['supplier_product_id' => $this->supplierProduct->id, 'local_product_id' => $this->localProduct->id, 'quantity_packs' => $quantity]]];
    }

    private function create(int $quantity = 20): array
    {
        return $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders', $this->body($quantity))->assertCreated()->json('data');
    }

    private function transition(array $order, string $action, User $actor, array $extra = []): array
    {
        return $this->act($actor)->postJson('/api/v1/orders/'.$order['id'].'/'.$action, ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], ...$extra])->assertOk()->json('data');
    }

    private function ready(int $quantity = 20, int $stock = 20): array
    {
        $order = $this->transition($this->create($quantity), 'approve', $this->lead);
        $order = $this->transition($order, 'process', $this->owner, ['source_store_id' => $this->source->id]);
        $batch = $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'B-001', 'expires_on' => '2030-01-01', 'quantity_packs' => $stock, 'note' => 'Penerimaan fisik gudang'])->assertCreated()->json('data');

        return [$order, $batch];
    }

    private function dispatch(array $order, array $batch, int $quantity = 20): array
    {
        return $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => $quantity]]]]])->assertCreated()->json('data');
    }

    private function receipt(array $shipment, int $version, int $good = 18, int $damaged = 0): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'lines' => [['shipment_line_id' => $shipment['lines'][0]['id'], 'good_packs' => $good, 'damaged_packs' => $damaged, 'condition_note' => 'Hasil pemeriksaan fisik']]];
    }

    public function test_native_warehouse_inbox_and_process_context_hide_unapproved_and_unassigned_work(): void
    {
        $order = $this->create();
        $path = '/api/v1/stores/'.$this->source->id.'/fulfillment/orders';
        $this->act($this->owner)->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($path.'/'.$order['id'])->assertForbidden();
        $order = $this->transition($order, 'approve', $this->lead);
        $this->act($this->owner)->getJson($path)->assertJsonPath('data.0.id', $order['id'])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($path.'/'.$order['id'])->assertOk()->assertJsonPath('data.can_process', true)->assertJsonPath('data.can_dispatch', false)->assertJsonCount(0, 'data.batches');
        $this->act($this->warehouse)->getJson($path)->assertJsonCount(0, 'data');
        $this->getJson($path.'/'.$order['id'])->assertForbidden();
        $this->act($this->owner)->getJson('/api/v1/stores')->assertJsonPath('data.0.organization_type', 'center');
        $this->act($this->manager)->getJson('/api/v1/stores')->assertJsonPath('data.0.organization_type', 'partner');
        $order = $this->transition($order, 'process', $this->owner, ['source_store_id' => $this->source->id]);
        $this->act($this->warehouse)->getJson($path)->assertJsonPath('data.0.id', $order['id']);
        $this->getJson($path.'/'.$order['id'])->assertOk()->assertJsonPath('data.can_process', false)->assertJsonPath('data.can_dispatch', true);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_warehouse_context_is_read_only_and_batches_are_exact_source_product_and_pack_size(): void
    {
        [$order, $batch] = $this->ready();
        $otherSource = Store::create(['name' => 'Gudang lain', 'organization_id' => $this->center->id]);
        $foreign = InventoryBatch::findOrFail($batch['id'])->replicate();
        $foreign->store_id = $otherSource->id;
        $foreign->save();
        $wrongPack = InventoryBatch::findOrFail($batch['id'])->replicate();
        $wrongPack->fill(['pack_size' => 10, 'batch_code' => 'WrongPack', 'usable_pieces' => 100]);
        $wrongPack->save();
        $before = [DB::table('operation_receipts')->count(), DB::table('audit_entries')->count(), DB::table('stock_entries')->count()];
        $url = '/api/v1/stores/'.$this->source->id.'/fulfillment/orders/'.$order['id'];
        $r = $this->act($this->warehouse)->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.source.id', $this->source->id)->assertJsonPath('data.order.version', $order['version'])
            ->assertJsonPath('data.order.lines.0.remaining_packs', 20)->assertJsonPath('data.order.lines.0.sent_packs', 0)
            ->assertJsonCount(1, 'data.batches')->assertJsonPath('data.batches.0.id', $batch['id']);
        $this->assertArrayNotHasKey('wholesale_price', $r->json('data.batches.0'));
        $this->assertSame($before, [DB::table('operation_receipts')->count(), DB::table('audit_entries')->count(), DB::table('stock_entries')->count()]);
        foreach ([$this->lead, $this->manager, $this->cashier, $this->outsider, $this->user($this->center, 'warehouse')] as $user) {
            $this->act($user)->getJson($url)->assertForbidden();
            $this->getJson('/api/v1/stores/'.$this->source->id.'/fulfillment/orders')->assertForbidden();
        }
        $this->act($this->owner)->getJson('/api/v1/stores/'.$otherSource->id.'/fulfillment/orders/'.$order['id'])->assertForbidden();
        $this->getJson('/api/v1/stores/'.$this->target->id.'/fulfillment/orders')->assertForbidden();
    }

    public function test_native_warehouse_multi_batch_allocation_has_one_shipment_and_exact_remaining_quota(): void
    {
        [$order, $first] = $this->ready();
        $second = $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'B-002', 'expires_on' => '2030-02-01', 'quantity_packs' => 10, 'note' => 'Second physical batch'])->assertCreated()->json('data');
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $first['id'], 'quantity_packs' => 5], ['batch_id' => $second['id'], 'quantity_packs' => 3]]]]];
        $url = '/api/v1/orders/'.$order['id'].'/shipments';
        $result = $this->postJson($url, $v)->assertCreated()->json('data');
        $this->postJson($url, $v)->assertCreated()->assertJsonPath('data.shipment.id', $result['shipment']['id']);
        $this->getJson('/api/v1/stores/'.$this->source->id.'/fulfillment/orders/'.$order['id'])->assertOk()->assertJsonPath('data.order.lines.0.sent_packs', 8)->assertJsonPath('data.order.lines.0.remaining_packs', 12);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('shipment_lines', 2);
        $this->assertSame(120, InventoryBatch::findOrFail($first['id'])->usable_pieces);
        $this->assertSame(56, InventoryBatch::findOrFail($second['id'])->usable_pieces);
        $this->assertSame(0, InventoryBatch::where('store_id', $this->target->id)->count());
    }

    public function test_native_warehouse_remaining_counts_sent_not_good_and_replay_never_reopens_quota(): void
    {
        [$order, $batch] = $this->ready();
        $url = '/api/v1/stores/'.$this->source->id.'/fulfillment/orders/'.$order['id'];
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas fisik', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => 10]]]]];
        $result = $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', $v)->assertCreated()->json('data');
        $this->getJson($url)->assertJsonPath('data.can_dispatch', true)->assertJsonPath('data.order.lines.0.remaining_packs', 10);
        $last = $this->dispatch($result['order'], $batch, 10);
        $this->act($this->manager)->postJson('/api/v1/shipments/'.$last['shipment']['id'].'/receive', $this->receipt($last['shipment'], $last['order']['version'], 8, 2))->assertOk();
        $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', $v)->assertCreated()->assertJsonPath('data.shipment.id', $result['shipment']['id']);
        $this->getJson($url)->assertJsonPath('data.order.status', 'partially_received')->assertJsonPath('data.can_dispatch', false)->assertJsonCount(0, 'data.batches')->assertJsonPath('data.order.lines.0.sent_packs', 20)->assertJsonPath('data.order.lines.0.remaining_packs', 0)->assertJsonCount(2, 'data.shipments');
        $this->assertDatabaseCount('shipments', 2);
        $this->assertSame(0, InventoryBatch::findOrFail($batch['id'])->usable_pieces);
    }

    public function test_inactive_target_or_source_disables_native_warehouse_actions_without_exposing_batches(): void
    {
        [$order, $batch] = $this->ready();
        $url = '/api/v1/stores/'.$this->source->id.'/fulfillment/orders/'.$order['id'];
        $this->target->update(['active' => false]);
        $this->act($this->warehouse)->getJson($url)->assertOk()->assertJsonPath('data.can_dispatch', false)->assertJsonCount(0, 'data.batches');
        $this->target->update(['active' => true]);
        $this->source->update(['active' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_dispatch', false)->assertJsonCount(0, 'data.batches');
        $this->assertSame(160, InventoryBatch::findOrFail($batch['id'])->usable_pieces);
    }

    public function test_native_warehouse_supports_internal_transfer_without_self_source_or_commercial_value(): void
    {
        $target = Store::create(['name' => 'Toko sendiri', 'organization_id' => $this->center->id]);
        $v = $this->body(2);
        $v['delivery_mode'] = 'pickup';
        $v['lines'][0]['local_product_id'] = $this->supplierProduct->id;
        $order = $this->act($this->owner)->postJson('/api/v1/stores/'.$target->id.'/orders', $v)->assertCreated()->json('data');
        $order = $this->transition($order, 'approve', $this->owner);
        $this->getJson('/api/v1/stores/'.$this->source->id.'/fulfillment/orders/'.$order['id'])->assertOk()->assertJsonPath('data.can_process', true)->assertJsonPath('data.order.total_amount', 0);
        $this->getJson('/api/v1/stores/'.$target->id.'/fulfillment/orders/'.$order['id'])->assertForbidden();
        $this->getJson('/api/v1/stores/'.$target->id.'/fulfillment/orders')->assertJsonCount(0, 'data');
    }

    public function test_proposal_requires_lead_approval_before_visible_to_center_and_never_reserves_stock(): void
    {
        $order = $this->create();
        $this->assertSame('awaiting_approval', $order['status']);
        $this->assertSame(360000, $order['total_amount']);
        $this->assertDatabaseCount('stock_entries', 0);
        $this->act($this->owner)->getJson('/api/v1/supply/orders')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders/'.$order['id'])->assertForbidden();
        foreach ([$this->cashier, $this->manager, $this->owner] as $actor) {
            $this->act($actor)->postJson('/api/v1/orders/'.$order['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1])->assertForbidden();
        }
        $this->transition($order, 'approve', $this->lead);
        $this->act($this->owner)->getJson('/api/v1/supply/orders')->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_cashier_and_other_partner_cannot_read_create_approve_or_receive_orders(): void
    {
        $order = $this->create();
        foreach ([$this->cashier, $this->outsider] as $actor) {
            $this->act($actor)->getJson('/api/v1/stores/'.$this->target->id.'/orders')->assertForbidden();
            $this->getJson('/api/v1/stores/'.$this->target->id.'/supply-catalog')->assertForbidden();
            $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', $this->body())->assertForbidden();
            $this->getJson('/api/v1/orders/'.$order['id'])->assertForbidden();
            $this->getJson('/api/v1/orders/'.$order['id'].'/shipments')->assertForbidden();
        }
    }

    public function test_same_create_key_replays_result_and_changed_payload_conflicts(): void
    {
        $body = $this->body();
        $url = '/api/v1/stores/'.$this->target->id.'/orders';
        $this->act($this->manager);
        $id = $this->postJson($url, $body)->assertCreated()->json('data.id');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.id', $id);
        $body['lines'][0]['quantity_packs'] = 1;
        $this->postJson($url, $body)->assertConflict();
        $this->assertDatabaseCount('supply_orders', 1);
    }

    public function test_wrong_local_tenant_and_mismatched_pack_are_rejected_without_partial_writes(): void
    {
        $body = $this->body();
        $body['lines'][0]['local_product_id'] = $this->supplierProduct->id;
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders', $body)->assertNotFound();
        $this->assertDatabaseCount('operation_receipts', 0);
        $this->localProduct->update(['pack_size' => 10]);
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', $this->body())->assertUnprocessable();
        $this->assertDatabaseCount('supply_orders', 0);
    }

    public function test_price_changes_before_approval_require_explicit_amendment_and_approved_snapshot_stays_fixed(): void
    {
        $order = $this->create();
        $this->supplierProduct->update(['wholesale_price' => 20000]);
        $this->act($this->lead)->postJson('/api/v1/orders/'.$order['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1])->assertConflict();
        $body = $this->body();
        $body['version'] = 1;
        $order = $this->putJson('/api/v1/orders/'.$order['id'], $body)->assertOk()->assertJsonPath('data.total_amount', 400000)->json('data');
        $order = $this->transition($order, 'approve', $this->lead);
        $this->supplierProduct->update(['wholesale_price' => 25000]);
        $this->getJson('/api/v1/orders/'.$order['id'])->assertJsonPath('data.lines.0.unit_price', 20000);
        $body['version'] = $order['version'];
        $body['operation_id'] = (string) Str::uuid();
        $this->putJson('/api/v1/orders/'.$order['id'], $body)->assertConflict();
    }

    public function test_rejection_amendment_and_stale_versions_are_audited(): void
    {
        $order = $this->transition($this->create(), 'reject', $this->lead, ['reason' => 'Tinjau jumlah']);
        $body = $this->body(10);
        $body['version'] = 1;
        $this->act($this->lead)->putJson('/api/v1/orders/'.$order['id'], $body)->assertConflict();
        $body['version'] = $order['version'];
        $body['operation_id'] = (string) Str::uuid();
        $this->putJson('/api/v1/orders/'.$order['id'], $body)->assertOk()->assertJsonPath('data.status', 'awaiting_approval')->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('audit_entries', ['action' => 'order.amended', 'subject_id' => $order['id']]);
    }

    public function test_actual_receipt_18_of_20_adds_144_pieces_and_retries_never_duplicate_stock(): void
    {
        [$order,$batch] = $this->ready();
        $dispatch = $this->dispatch($order, $batch);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch['id'], 'usable_pieces' => 0]);
        $this->assertDatabaseMissing('inventory_batches', ['store_id' => $this->target->id]);
        $shipment = $dispatch['shipment'];
        $v = $this->receipt($shipment, $dispatch['order']['version']);
        $url = '/api/v1/shipments/'.$shipment['id'].'/receive';
        $this->act($this->manager)->postJson($url, $v)->assertOk()->assertJsonPath('data.order.status', 'partially_received');
        $this->postJson($url, $v)->assertOk();
        $this->assertDatabaseHas('inventory_batches', ['store_id' => $this->target->id, 'product_id' => $this->localProduct->id, 'usable_pieces' => 144]);
        $this->assertDatabaseCount('stock_entries', 3);
        $v['operation_id'] = (string) Str::uuid();
        $v['version'] = SupplyOrder::find($order['id'])->version;
        $this->postJson($url, $v)->assertConflict();
        $this->assertDatabaseCount('stock_entries', 3);
    }

    public function test_damaged_stock_is_quarantined_and_never_marked_completed(): void
    {
        [$order,$batch] = $this->ready();
        $d = $this->dispatch($order, $batch);
        $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', $this->receipt($d['shipment'], $d['order']['version'], 18, 2))->assertOk()->assertJsonPath('data.order.status', 'partially_received');
        $this->assertDatabaseHas('inventory_batches', ['store_id' => $this->target->id, 'usable_pieces' => 144, 'quarantined_pieces' => 16]);
        $this->assertDatabaseHas('stock_entries', ['store_id' => $this->target->id, 'bucket' => 'quarantined', 'quantity_pieces' => 16]);
    }

    public function test_two_partial_shipments_complete_only_after_actual_receipt_of_all_units(): void
    {
        [$order,$batch] = $this->ready();
        $d = $this->dispatch($order, $batch, 18);
        $r = $this->act($this->manager)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', $this->receipt($d['shipment'], $d['order']['version'], 18))->assertOk()->json('data.order');
        $d = $this->dispatch($r, $batch, 2);
        $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', $this->receipt($d['shipment'], $d['order']['version'], 2))->assertOk()->assertJsonPath('data.order.status', 'completed');
        $this->assertDatabaseHas('inventory_batches', ['store_id' => $this->target->id, 'usable_pieces' => 160]);
    }

    public function test_dispatch_insufficient_stock_rolls_back_shipment_and_idempotency_claim(): void
    {
        [$order,$batch] = $this->ready(20, 1);
        $before = DB::table('operation_receipts')->count();
        $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => 20]]]]])->assertConflict();
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('operation_receipts', $before);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch['id'], 'usable_pieces' => 8]);
    }

    public function test_cross_tenant_or_wrong_product_batch_cannot_be_dispatched(): void
    {
        [$order,$batch] = $this->ready();
        $forged = InventoryBatch::create(['organization_id' => $this->partner->id, 'store_id' => $this->target->id, 'product_id' => $this->localProduct->id, 'sku' => 'LOCAL', 'name' => 'LOCAL', 'batch_code' => 'FORGED', 'expires_on' => '2030-01-01', 'pack_size' => 8, 'condition' => 'frozen', 'usable_pieces' => 999]);
        $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $forged->id, 'quantity_packs' => 20]]]]])->assertNotFound();
        $this->assertDatabaseHas('inventory_batches', ['id' => $forged->id, 'usable_pieces' => 999]);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_unassigned_warehouse_cannot_see_or_dispatch_another_locations_orders(): void
    {
        [$order,$batch] = $this->ready();
        $actor = $this->user($this->center, 'warehouse');
        $this->act($actor)->getJson('/api/v1/supply/orders')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders/'.$order['id'])->assertForbidden();
        $this->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => 1]]]]])->assertForbidden();
    }

    public function test_receipt_overflow_or_missing_rows_or_missing_discrepancy_note_is_atomic(): void
    {
        [$order,$batch] = $this->ready();
        $d = $this->dispatch($order, $batch);
        $url = '/api/v1/shipments/'.$d['shipment']['id'].'/receive';
        $v = $this->receipt($d['shipment'], $d['order']['version'], 21);
        $this->act($this->manager)->postJson($url, $v)->assertUnprocessable();
        $v = $this->receipt($d['shipment'], $d['order']['version'], 18);
        $v['lines'][0]['condition_note'] = '';
        $this->postJson($url, $v)->assertUnprocessable();
        $this->assertDatabaseMissing('inventory_batches', ['store_id' => $this->target->id]);
    }

    public function test_intake_is_idempotent_requires_center_permissions_and_conflicting_batch_expiry_rolls_back(): void
    {
        $v = ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'B1', 'expires_on' => '2030-01-01', 'quantity_packs' => 2, 'note' => 'Penerimaan fisik'];
        $url = '/api/v1/stores/'.$this->source->id.'/inventory/intakes';
        $this->act($this->owner)->postJson($url, $v)->assertCreated();
        $this->postJson($url, $v)->assertCreated();
        $this->assertDatabaseCount('stock_entries', 1);
        $this->assertDatabaseHas('inventory_batches', ['usable_pieces' => 16]);
        $v['operation_id'] = (string) Str::uuid();
        $v['expires_on'] = '2031-01-01';
        $this->postJson($url, $v)->assertConflict();
        $this->assertDatabaseCount('stock_entries', 1);
        $this->act($this->lead)->postJson('/api/v1/stores/'.$this->target->id.'/inventory/intakes', [...$v, 'product_id' => $this->localProduct->id])->assertForbidden();
    }

    public function test_disabled_store_and_organization_block_new_fulfillment(): void
    {
        [$order,$batch] = $this->ready();
        $this->target->update(['active' => false]);
        $this->act($this->owner)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => 1]]]]])->assertConflict();
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_internal_store_transfer_never_creates_wholesale_amount(): void
    {
        $target = Store::create(['name' => 'Toko sendiri', 'organization_id' => $this->center->id]);
        $v = $this->body(2);
        $v['delivery_mode'] = 'pickup';
        $v['lines'][0]['local_product_id'] = $this->supplierProduct->id;
        $o = $this->act($this->owner)->postJson('/api/v1/stores/'.$target->id.'/orders', $v)->assertCreated()->assertJsonPath('data.kind', 'internal_transfer')->assertJsonPath('data.total_amount', 0)->json('data');
        $this->transition($o, 'approve', $this->owner);
        $this->assertDatabaseHas('supply_order_lines', ['supply_order_id' => $o['id'], 'unit_price' => 0]);
    }

    public function test_approved_pack_snapshot_survives_later_catalog_edits(): void
    {
        [$order,$batch] = $this->ready();
        $this->localProduct->update(['pack_size' => 10]);
        $this->supplierProduct->update(['pack_size' => 10, 'wholesale_price' => 999]);
        $d = $this->dispatch($order, $batch);
        $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', $this->receipt($d['shipment'], $d['order']['version'], 20))->assertOk()->assertJsonPath('data.order.total_amount', 360000);
        $this->assertDatabaseHas('inventory_batches', ['store_id' => $this->target->id, 'pack_size' => 8, 'usable_pieces' => 160]);
    }

    public function test_cancelled_request_cannot_be_approved_and_audits_are_preserved(): void
    {
        $o = $this->transition($this->create(), 'cancel', $this->lead, ['reason' => 'Tidak dibutuhkan']);
        $this->act($this->lead)->postJson('/api/v1/orders/'.$o['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $o['version']])->assertConflict();
        $this->assertDatabaseHas('audit_entries', ['action' => 'order.cancel', 'subject_id' => $o['id']]);
    }

    public function test_dispatch_same_operation_replays_without_consuming_stock_twice(): void
    {
        [$o,$b] = $this->ready();
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $o['version'], 'handler' => 'Petugas', 'lines' => [['order_line_id' => $o['lines'][0]['id'], 'allocations' => [['batch_id' => $b['id'], 'quantity_packs' => 18]]]]];
        $url = '/api/v1/orders/'.$o['id'].'/shipments';
        $this->act($this->warehouse);
        $id = $this->postJson($url, $v)->assertCreated()->json('data.shipment.id');
        $this->postJson($url, $v)->assertCreated()->assertJsonPath('data.shipment.id', $id);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('inventory_batches', ['id' => $b['id'], 'usable_pieces' => 16]);
        $this->assertDatabaseCount('stock_entries', 2);
    }

    public function test_cashier_inventory_is_scoped_and_cannot_read_ledger_or_receive(): void
    {
        [$o,$b] = $this->ready();
        $d = $this->dispatch($o, $b);
        $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->target->id.'/inventory/batches')->assertOk();
        $this->getJson('/api/v1/stores/'.$this->source->id.'/inventory/batches')->assertForbidden();
        $this->getJson('/api/v1/stores/'.$this->target->id.'/inventory/ledger')->assertForbidden();
        $this->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', $this->receipt($d['shipment'], $d['order']['version'], 20))->assertForbidden();
    }

    public function test_internal_transfer_moves_stock_without_commercial_amount(): void
    {
        [$unused,$batch] = $this->ready();
        $target = Store::create(['name' => 'Toko sendiri', 'organization_id' => $this->center->id]);
        $body = $this->body(2);
        $body['delivery_mode'] = 'pickup';
        $body['lines'][0]['local_product_id'] = $this->supplierProduct->id;
        $order = $this->act($this->owner)->postJson('/api/v1/stores/'.$target->id.'/orders', $body)->assertCreated()->json('data');
        $order = $this->transition($order, 'approve', $this->owner);
        $order = $this->transition($order, 'process', $this->owner, ['source_store_id' => $this->source->id]);
        $dispatch = $this->dispatch($order, $batch, 2);
        $this->act($this->owner)->postJson('/api/v1/shipments/'.$dispatch['shipment']['id'].'/receive', $this->receipt($dispatch['shipment'], $dispatch['order']['version'], 2))->assertOk()->assertJsonPath('data.order.status', 'completed')->assertJsonPath('data.order.total_amount', 0);
        $this->assertDatabaseHas('inventory_batches', ['store_id' => $target->id, 'product_id' => $this->supplierProduct->id, 'usable_pieces' => 16]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch['id'], 'usable_pieces' => 144]);
        $this->assertSame(160, (int) DB::table('stock_entries')->sum('quantity_pieces'));
    }

    public function test_second_item_stock_failure_rolls_back_first_item_debit(): void
    {
        [$unused,$batch] = $this->ready();
        $supplier = Product::create([...$this->supplierProduct->only(['name', 'condition', 'pack_size', 'portion_size', 'retail_price', 'wholesale_price']), 'sku' => 'UDANG-8', 'organization_id' => $this->center->id]);
        $local = Product::create([...$this->localProduct->only(['name', 'condition', 'pack_size', 'portion_size', 'retail_price', 'wholesale_price']), 'sku' => 'UDANG-8', 'organization_id' => $this->partner->id]);
        $second = $this->act($this->owner)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $supplier->id, 'batch_code' => 'B-002', 'expires_on' => '2030-01-01', 'quantity_packs' => 1, 'note' => 'Pemeriksaan fisik'])->assertCreated()->json('data');
        $v = $this->body(2);
        $v['lines'][] = ['supplier_product_id' => $supplier->id, 'local_product_id' => $local->id, 'quantity_packs' => 2];
        $o = $this->act($this->lead)->postJson('/api/v1/stores/'.$this->target->id.'/orders', $v)->assertCreated()->json('data');
        $o = $this->transition($o, 'approve', $this->lead);
        $o = $this->transition($o, 'process', $this->owner, ['source_store_id' => $this->source->id]);
        $entries = DB::table('stock_entries')->count();
        $this->act($this->warehouse)->postJson('/api/v1/orders/'.$o['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $o['version'], 'handler' => 'Petugas', 'lines' => [
            ['order_line_id' => $o['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => 2]]],
            ['order_line_id' => $o['lines'][1]['id'], 'allocations' => [['batch_id' => $second['id'], 'quantity_packs' => 2]]],
        ]])->assertConflict();
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch['id'], 'usable_pieces' => 160]);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('stock_entries', $entries);
    }

    public function test_native_recipient_actions_follow_role_status_and_active_target(): void
    {
        $order = $this->create();
        $url = '/api/v1/orders/'.$order['id'];
        $this->act($this->manager)->getJson($url)->assertOk()->assertJsonPath('data.recipient_actions', ['amend' => false, 'approve' => false, 'reject' => false, 'cancel' => false, 'receive' => false]);
        $this->act($this->lead)->getJson($url)->assertOk()->assertJsonPath('data.fulfillment_enabled', true)->assertJsonPath('data.recipient_actions', ['amend' => true, 'approve' => true, 'reject' => true, 'cancel' => true, 'receive' => false]);
        $order = $this->transition($order, 'approve', $this->lead);
        $this->act($this->lead)->getJson($url)->assertJsonPath('data.recipient_actions', ['amend' => false, 'approve' => false, 'reject' => false, 'cancel' => true, 'receive' => false]);
        $this->target->update(['active' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.recipient_actions', ['amend' => false, 'approve' => false, 'reject' => false, 'cancel' => false, 'receive' => false]);
    }

    public function test_native_receive_action_never_grants_supplier_or_foreign_access(): void
    {
        [$order, $batch] = $this->ready();
        $dispatch = $this->dispatch($order, $batch);
        $url = '/api/v1/orders/'.$order['id'];
        $this->act($this->manager)->getJson($url)->assertOk()->assertJsonPath('data.recipient_actions.receive', true)->assertJsonPath('data.recipient_actions.cancel', false);
        $this->act($this->owner)->getJson($url)->assertOk()->assertJsonPath('data.access_side', 'supplier')->assertJsonPath('data.recipient_actions', ['amend' => false, 'approve' => false, 'reject' => false, 'cancel' => false, 'receive' => false]);
        foreach ([$this->cashier, $this->outsider] as $actor) {
            $this->act($actor)->getJson($url)->assertForbidden();
        }
        $body = $this->receipt($dispatch['shipment'], $dispatch['order']['version'], 20);
        $this->act($this->manager)->postJson('/api/v1/shipments/'.$dispatch['shipment']['id'].'/receive', $body)->assertOk();
        $this->getJson($url)->assertJsonPath('data.recipient_actions.receive', false)->assertJsonPath('data.status', 'completed');
    }

    public function test_native_actions_are_hints_and_optional_fulfillment_controls_receive_only(): void
    {
        $order = $this->create();
        $this->act($this->manager)->postJson('/api/v1/orders/'.$order['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'recipient_actions' => ['approve' => true]])->assertForbidden();
        [$order, $batch] = $this->ready();
        $dispatch = $this->dispatch($order, $batch);
        $flags = app(RecipientOrderActions::class)->for($this->manager, SupplyOrder::findOrFail($order['id']), 'recipient', false);
        $this->assertFalse($flags['receive']);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_invalid_second_receipt_line_rolls_back_first_credit_and_operation_claim(): void
    {
        $supplier = Product::create(['organization_id' => $this->center->id, 'sku' => 'SAPI8', 'name' => 'Sapi', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3500, 'wholesale_price' => 20000]);
        $local = Product::create(['organization_id' => $this->partner->id, 'sku' => 'LOCAL-SAPI8', 'name' => 'Sapi lokal', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3500, 'wholesale_price' => 20000]);
        $body = $this->body();
        $body['lines'][] = ['supplier_product_id' => $supplier->id, 'local_product_id' => $local->id, 'quantity_packs' => 20];
        $order = $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders', $body)->assertCreated()->json('data');
        $order = $this->transition($order, 'approve', $this->lead);
        $order = $this->transition($order, 'process', $this->owner, ['source_store_id' => $this->source->id]);
        $batches = [];
        foreach ([$this->supplierProduct, $supplier] as $product) {
            $batches[$product->id] = $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $product->id, 'batch_code' => 'LOT-'.$product->sku, 'expires_on' => '2030-01-01', 'quantity_packs' => 20, 'note' => 'Penerimaan fisik'])->assertCreated()->json('data.id');
        }
        $lines = [];
        foreach ($order['lines'] as $line) {
            $lines[] = ['order_line_id' => $line['id'], 'allocations' => [['batch_id' => $batches[$line['supplier_product_id']], 'quantity_packs' => 20]]];
        }
        $dispatch = $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'Gudang', 'lines' => $lines])->assertCreated()->json('data');
        $lines = array_map(fn ($line) => ['shipment_line_id' => $line['id'], 'good_packs' => 20, 'damaged_packs' => 0, 'condition_note' => 'Dihitung fisik'], $dispatch['shipment']['lines']);
        $lines[1]['good_packs'] = 21;
        $operation = (string) Str::uuid();
        $this->act($this->manager)->postJson('/api/v1/shipments/'.$dispatch['shipment']['id'].'/receive', ['operation_id' => $operation, 'version' => $dispatch['order']['version'], 'lines' => $lines])->assertUnprocessable();
        $this->assertSame(0, InventoryBatch::where('store_id', $this->target->id)->count());
        $this->assertSame(0, DB::table('stock_entries')->where('source_type', 'shipment_receipt')->count());
        $this->assertSame(0, DB::table('shipment_lines')->whereNotNull('good_packs')->count());
        $this->assertDatabaseHas('shipments', ['id' => $dispatch['shipment']['id'], 'received_at' => null]);
        $this->assertDatabaseMissing('operation_receipts', ['operation_id' => $operation]);
        $this->assertDatabaseHas('supply_orders', ['id' => $order['id'], 'status' => 'dispatched', 'version' => $dispatch['order']['version']]);
    }

    public function test_preview_is_authoritative_and_never_creates_an_order_or_reserves_stock(): void
    {
        $body = $this->body();
        unset($body['operation_id']);
        $body['total_amount'] = 1;
        $body['lines'][0]['unit_price'] = 1;
        $preview = $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders/preview', $body)->assertOk()->json('data');
        $this->assertSame(360000, $preview['total_amount']);
        $this->assertSame(18000, $preview['lines'][0]['unit_price']);
        $this->assertSame('wholesale', $preview['kind']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['review_hash']);
        foreach (['supply_orders', 'stock_entries', 'operation_receipts', 'audit_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $payload = [...$this->body(), 'review_hash' => $preview['review_hash']];
        $order = $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', $payload)->assertCreated()->json('data');
        $this->assertSame('awaiting_approval', $order['status']);
        $this->supplierProduct->update(['wholesale_price' => 19000]);
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', $payload)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->assertDatabaseCount('supply_orders', 1);
        $this->act($this->owner)->getJson('/api/v1/supply/orders')->assertJsonCount(0, 'data');
    }

    public function test_review_hash_rejects_changed_price_quantity_mapping_and_store_snapshot(): void
    {
        $body = $this->body();
        unset($body['operation_id']);
        $preview = $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders/preview', $body)->assertOk()->json('data');
        $changed = [...$this->body(), 'review_hash' => $preview['review_hash']];
        $changed['lines'][0]['quantity_packs'] = 1;
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', $changed)->assertConflict();
        $alternate = Product::create(['organization_id' => $this->partner->id, 'sku' => 'ALT8', 'name' => 'Alternatif', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 18000]);
        $mapping = [...$this->body(), 'review_hash' => $preview['review_hash']];
        $mapping['lines'][0]['local_product_id'] = $alternate->id;
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', $mapping)->assertConflict();
        $this->supplierProduct->update(['wholesale_price' => 19000]);
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', [...$this->body(), 'review_hash' => $preview['review_hash']])->assertConflict();
        $this->supplierProduct->update(['wholesale_price' => 18000]);
        $this->target->update(['address' => 'Alamat baru']);
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', [...$this->body(), 'review_hash' => $preview['review_hash']])->assertConflict();
        $this->assertDatabaseCount('supply_orders', 0);
        $this->assertDatabaseCount('operation_receipts', 0);
    }

    public function test_preview_rejects_denied_tenants_inactive_target_and_mismatched_units(): void
    {
        $body = $this->body();
        unset($body['operation_id']);
        foreach ([$this->cashier, $this->outsider, $this->owner] as $actor) {
            $this->act($actor)->postJson('/api/v1/stores/'.$this->target->id.'/orders/preview', $body)->assertForbidden();
        }
        $this->localProduct->update(['pack_size' => 10]);
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders/preview', $body)->assertUnprocessable();
        $this->localProduct->update(['pack_size' => 8]);
        $this->target->update(['active' => false]);
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders/preview', $body)->assertConflict();
        $this->assertDatabaseCount('supply_orders', 0);
    }

    public function test_entry_context_declares_internal_transfer_and_preview_has_zero_commercial_value(): void
    {
        $target = Store::create(['organization_id' => $this->center->id, 'name' => 'Toko sendiri', 'address' => 'Pacitan']);
        $context = $this->act($this->owner)->getJson('/api/v1/stores/'.$target->id.'/operations-context')->assertOk()->json('data');
        $this->assertSame('internal_transfer', $context['order_kind']);
        $body = ['delivery_mode' => 'pickup', 'lines' => [['supplier_product_id' => $this->supplierProduct->id, 'local_product_id' => $this->supplierProduct->id, 'quantity_packs' => 3]]];
        $preview = $this->postJson('/api/v1/stores/'.$target->id.'/orders/preview', $body)->assertOk()->json('data');
        $this->assertSame(0, $preview['total_amount']);
        $this->assertSame(0, $preview['lines'][0]['unit_price']);
        $this->postJson('/api/v1/stores/'.$target->id.'/orders', [...$body, 'review_hash' => $preview['review_hash'], 'operation_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.kind', 'internal_transfer');
    }

    public function test_preview_delivery_needs_real_address_and_malformed_hash_never_creates(): void
    {
        $this->target->update(['address' => null]);
        $body = $this->body();
        unset($body['operation_id']);
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders/preview', $body)->assertUnprocessable();
        $body['delivery_mode'] = 'pickup';
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders/preview', $body)->assertOk()->assertJsonPath('data.destination_address', null);
        $this->postJson('/api/v1/stores/'.$this->target->id.'/orders', [...$body, 'operation_id' => (string) Str::uuid(), 'review_hash' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('supply_orders', 0);
        $this->assertDatabaseCount('operation_receipts', 0);
    }

    public function test_amend_preview_binds_order_version_and_keeps_original_until_confirmation(): void
    {
        $order = $this->create();
        $body = $this->body(10);
        unset($body['operation_id']);
        $body['version'] = $order['version'];
        $before = SupplyOrder::with('lines')->findOrFail($order['id'])->toArray();
        $preview = $this->act($this->lead)->postJson('/api/v1/orders/'.$order['id'].'/preview', $body)->assertOk()->json('data');
        $this->assertSame($order['id'], $preview['order_id']);
        $this->assertSame(180000, $preview['total_amount']);
        $this->assertSame($before, SupplyOrder::with('lines')->findOrFail($order['id'])->toArray());
        $this->assertDatabaseCount('audit_entries', 1);
        $this->assertDatabaseCount('operation_receipts', 1);
        $payload = [...$body, 'review_hash' => $preview['review_hash'], 'operation_id' => (string) Str::uuid()];
        $saved = $this->putJson('/api/v1/orders/'.$order['id'], $payload)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'awaiting_approval')->json('data');
        $this->assertSame($order['id'], $saved['id']);
        $this->assertSame(10, $saved['lines'][0]['quantity_packs']);
        $this->supplierProduct->update(['wholesale_price' => 19000]);
        $this->putJson('/api/v1/orders/'.$order['id'], $payload)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.total_amount', 180000);
        $this->assertDatabaseCount('supply_orders', 1);
        $this->assertDatabaseCount('audit_entries', 2);
        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_amend_review_rejects_cross_order_stale_version_and_price_without_deleting_lines(): void
    {
        $one = $this->create();
        $two = $this->create();
        $body = $this->body(10);
        unset($body['operation_id']);
        $body['version'] = 1;
        $preview = $this->act($this->lead)->postJson('/api/v1/orders/'.$one['id'].'/preview', $body)->assertOk()->json('data');
        $payload = [...$body, 'review_hash' => $preview['review_hash'], 'operation_id' => (string) Str::uuid()];
        $this->putJson('/api/v1/orders/'.$two['id'], $payload)->assertConflict();
        $this->supplierProduct->update(['wholesale_price' => 19000]);
        $this->putJson('/api/v1/orders/'.$one['id'], [...$payload, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->supplierProduct->update(['wholesale_price' => 18000]);
        $this->transition($one, 'reject', $this->lead, ['reason' => 'Hitung ulang']);
        $this->postJson('/api/v1/orders/'.$one['id'].'/preview', $body)->assertConflict();
        $this->putJson('/api/v1/orders/'.$one['id'], [...$payload, 'operation_id' => (string) Str::uuid()])->assertConflict();
        foreach ([$one, $two] as $original) {
            $current = SupplyOrder::with('lines')->findOrFail($original['id']);
            $this->assertSame($original['lines'][0]['id'], $current->lines[0]->id);
            $this->assertSame(20, $current->lines[0]->quantity_packs);
        }
        $this->assertDatabaseCount('operation_receipts', 3);
    }

    public function test_amend_preview_and_save_deny_unapproved_roles_and_approved_order(): void
    {
        $order = $this->create();
        $body = $this->body(10);
        unset($body['operation_id']);
        $body['version'] = 1;
        foreach ([$this->manager, $this->cashier, $this->outsider, $this->owner] as $actor) {
            $this->act($actor)->postJson('/api/v1/orders/'.$order['id'].'/preview', $body)->assertForbidden();
            $this->putJson('/api/v1/orders/'.$order['id'], [...$body, 'operation_id' => (string) Str::uuid()])->assertForbidden();
        }
        $order = $this->transition($order, 'approve', $this->lead);
        $body['version'] = $order['version'];
        $this->act($this->lead)->getJson('/api/v1/orders/'.$order['id'])->assertJsonPath('data.recipient_actions.amend', false);
        $this->postJson('/api/v1/orders/'.$order['id'].'/preview', $body)->assertConflict();
        $this->putJson('/api/v1/orders/'.$order['id'], [...$body, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('supply_orders',1);
    }
}
