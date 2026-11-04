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

class ReturnTest extends TestCase
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

    private function confirmed(): array
    {
        $d = $this->received();
        $this->report($d);
        $p = $this->propose($d, type: 'return');
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();

        return $d;
    }

    private function sendBody(array $d, int $qty = 2, int $version = 3): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'destination_bucket' => 'quarantined', 'handler' => 'Physical return courier', 'lines' => [['original_shipment_line_id' => $d['shipment']['lines'][0]['id'], 'allocations' => [['batch_id' => DB::table('inventory_batches')->where('store_id', $this->target->id)->value('id'), 'quantity_packs' => $qty]]]]];
    }

    public function test_return_moves_only_actual_quarantine_to_original_source_quarantine_once(): void
    {
        $d = $this->confirmed();
        $path = $this->path($d).'/returns';
        $order = SupplyOrder::with('lines')->find($d['order']['id'])->toArray();
        $original = DB::table('shipment_lines')->get()->toJson();
        $this->act($this->lead)->getJson($path)->assertOk()->assertJsonPath('quota.0.limit_packs', 2)->assertJsonPath('destination_bucket', 'quarantined')->assertJsonPath('can_dispatch', true);
        $body = $this->sendBody($d);
        $r = $this->postJson($path, $body)->assertCreated()->json('data');
        $this->postJson($path, $body)->assertCreated();
        $this->assertSame(0, DB::table('inventory_batches')->where('store_id', $this->target->id)->value('quarantined_pieces'));
        $this->assertSame(136, DB::table('inventory_batches')->where('store_id', $this->target->id)->value('usable_pieces'));
        $receipt = ['operation_id' => (string) Str::uuid(), 'version' => 4, 'lines' => [['return_line_id' => $r['return']['lines'][0]['id'], 'received_packs' => 2]]];
        $url = $path.'/'.$r['return']['id'].'/receive';
        $this->act($this->warehouse)->postJson($url, $receipt)->assertOk()->assertJsonPath('data.execution_status', 'fulfilled');
        $this->postJson($url, $receipt)->assertOk();
        $source = DB::table('inventory_batches')->where('store_id', $this->source->id)->first();
        $this->assertSame(16, $source->quarantined_pieces);
        $this->assertSame(0, $source->usable_pieces);
        $this->assertSame($order, SupplyOrder::with('lines')->find($d['order']['id'])->toArray());
        $this->assertSame($original, DB::table('shipment_lines')->get()->toJson());
        $this->assertSame(2, DB::table('stock_entries')->whereIn('source_type', ['return_dispatch', 'return_receipt'])->count());
        $this->act($this->lead)->postJson($path, $this->sendBody($d, 1, 5))->assertUnprocessable();
    }

    public function test_roles_foreign_batch_partial_shortage_and_stale_case_guards(): void
    {
        $d = $this->confirmed();
        $path = $this->path($d).'/returns';
        $body = $this->sendBody($d, 1);
        foreach ([$this->owner, $this->warehouse, $this->cashier, $this->outsider] as $u) {
            $this->act($u)->postJson($path, $body)->assertForbidden();
        }
        $bad = $body;
        $bad['lines'][0]['allocations'][0]['batch_id'] = (string) Str::ulid();
        $this->act($this->lead)->postJson($path, $bad)->assertNotFound();
        $this->assertDatabaseCount('return_shipments', 0);
        $r = $this->postJson($path, $body)->assertCreated()->json('data');
        $url = $path.'/'.$r['return']['id'].'/receive';
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 4, 'lines' => [['return_line_id' => $r['return']['lines'][0]['id'], 'received_packs' => 0]]];
        $this->postJson($url, $v)->assertForbidden();
        $this->act($this->owner)->postJson($url, $v)->assertUnprocessable();
        $v['lines'][0]['condition_note'] = 'Not physically arrived';
        $this->postJson($url, $v)->assertOk()->assertJsonPath('data.execution_status', 'requires_follow_up');
        $this->act($this->lead)->postJson($path, $this->sendBody($d, 2, 5))->assertUnprocessable();
        $this->postJson($path, $this->sendBody($d, 1, 4))->assertConflict();
        $migration = require database_path('migrations/2026_11_03_000014_create_return_shipments.php');
        try {
            $migration->down();
            $this->fail('Used history');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ditolak', $e->getMessage());
        }
    }
}
