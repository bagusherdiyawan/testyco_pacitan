<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Ordering\Infrastructure\SupplyOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReplacementTest extends TestCase
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
        [$o,$b] = $this->ready(quantity: max(20, $sent), stock: max(40, $sent * 2));
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

    private function confirmed(string $type = 'replacement', int $sent = 20): array
    {
        $d = $this->received($sent, $sent - 3, 2);
        $this->report($d);
        $p = $this->propose($d, type: $type);
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();

        return $d;
    }

    private function replacementBody(array $d, int $qty = 3, int $version = 3, ?string $batch = null): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'handler' => 'Courier <script>x</script>', 'note' => 'Physical dispatch', 'lines' => [['original_shipment_line_id' => $d['shipment']['lines'][0]['id'], 'allocations' => [['batch_id' => $batch ?? DB::table('inventory_batches')->where('store_id', $this->source->id)->value('id'), 'quantity_packs' => $qty]]]]];
    }

    private function receiptBody(array $r, int $good = 3, int $damage = 0, int $version = 4): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version, 'note' => 'Checked physical replacement', 'lines' => [['replacement_line_id' => $r['replacement']['lines'][0]['id'], 'good_packs' => $good, 'damaged_packs' => $damage, 'condition_note' => 'Checked discrepancy']]];
    }

    public function test_end_to_end_replacement_posts_real_stock_once_without_rewriting_original_or_creating_financial_values(): void
    {
        $d = $this->confirmed();
        $path = $this->path($d).'/replacements';
        $order = SupplyOrder::with('lines')->find($d['order']['id'])->toArray();
        $original = DB::table('shipment_lines')->get()->toJson();
        $before = DB::table('inventory_batches')->where('store_id', $this->source->id)->first();
        $this->supplierProduct->update(['sku' => 'NEW10', 'pack_size' => 10, 'wholesale_price' => 99999]);
        $body = $this->replacementBody($d);
        $this->act($this->warehouse)->getJson($path)->assertOk()->assertJsonPath('execution_status', 'ready')->assertJsonPath('can_dispatch', true)->assertJsonPath('quota.0.limit_packs', 3)->assertJsonPath('quota.0.remaining_packs', 3);
        $r = $this->postJson($path, [...$body, 'amount' => 99999])->assertCreated()->json('data');
        $this->assertSame('AYAM-8', $r['replacement']['lines'][0]['sku']);
        $this->assertSame(8, $r['replacement']['lines'][0]['pack_size']);
        $this->postJson($path, $body)->assertCreated()->assertJsonPath('data.replacement.id', $r['replacement']['id']);
        $this->assertSame($before->usable_pieces - 24, DB::table('inventory_batches')->where('id', $before->id)->value('usable_pieces'));
        $this->assertSame($before->stock_revision + 1, DB::table('inventory_batches')->where('id', $before->id)->value('stock_revision'));
        $this->getJson($path)->assertJsonPath('execution_status', 'in_transit')->assertJsonPath('quota.0.remaining_packs', 0)->assertJsonPath('can_dispatch', false);
        $receive = $path.'/'.$r['replacement']['id'].'/receive';
        $receipt = $this->receiptBody($r);
        $this->act($this->manager)->postJson($receive, $receipt)->assertOk()->assertJsonPath('data.execution_status', 'fulfilled')->assertJsonPath('data.case_version', 5);
        $this->postJson($receive, $receipt)->assertOk();
        $this->postJson($receive, [...$receipt, 'operation_id' => (string) Str::uuid(), 'version' => 5])->assertConflict();
        $target = DB::table('inventory_batches')->where('store_id', $this->target->id)->first();
        $this->assertSame(160, $target->usable_pieces);
        $this->assertSame(16, $target->quarantined_pieces);
        $this->assertDatabaseCount('replacement_shipments', 1);
        $this->assertDatabaseCount('replacement_lines', 1);
        $this->assertSame(1, DB::table('stock_entries')->where('source_type', 'replacement_dispatch')->count());
        $this->assertSame(1, DB::table('stock_entries')->where('source_type', 'replacement_receipt')->count());
        $this->assertSame($order, SupplyOrder::with('lines')->find($d['order']['id'])->toArray());
        $this->assertSame($original, DB::table('shipment_lines')->get()->toJson());
        $this->getJson('/api/v1/stores/'.$this->target->id.'/inventory/ledger?source_type=replacement_receipt')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity_pieces', 24);
        $this->assertSame(4, DB::table('audit_entries')->whereIn('action', ['replacement.dispatched', 'replacement.received'])->count());
        $this->act($this->owner)->postJson($path, [...$this->replacementBody($d, 1, 5)])->assertUnprocessable();
        // Original dispatch quota still cannot be expanded by replacement delivery.
        $this->postJson('/api/v1/orders/'.$d['order']['id'].'/shipments', ['operation_id' => (string) Str::uuid(), 'version' => $order['version'], 'handler' => 'No extra', 'lines' => [['order_line_id' => $order['lines'][0]['id'], 'allocations' => [['batch_id' => $before->id, 'quantity_packs' => 1]]]]])->assertUnprocessable();
    }

    public function test_partial_replacement_quota_is_sent_not_good_and_secondary_damage_requires_follow_up(): void
    {
        $d = $this->confirmed(sent: 8);
        $path = $this->path($d).'/replacements';
        $r = $this->act($this->warehouse)->postJson($path, $this->replacementBody($d, 1))->assertCreated()->json('data');
        $this->act($this->lead)->postJson($path.'/'.$r['replacement']['id'].'/receive', $this->receiptBody($r, 1))->assertOk()->assertJsonPath('data.execution_status', 'partially_fulfilled');
        $this->getJson($path)->assertJsonPath('quota.0.limit_packs', 3)->assertJsonPath('quota.0.remaining_packs', 2); // unsent12 is not part of quota.
        $r = $this->act($this->warehouse)->postJson($path, $this->replacementBody($d, 2, 5))->assertCreated()->json('data');
        $this->act($this->lead)->postJson($path.'/'.$r['replacement']['id'].'/receive', $this->receiptBody($r, 0, 1, 6))->assertOk()->assertJsonPath('data.execution_status', 'requires_follow_up');
        $this->getJson($path)->assertJsonPath('quota.0.remaining_packs', 0)->assertJsonPath('quota.0.replacement_good_packs', 1)->assertJsonPath('can_dispatch', false);
        $target = DB::table('inventory_batches')->where('store_id', $this->target->id)->first();
        $this->assertSame(48, $target->usable_pieces);
        $this->assertSame(24, $target->quarantined_pieces);
    }

    public function test_scope_roles_plan_type_and_inactive_targets_are_enforced(): void
    {
        $d = $this->confirmed();
        $path = $this->path($d).'/replacements';
        $body = $this->replacementBody($d);
        foreach ([$this->outsider, $this->cashier] as $u) {
            $this->act($u)->getJson($path)->assertForbidden();
            $this->postJson($path, $body)->assertForbidden();
        }
        $this->act($this->manager)->getJson($path)->assertOk()->assertJsonPath('can_dispatch', false)->assertJsonCount(0, 'batches');
        $this->postJson($path, $body)->assertForbidden();
        DB::table('store_user')->where('user_id', $this->warehouse->id)->delete();
        $this->act($this->warehouse)->getJson($path)->assertForbidden();
        $this->act($this->owner)->getJson(str_replace($d['order']['id'], (string) Str::ulid(), $path))->assertNotFound();
        $this->target->update(['active' => false]);
        $this->getJson($path)->assertOk()->assertJsonPath('can_dispatch', false);
        $this->postJson($path, $body)->assertConflict();
        $this->target->update(['active' => true]);
        $r = $this->postJson($path, $body)->assertCreated()->json('data');
        $this->postJson($path.'/'.$r['replacement']['id'].'/receive', $this->receiptBody($r))->assertForbidden();
        $this->act($this->manager)->postJson($path.'/'.Str::ulid().'/receive', $this->receiptBody($r))->assertNotFound();
        $this->source->update(['active' => false]);
        $this->postJson($path.'/'.$r['replacement']['id'].'/receive', $this->receiptBody($r))->assertConflict();
        $this->source->update(['active' => true]);
        DB::table('store_user')->insert(['user_id' => $this->warehouse->id, 'store_id' => $this->source->id]);
        $d2 = $this->confirmed('credit');
        $this->act($this->owner)->getJson($this->path($d2).'/replacements')->assertConflict();
        $this->postJson($this->path($d2).'/replacements', $this->replacementBody($d2))->assertConflict();
    }

    public function test_failed_dispatch_and_receipt_roll_back_all_stock_and_uuid_records_and_stale_case_is_rejected(): void
    {
        $d = $this->confirmed();
        $path = $this->path($d).'/replacements';
        $before = DB::table('inventory_batches')->get()->toJson();
        $entries = DB::table('stock_entries')->count();
        $this->act($this->owner)->postJson($path, $this->replacementBody($d, 4))->assertUnprocessable();
        $this->postJson($path, $this->replacementBody($d, 1, 2))->assertConflict();
        $this->postJson($path, $this->replacementBody($d, 1, 3, (string) Str::ulid()))->assertNotFound();
        $body = $this->replacementBody($d);
        $body['lines'][0]['allocations'][] = $body['lines'][0]['allocations'][0];
        $this->postJson($path, $body)->assertUnprocessable();
        $batch = DB::table('inventory_batches')->where('store_id', $this->source->id)->first();
        DB::table('inventory_batches')->where('id', $batch->id)->update(['usable_pieces' => 8]);
        $this->postJson($path, $this->replacementBody($d))->assertConflict();
        DB::table('inventory_batches')->where('id', $batch->id)->update(['usable_pieces' => $batch->usable_pieces]);
        $this->assertDatabaseCount('replacement_shipments', 0);
        $this->assertSame($before, DB::table('inventory_batches')->get()->toJson());
        $this->assertSame($entries, DB::table('stock_entries')->count());
        $body = $this->replacementBody($d);
        $r = $this->postJson($path, $body)->assertCreated()->json('data');
        $this->postJson($path, [...$body, 'handler' => 'Different'])->assertConflict();
        $receipt = $this->receiptBody($r);
        $targetBefore = DB::table('inventory_batches')->where('store_id', $this->target->id)->get()->toJson();
        $this->act($this->lead)->postJson($path.'/'.$r['replacement']['id'].'/receive', [...$receipt, 'lines' => []])->assertUnprocessable();
        $bad = $receipt;
        $bad['lines'][0]['good_packs'] = 4;
        $this->postJson($path.'/'.$r['replacement']['id'].'/receive', $bad)->assertUnprocessable();
        $bad = $receipt;
        $bad['lines'][0]['good_packs'] = 2;
        $bad['lines'][0]['condition_note'] = '';
        $this->postJson($path.'/'.$r['replacement']['id'].'/receive', $bad)->assertUnprocessable();
        $this->assertSame($targetBefore, DB::table('inventory_batches')->where('store_id', $this->target->id)->get()->toJson());
        $this->postJson($this->path($d).'/notes', ['operation_id' => (string) Str::uuid(), 'version' => 4, 'message' => 'Check again'])->assertOk();
        $this->postJson($path.'/'.$r['replacement']['id'].'/receive', $receipt)->assertConflict();
        $this->postJson($path.'/'.$r['replacement']['id'].'/receive', [...$receipt, 'version' => 5])->assertOk();
        $migration = require database_path('migrations/2026_11_03_000009_create_replacement_shipments.php');
        try {
            $migration->down();
            $this->fail('Used migration must refuse rollback');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ditolak', $e->getMessage());
        }
    }

    public function test_multi_allocation_failure_rolls_back_prior_debit_and_foreign_batch_is_rejected(): void
    {
        $d = $this->confirmed();
        $path = $this->path($d).'/replacements';
        $body = $this->replacementBody($d, 1);
        $before = DB::table('inventory_batches')->orderBy('id')->get()->toJson();
        $receipts = DB::table('operation_receipts')->count();
        $entries = DB::table('stock_entries')->count();
        $body['lines'][0]['allocations'][] = ['batch_id' => (string) Str::ulid(), 'quantity_packs' => 1];
        $this->act($this->owner)->postJson($path, $body)->assertNotFound();
        $this->assertSame($before, DB::table('inventory_batches')->orderBy('id')->get()->toJson());
        $this->assertSame($receipts, DB::table('operation_receipts')->count());
        $this->assertSame($entries, DB::table('stock_entries')->count());
        $this->assertDatabaseCount('replacement_shipments', 0);
        $targetBatch = DB::table('inventory_batches')->where('store_id', $this->target->id)->value('id');
        $this->postJson($path, $this->replacementBody($d, 1, 3, $targetBatch))->assertNotFound();
        $this->partner->update(['active' => false]);
        $this->postJson($path, $this->replacementBody($d))->assertConflict();
        $this->partner->update(['active' => true]);
        $d2 = $this->confirmed();
        $r = $this->act($this->owner)->postJson($path, $this->replacementBody($d))->assertCreated()->json('data');
        $this->act($this->lead)->postJson($this->path($d2).'/replacements/'.$r['replacement']['id'].'/receive', $this->receiptBody($r, 3, 0, 3))->assertNotFound();
    }

    public function test_history_pagination_does_not_limit_global_quota_and_inactive_store_read_does_not_allow_receipt(): void
    {
        $d = $this->received(100, 49, 51);
        $this->report($d);
        $p = $this->propose($d);
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertOk();
        $case = DB::table('shipment_discrepancies')->where('shipment_id', $d['shipment']['id'])->first();
        for ($i = 0; $i < 51; $i++) {
            $id = (string) Str::ulid();
            $row = $d['shipment']['lines'][0];
            DB::table('replacement_shipments')->insert(['id' => $id, 'case_id' => $case->id, 'resolution_id' => $p['resolution']['id'], 'source_store_id' => $this->source->id, 'dispatched_by' => $this->owner->id, 'received_by' => $this->lead->id, 'handler' => 'Historical physical delivery', 'dispatched_at' => now(), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('replacement_lines')->insert(['id' => (string) Str::ulid(), 'replacement_shipment_id' => $id, 'original_shipment_line_id' => $row['id'], 'inventory_batch_id' => $row['inventory_batch_id'], 'local_product_id' => $this->localProduct->id, 'sku' => 'AYAM-8', 'name' => 'Ayam', 'local_sku' => 'AYAM-8', 'local_name' => 'Ayam', 'condition' => 'frozen', 'batch_code' => 'B-001', 'expires_on' => '2030-01-01', 'pack_size' => 8, 'quantity_packs' => 1, 'good_packs' => 1, 'damaged_packs' => 0]);
        }
        $path = $this->path($d).'/replacements';
        $this->getJson($path)->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('total', 51)->assertJsonPath('quota.0.replacement_good_packs', 51)->assertJsonPath('quota.0.remaining_packs', 0)->assertJsonPath('execution_status', 'fulfilled');
        $this->target->update(['active' => false]);
        $this->getJson($path.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('current_page', 2)->assertJsonPath('data.0.can_receive', false);
        $this->getJson('/api/v1/organizations/'.$this->partner->id.'/audit?action=replacement.received')->assertOk();
    }

    public function test_batch_choices_exclude_products_without_a_discrepancy(): void
    {
        $d = $this->confirmed();
        $other = Product::create(['organization_id' => $this->center->id, 'sku' => 'OTHER8', 'name' => 'Other', 'condition' => 'frozen', 'pack_size' => 8, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 18000]);
        // An unaffected order line may retain its own warehouse stock; it is not a replacement choice.
        $original = SupplyOrderLine::where('supply_order_id', $d['order']['id'])->first();
        $unaffected = $original->replicate();
        $unaffected->supplier_product_id = $other->id;
        $unaffected->sku = 'OTHER8';
        $unaffected->save();
        $this->act($this->owner)->postJson('/api/v1/stores/'.$this->source->id.'/inventory/intakes', ['operation_id' => (string) Str::uuid(), 'product_id' => $other->id, 'batch_code' => 'UNAFFECTED', 'expires_on' => '2030-01-01', 'quantity_packs' => 2, 'note' => 'Other physical stock'])->assertCreated();
        $this->getJson($this->path($d).'/replacements')->assertOk()->assertJsonCount(1, 'batches')->assertJsonPath('batches.0.product_id', $this->supplierProduct->id);
    }
}
