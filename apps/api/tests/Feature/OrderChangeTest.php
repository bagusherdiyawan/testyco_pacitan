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

class OrderChangeTest extends TestCase
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

    private function proposeChange(array $order, int $quantity = 12, ?string $uuid = null): array
    {
        $v = ['operation_id' => $uuid ?? (string) Str::uuid(), 'version' => $order['version'], 'reason' => 'Stok tidak cukup untuk jumlah awal', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => $quantity]]];

        return $this->act($this->owner)->postJson('/api/v1/orders/'.$order['id'].'/changes', $v)->assertCreated()->json('data');
    }

    private function decision(array $result, string $action = 'approve', ?User $actor = null, ?string $uuid = null): array
    {
        return $this->act($actor ?? $this->lead)->postJson('/api/v1/orders/'.$result['order']['id'].'/changes/'.$result['change']['id'].'/'.$action, ['operation_id' => $uuid ?? (string) Str::uuid(), 'version' => $result['order']['version'], 'reason' => 'Keputusan setelah review'])->assertOk()->json('data');
    }

    public function test_pending_keeps_original_quote_holds_all_fulfillment_then_accepts_reduced_quota_without_stock_write(): void
    {
        [$order, $batch] = $this->ready();
        $entries = DB::table('stock_entries')->count();
        $revision = InventoryBatch::find($batch['id'])->stock_revision;
        $proposal = $this->proposeChange($order);
        $this->assertSame(360000, $proposal['order']['total_amount']);
        $this->assertSame(20, $proposal['order']['lines'][0]['quantity_packs']);
        $this->assertSame(216000, $proposal['change']['after']['total_amount']);
        $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $proposal['order']['version'], 'handler' => 'Gudang', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => 1]]]]])->assertConflict();
        $this->act($this->lead)->postJson('/api/v1/orders/'.$order['id'].'/cancel', ['operation_id' => (string) Str::uuid(), 'version' => $proposal['order']['version'], 'reason' => 'Pending cannot be bypassed'])->assertConflict();
        $this->getJson('/api/v1/orders/'.$order['id'])->assertJsonPath('data.change_pending', true)->assertJsonPath('data.recipient_actions.cancel', false);
        $this->act($this->warehouse)->getJson('/api/v1/stores/'.$this->source->id.'/fulfillment/orders/'.$order['id'])->assertOk()->assertJsonPath('data.change_pending', true)->assertJsonPath('data.can_dispatch', false)->assertJsonCount(0, 'data.batches');
        $this->supplierProduct->update(['wholesale_price' => 99999, 'pack_size' => 10, 'active' => false]);
        $accepted = $this->decision($proposal);
        $this->assertSame('approved', $accepted['change']['status']);
        $this->assertSame('processing', $accepted['order']['status']);
        $this->assertSame(216000, $accepted['order']['total_amount']);
        $this->assertSame(12, $accepted['order']['lines'][0]['quantity_packs']);
        $this->assertSame(18000, $accepted['order']['lines'][0]['unit_price']);
        $this->assertSame(8, $accepted['order']['lines'][0]['pack_size']);
        $this->assertSame($order['lines'][0]['id'], $accepted['order']['lines'][0]['id']);
        $this->assertDatabaseCount('stock_entries', $entries);
        $this->assertSame($revision, InventoryBatch::find($batch['id'])->stock_revision);
        $this->assertDatabaseCount('shipments', 0);
        $this->act($this->warehouse)->postJson('/api/v1/orders/'.$order['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $accepted['order']['version'], 'handler' => 'Too many', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $batch['id'], 'quantity_packs' => 13]]]]])->assertUnprocessable();
        $this->dispatch($accepted['order'], $batch, 12);
        $this->assertSame(64, InventoryBatch::find($batch['id'])->usable_pieces);
        $this->assertDatabaseHas('order_changes', ['id' => $proposal['change']['id'], 'pending_order_id' => null]);
    }

    public function test_request_and_decision_replay_do_not_duplicate_or_apply_twice_and_payload_collision_fails(): void
    {
        [$order] = $this->ready();
        $op = (string) Str::uuid();
        $first = $this->proposeChange($order, 12, $op);
        $this->assertSame($first['change']['id'], $this->proposeChange($order, 12, $op)['change']['id']);
        $body = ['operation_id' => $op, 'version' => $order['version'], 'reason' => 'Different', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => 12]]];
        $this->postJson('/api/v1/orders/'.$order['id'].'/changes', $body)->assertConflict();
        $decision = (string) Str::uuid();
        $accepted = $this->decision($first, uuid: $decision);
        $replay = $this->decision($first, uuid: $decision);
        $this->assertSame($accepted['order']['version'], $replay['order']['version']);
        $this->assertDatabaseCount('order_changes', 1);
        $this->assertSame($first['change']['id'], $this->proposeChange($order, 12, $op)['change']['id']);
        $this->assertSame(12, SupplyOrder::find($order['id'])->lines()->first()->quantity_packs);
    }

    public function test_reject_or_supplier_withdraw_releases_hold_and_preserves_original_values(): void
    {
        [$order] = $this->ready();
        $first = $this->proposeChange($order);
        $rejected = $this->decision($first, 'reject');
        $this->assertSame(20, $rejected['order']['lines'][0]['quantity_packs']);
        $this->assertSame(360000, $rejected['order']['total_amount']);
        $second = $this->proposeChange($rejected['order'], 10);
        $withdrawn = $this->decision($second, 'withdraw', $this->owner);
        $this->assertSame('withdrawn', $withdrawn['change']['status']);
        $this->assertSame(20, $withdrawn['order']['lines'][0]['quantity_packs']);
        $this->act($this->owner)->getJson('/api/v1/orders/'.$order['id'].'/changes')->assertJsonCount(2, 'data')->assertJsonPath('actions.propose', true);
    }

    public function test_permissions_context_and_foreign_order_binding_are_enforced(): void
    {
        [$order] = $this->ready();
        $body = ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'reason' => 'Reduction', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => 12]]];
        foreach ([$this->lead, $this->manager, $this->cashier, $this->warehouse, $this->outsider] as $actor) {
            $this->act($actor)->postJson('/api/v1/orders/'.$order['id'].'/changes', $body)->assertForbidden();
        }
        $proposal = $this->proposeChange($order);
        foreach ([$this->owner, $this->manager, $this->cashier, $this->warehouse, $this->outsider] as $actor) {
            $this->act($actor)->postJson('/api/v1/orders/'.$order['id'].'/changes/'.$proposal['change']['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $proposal['order']['version']])->assertForbidden();
        }
        $this->act($this->outsider)->getJson('/api/v1/orders/'.$order['id'].'/changes')->assertForbidden();
        $this->act($this->lead)->getJson('/api/v1/orders/'.$order['id'].'/changes')->assertJsonPath('actions.approve', true)->assertJsonPath('actions.propose', false)->assertJsonPath('data.0.id', $proposal['change']['id']);
        $other = $this->transition($this->create(), 'approve', $this->lead);
        $this->postJson('/api/v1/orders/'.$other['id'].'/changes/'.$proposal['change']['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $other['version']])->assertNotFound();
    }

    public function test_stale_duplicate_missing_increase_noop_and_forged_prices_are_rejected(): void
    {
        [$order] = $this->ready();
        $this->act($this->owner);
        $base = ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'reason' => 'Reduction', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => 12]]];
        foreach ([0, 21, 20] as $quantity) {
            $this->postJson('/api/v1/orders/'.$order['id'].'/changes', [...$base, 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => $quantity]]])->assertUnprocessable();
        }
        $this->postJson('/api/v1/orders/'.$order['id'].'/changes', [...$base, 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => 12, 'unit_price' => 1]]])->assertUnprocessable();
        $this->postJson('/api/v1/orders/'.$order['id'].'/changes', [...$base, 'lines' => [$base['lines'][0], $base['lines'][0]]])->assertUnprocessable();
        $this->postJson('/api/v1/orders/'.$order['id'].'/changes', [...$base, 'version' => 1])->assertConflict();
        $this->assertDatabaseCount('order_changes', 0);
        $proposal = $this->proposeChange($order);
        $this->postJson('/api/v1/orders/'.$order['id'].'/changes', [...$base, 'operation_id' => (string) Str::uuid(), 'version' => $proposal['order']['version']])->assertConflict();
        $this->act($this->lead)->postJson('/api/v1/orders/'.$order['id'].'/changes/'.$proposal['change']['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $order['version']])->assertConflict();
        $this->assertDatabaseCount('order_changes', 1);
    }

    public function test_after_dispatch_and_unapproved_requests_cannot_be_revised_and_old_amend_stays_denied(): void
    {
        $draft = $this->create();
        $this->act($this->owner)->postJson('/api/v1/orders/'.$draft['id'].'/changes', ['operation_id' => (string) Str::uuid(), 'version' => $draft['version'], 'reason' => 'No', 'lines' => [['order_line_id' => $draft['lines'][0]['id'], 'quantity_packs' => 12]]])->assertForbidden();
        [$order, $batch] = $this->ready();
        $this->act($this->lead)->putJson('/api/v1/orders/'.$order['id'], ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'delivery_mode' => 'pickup', 'lines' => [['supplier_product_id' => $this->supplierProduct->id, 'local_product_id' => $this->localProduct->id, 'quantity_packs' => 12]]])->assertConflict();
        $sent = $this->dispatch($order, $batch, 1)['order'];
        $this->act($this->owner)->postJson('/api/v1/orders/'.$order['id'].'/changes', ['operation_id' => (string) Str::uuid(), 'version' => $sent['version'], 'reason' => 'Too late', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => 12]]])->assertConflict();
        $this->assertDatabaseCount('order_changes', 0);
    }

    public function test_internal_transfer_remains_zero_and_used_revision_history_cannot_be_rolled_back(): void
    {
        $destination = Store::create(['organization_id' => $this->center->id, 'name' => 'Own', 'address' => 'Pacitan']);
        $v = $this->body();
        $v['lines'][0]['local_product_id'] = $this->supplierProduct->id;
        $draft = $this->act($this->owner)->postJson('/api/v1/stores/'.$destination->id.'/orders', $v)->assertCreated()->json('data');
        $order = $this->transition($draft, 'approve', $this->owner);
        $proposal = $this->proposeChange($order);
        $accepted = $this->decision($proposal, actor: $this->owner);
        $this->assertSame(0, $accepted['order']['total_amount']);
        $this->assertSame('internal_transfer', $accepted['order']['kind']);
        $migration = require database_path('migrations/2026_11_02_000006_create_order_changes.php');
        try {
            $migration->down();
            $this->fail('Used history must remain.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback revisi pesanan ditolak', $e->getMessage());
        }
    }

    public function test_multiple_lines_are_complete_and_pending_before_process_cannot_bypass_hold(): void
    {
        $supplier = Product::create([...$this->supplierProduct->only(['organization_id', 'condition', 'pack_size', 'portion_size', 'retail_price', 'wholesale_price']), 'sku' => 'SAPI8', 'name' => 'Sapi']);
        $local = Product::create([...$this->localProduct->only(['organization_id', 'condition', 'pack_size', 'portion_size', 'retail_price', 'wholesale_price']), 'sku' => 'LOCAL8', 'name' => 'Lokal']);
        $body = $this->body();
        $body['lines'][] = ['supplier_product_id' => $supplier->id, 'local_product_id' => $local->id, 'quantity_packs' => 5];
        $order = $this->act($this->manager)->postJson('/api/v1/stores/'.$this->target->id.'/orders', $body)->assertCreated()->json('data');
        $order = $this->transition($order, 'approve', $this->lead);
        $v = ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'reason' => 'Review', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity_packs' => 12]]];
        $this->act($this->owner)->postJson('/api/v1/orders/'.$order['id'].'/changes', $v)->assertUnprocessable();
        $v['lines'][] = ['order_line_id' => $order['lines'][1]['id'], 'quantity_packs' => 5];
        $proposal = $this->postJson('/api/v1/orders/'.$order['id'].'/changes', $v)->assertCreated()->json('data');
        $this->postJson('/api/v1/orders/'.$order['id'].'/process', ['operation_id' => (string) Str::uuid(), 'version' => $proposal['order']['version'], 'source_store_id' => $this->source->id])->assertConflict();
        $this->target->update(['active' => false]);
        $this->act($this->lead)->postJson('/api/v1/orders/'.$order['id'].'/changes/'.$proposal['change']['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $proposal['order']['version']])->assertConflict();
        $withdrawn = $this->decision($proposal, 'withdraw', $this->owner);
        $this->assertSame($order['total_amount'], $withdrawn['order']['total_amount']);
        $this->assertDatabaseCount('shipments', 0);
    }
}
