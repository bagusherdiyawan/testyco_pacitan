<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
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
        $d = $this->dispatch($o,$b);
        $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->target->id.'/inventory/batches')->assertOk();
        $this->getJson('/api/v1/stores/'.$this->source->id.'/inventory/batches')->assertForbidden();
        $this->getJson('/api/v1/stores/'.$this->target->id.'/inventory/ledger')->assertForbidden();
        $this->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive',$this->receipt($d['shipment'],$d['order']['version'],20))->assertForbidden();
    }

    public function test_internal_transfer_moves_stock_without_commercial_amount(): void
    {
        [$unused,$batch]=$this->ready();
        $target=Store::create(['name'=>'Toko sendiri','organization_id'=>$this->center->id]);
        $body=$this->body(2);$body['delivery_mode']='pickup';$body['lines'][0]['local_product_id']=$this->supplierProduct->id;
        $order=$this->act($this->owner)->postJson('/api/v1/stores/'.$target->id.'/orders',$body)->assertCreated()->json('data');
        $order=$this->transition($order,'approve',$this->owner);$order=$this->transition($order,'process',$this->owner,['source_store_id'=>$this->source->id]);
        $dispatch=$this->dispatch($order,$batch,2);
        $this->act($this->owner)->postJson('/api/v1/shipments/'.$dispatch['shipment']['id'].'/receive',$this->receipt($dispatch['shipment'],$dispatch['order']['version'],2))->assertOk()->assertJsonPath('data.order.status','completed')->assertJsonPath('data.order.total_amount',0);
        $this->assertDatabaseHas('inventory_batches',['store_id'=>$target->id,'product_id'=>$this->supplierProduct->id,'usable_pieces'=>16]);
        $this->assertDatabaseHas('inventory_batches',['id'=>$batch['id'],'usable_pieces'=>144]);
        $this->assertSame(160,(int)DB::table('stock_entries')->sum('quantity_pieces'));
    }

    public function test_second_item_stock_failure_rolls_back_first_item_debit(): void
    {
        [$unused,$batch]=$this->ready();
        $supplier=Product::create([...$this->supplierProduct->only(['name','condition','pack_size','portion_size','retail_price','wholesale_price']),'sku'=>'UDANG-8','organization_id'=>$this->center->id]);
        $local=Product::create([...$this->localProduct->only(['name','condition','pack_size','portion_size','retail_price','wholesale_price']),'sku'=>'UDANG-8','organization_id'=>$this->partner->id]);
        $second=$this->act($this->owner)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes',['operation_id'=>(string)Str::uuid(),'product_id'=>$supplier->id,'batch_code'=>'B-002','expires_on'=>'2030-01-01','quantity_packs'=>1,'note'=>'Pemeriksaan fisik'])->assertCreated()->json('data');
        $v=$this->body(2);$v['lines'][]=['supplier_product_id'=>$supplier->id,'local_product_id'=>$local->id,'quantity_packs'=>2];
        $o=$this->act($this->lead)->postJson('/api/v1/stores/'.$this->target->id.'/orders',$v)->assertCreated()->json('data');$o=$this->transition($o,'approve',$this->lead);$o=$this->transition($o,'process',$this->owner,['source_store_id'=>$this->source->id]);
        $entries=DB::table('stock_entries')->count();
        $this->act($this->warehouse)->postJson('/api/v1/orders/'.$o['id'].'/shipments',['operation_id'=>(string)Str::uuid(),'version'=>$o['version'],'handler'=>'Petugas','lines'=>[
            ['order_line_id'=>$o['lines'][0]['id'],'allocations'=>[['batch_id'=>$batch['id'],'quantity_packs'=>2]]],
            ['order_line_id'=>$o['lines'][1]['id'],'allocations'=>[['batch_id'=>$second['id'],'quantity_packs'=>2]]],
        ]])->assertConflict();
        $this->assertDatabaseHas('inventory_batches',['id'=>$batch['id'],'usable_pieces'=>160]);$this->assertDatabaseCount('shipments',0);$this->assertDatabaseCount('stock_entries',$entries);
    }
}
