<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderRevisionTest extends TestCase
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

    private function received(int $sent = 20, int $good = 17, int $damaged = 2): array
    {
        [$o,$b] = $this->ready();
        $d = $this->dispatch($o, $b, $sent);

        return $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $d['order']['version'], 'note' => 'Physical check', 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => $good, 'damaged_packs' => $damaged, 'condition_note' => 'Damage or shortage noted']]])->assertOk()->json('data');
    }

    private function path(array $data): string
    {
        return '/api/v1/orders/'.$data['order']['id'].'/shipments/'.$data['shipment']['id'].'/discrepancy';
    }

    private function reportBody(array $data): array
    {
        return ['operation_id' => (string) Str::uuid(), 'order_version' => $data['order']['version'], 'reason' => 'Report physical discrepancy', 'reference' => 'Inspection log 1'];
    }

    private function report(array $data): array
    {
        return $this->act($this->lead)->postJson($this->path($data), $this->reportBody($data))->assertCreated()->json('data');
    }

    private function propose(array $data, int $version = 1, string $type = 'replacement'): array
    {
        return $this->act($this->owner)->postJson($this->path($data).'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => $version, 'type' => $type, 'proposal' => 'Proposed administrative plan', 'reference' => 'Check physical handling first'])->assertCreated()->json('data');
    }

    private function revisionBody(array $o, int $qty, array $additions = []): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $o['version'], 'reason' => 'Reviewed change of needs', 'existing' => [['order_line_id' => $o['lines'][0]['id'], 'quantity_packs' => $qty]], 'additions' => $additions];
    }

    private function extra(): array
    {
        $data = ['sku' => 'UDANG-8', 'name' => 'Udang', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 4000, 'wholesale_price' => 24000];
        $p = Product::create([...$data, 'organization_id' => $this->center->id]);
        $l = Product::create([...$data, 'organization_id' => $this->partner->id]);

        return ['supplier_product_id' => $p->id, 'local_product_id' => $l->id, 'quantity_packs' => 3];
    }

    public function test_recipient_substitution_after_partial_shipment_requires_supplier_acceptance_and_keeps_received_history(): void
    {
        [$o,$b] = $this->ready(stock: 40);
        $d = $this->dispatch($o, $b, 8);
        $o = $d['order'];
        $extra = $this->extra();
        $url = '/api/v1/orders/'.$o['id'].'/revisions';
        $entries = DB::table('stock_entries')->count();
        $shipment = DB::table('shipment_lines')->get()->toJson();
        $p = $this->act($this->lead)->postJson($url, $this->revisionBody($o, 8, [$extra]))->assertCreated()->json('data');
        $this->assertSame(360000, SupplyOrder::find($o['id'])->total_amount);
        $this->assertSame($entries, DB::table('stock_entries')->count());
        $this->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $p['order']['version'], 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 8, 'damaged_packs' => 0]]])->assertConflict();
        $decision = ['operation_id' => (string) Str::uuid(), 'version' => $p['order']['version']];
        $path = $url.'/'.$p['revision']['id'].'/approve';
        $this->postJson($path, $decision)->assertForbidden();
        $accepted = $this->act($this->owner)->postJson($path, $decision)->assertOk()->assertJsonPath('data.order.total_amount', 216000)->assertJsonCount(2, 'data.order.lines')->json('data');
        $this->postJson($path, $decision)->assertOk();
        $this->assertSame($shipment, DB::table('shipment_lines')->get()->toJson());
        $this->assertSame($entries, DB::table('stock_entries')->count());
        $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $accepted['order']['version'], 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 8, 'damaged_packs' => 0]]])->assertOk();
        $this->assertSame(64, DB::table('inventory_batches')->where('store_id', $this->target->id)->value('usable_pieces'));
        $this->act($this->owner)->getJson($url)->assertOk()->assertJsonPath('data.0.kind', 'revision')->assertJsonPath('pending', null);
        $this->getJson('/api/v1/orders/'.$o['id'].'/changes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unshipped_removal_and_addition_keep_zero_tombstone_and_use_frozen_new_quote(): void
    {
        [$o,$b] = $this->ready(stock: 40);
        $url = '/api/v1/orders/'.$o['id'].'/revisions';
        $extra = $this->extra();
        $p = $this->act($this->lead)->postJson($url, $this->revisionBody($o, 0, [$extra]))->assertCreated()->json('data');
        Product::whereKey($extra['supplier_product_id'])->update(['wholesale_price' => 99999]);
        $r = $this->act($this->owner)->postJson($url.'/'.$p['revision']['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $p['order']['version']])->assertOk()->assertJsonPath('data.order.total_amount', 72000)->json('data');
        $this->assertDatabaseHas('supply_order_lines', ['id' => $o['lines'][0]['id'], 'quantity_packs' => 0]);
        $this->assertSame(24000, collect($r['order']['lines'])->firstWhere('supplier_product_id', $extra['supplier_product_id'])['unit_price']);
        $this->getJson('/api/v1/orders/'.$o['id'].'/changes')->assertJsonPath('actions.propose', false);
        $this->getJson($url)->assertJsonPath('actions.propose', true);
    }

    public function test_shipments_bound_reductions_and_pending_workflows_are_mutually_exclusive(): void
    {
        [$o,$b] = $this->ready();
        $d = $this->dispatch($o, $b, 8);
        $o = $d['order'];
        $url = '/api/v1/orders/'.$o['id'].'/revisions';
        $this->act($this->owner)->postJson($url, $this->revisionBody($o, 7))->assertUnprocessable();
        $this->postJson($url, $this->revisionBody($o, 21))->assertUnprocessable();
        $this->postJson($url, $this->revisionBody($o, 20))->assertUnprocessable();
        $body = $this->revisionBody($o, 10);
        $p = $this->postJson($url, $body)->assertCreated()->json('data');
        $this->postJson($url, $body)->assertCreated();
        $this->postJson($url, [...$body, 'reason' => 'Changed'])->assertConflict();
        $this->postJson($url, $this->revisionBody($p['order'], 9))->assertConflict();
        $this->act($this->lead)->postJson('/api/v1/orders/'.$o['id'].'/changes/'.$p['revision']['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => $p['order']['version']])->assertNotFound();
        $this->act($this->lead)->postJson($url.'/'.$p['revision']['id'].'/reject', ['operation_id' => (string) Str::uuid(), 'version' => $p['order']['version'], 'reason' => 'Keep original'])->assertOk()->assertJsonPath('data.order.lines.0.quantity_packs', 20);
    }

    public function test_tenant_roles_supplier_new_mapping_and_withdraw_guards(): void
    {
        [$o,$b] = $this->ready();
        $url = '/api/v1/orders/'.$o['id'].'/revisions';
        $body = $this->revisionBody($o, 10, [$this->extra()]);
        foreach ([$this->warehouse, $this->manager, $this->cashier, $this->outsider] as $u) {
            $this->act($u)->postJson($url, $body)->assertForbidden();
        }
        $this->act($this->owner)->postJson($url, $body)->assertForbidden();
        $p = $this->act($this->lead)->postJson($url, $body)->assertCreated()->json('data');
        $this->postJson($url.'/'.$p['revision']['id'].'/withdraw', ['operation_id' => (string) Str::uuid(), 'version' => $p['order']['version'], 'reason' => 'Recheck mapping'])->assertOk()->assertJsonPath('data.revision.status', 'withdrawn');
        $this->assertDatabaseCount('stock_entries', 1);
        $migration = require database_path('migrations/2026_11_03_000010_add_order_revision_kind.php');
        try {
            $migration->down();
            $this->fail('Used history rollback');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ditolak', $e->getMessage());
        }
    }

    public function test_preview_hash_freezes_new_price_and_quote_drift_never_commits_proposal(): void
    {
        [$o,$b] = $this->ready();
        $extra = $this->extra();
        $body = $this->revisionBody($o, 0, [$extra]);
        $url = '/api/v1/orders/'.$o['id'].'/revisions';
        $this->act($this->lead);
        $receipts = DB::table('operation_receipts')->count();
        $ledger = DB::table('stock_entries')->count();
        $preview = $this->postJson($url.'/preview', $body)->assertOk()->json('data');
        $again = $this->postJson($url.'/preview', $body)->assertOk()->json('data');
        $this->assertSame($preview['review_hash'], $again['review_hash']);
        $this->assertSame($receipts, DB::table('operation_receipts')->count());
        $this->assertSame($ledger, DB::table('stock_entries')->count());
        Product::whereKey($extra['supplier_product_id'])->update(['wholesale_price' => 30000]);
        $this->postJson($url, [...$body, 'review_hash' => $preview['review_hash']])->assertConflict();
        $this->assertDatabaseCount('order_changes', 0);
        $fresh = $this->postJson($url.'/preview', $body)->assertOk()->json('data');
        $this->postJson($url, [...$body, 'review_hash' => $fresh['review_hash']])->assertCreated()->assertJsonPath('data.revision.after.total_amount', 90000);
    }
}
