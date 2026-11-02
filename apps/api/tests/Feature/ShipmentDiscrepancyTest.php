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

class ShipmentDiscrepancyTest extends TestCase
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

    public function test_snapshot_server_counts_and_plan_confirmation_preserve_all_stock_order_and_receipt_records(): void
    {
        $d = $this->received();
        $before = DB::table('inventory_batches')->orderBy('id')->get()->toJson();
        $ledger = DB::table('stock_entries')->orderBy('id')->get()->toJson();
        $orderBefore = SupplyOrder::with('lines')->find($d['order']['id'])->toArray();
        $shipmentBefore = DB::table('shipment_lines')->get()->toJson();
        $this->supplierProduct->update(['sku' => 'CURRENT10', 'pack_size' => 10, 'wholesale_price' => 99999]);
        $r = $this->act($this->manager)->postJson($this->path($d), [...$this->reportBody($d), 'missing_packs' => 9999, 'amount' => 99999])->assertCreated()->json('data');
        $this->assertSame(3, $r['case']['snapshot']['problem_packs']);
        $line = $r['case']['snapshot']['lines'][0];
        $this->assertSame(2, $line['damaged_packs']);
        $this->assertSame(1, $line['missing_packs']);
        $this->assertSame(16, $line['damaged_pieces']);
        $this->assertSame(8, $line['missing_pieces']);
        $this->assertSame('AYAM-8', $line['sku']);
        $this->assertSame(8, $line['pack_size']);
        $p = $this->propose($d, type: 'credit');
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'reason' => 'Reviewed plan', 'amount' => 99999])->assertOk()->assertJsonPath('data.case.status', 'plan_confirmed')->assertJsonPath('data.case.version', 3);
        $this->getJson($this->path($d))->assertOk()->assertJsonPath('pending_resolution', null)->assertJsonPath('actions.propose_resolution', false)->assertJsonPath('resolutions.data.0.status', 'confirmed');
        $this->assertSame($before, DB::table('inventory_batches')->orderBy('id')->get()->toJson());
        $this->assertSame($ledger, DB::table('stock_entries')->orderBy('id')->get()->toJson());
        $this->assertSame($orderBefore, SupplyOrder::with('lines')->find($d['order']['id'])->toArray());
        $this->assertSame($shipmentBefore, DB::table('shipment_lines')->get()->toJson());
    }

    public function test_fully_good_or_unreceived_shipments_cannot_create_cases_and_unsent_order_remainder_is_not_missing(): void
    {
        [$o,$b] = $this->ready();
        $d = $this->dispatch($o, $b, 8);
        $this->act($this->lead)->getJson($this->path($d))->assertJsonPath('actions.report', false);
        $this->postJson($this->path($d), $this->reportBody($d))->assertConflict();
        $d = $this->received(8, 8, 0);
        $this->getJson($this->path($d))->assertJsonPath('snapshot.problem_packs', 0)->assertJsonPath('actions.report', false);
        $this->postJson($this->path($d), $this->reportBody($d))->assertConflict();
        $d = $this->received(8, 6, 1);
        $r = $this->report($d);
        $this->assertSame(2, $r['case']['snapshot']['problem_packs']);
        $this->assertSame(1, $r['case']['snapshot']['lines'][0]['missing_packs']);
    }

    public function test_replay_collision_one_case_and_old_plan_ack_never_reapplies_after_new_notes(): void
    {
        $d = $this->received();
        $body = $this->reportBody($d);
        $this->act($this->lead);
        $r = $this->postJson($this->path($d), $body)->assertCreated()->json('data');
        $this->postJson($this->path($d), $body)->assertCreated()->assertJsonPath('data.case.id', $r['case']['id']);
        $this->postJson($this->path($d), [...$body, 'reason' => 'Changed'])->assertConflict();
        $this->postJson($this->path($d), $this->reportBody($d))->assertConflict();
        $p = $this->propose($d);
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 2, 'reason' => 'Plan accepted'];
        $url = $this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm';
        $this->act($this->lead)->postJson($url, $v)->assertOk()->assertJsonPath('data.case.version', 3);
        $n = ['operation_id' => (string) Str::uuid(), 'version' => 3, 'message' => 'Physical work still outstanding'];
        $this->postJson($this->path($d).'/notes', $n)->assertOk()->assertJsonPath('data.case.version', 4);
        $this->postJson($this->path($d).'/notes', $n)->assertOk();
        $this->postJson($url, $v)->assertOk()->assertJsonPath('data.case.version', 3);
        $this->getJson($this->path($d))->assertJsonPath('case.version', 4)->assertJsonPath('case.status', 'plan_confirmed')->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('shipment_discrepancies', 1);
        $this->assertDatabaseCount('shipment_discrepancy_notes', 1);
        $this->assertDatabaseCount('shipment_discrepancy_resolutions', 1);
    }

    public function test_existing_order_visibility_and_decision_roles_enforce_tenant_and_parent_binding(): void
    {
        $d = $this->received();
        $path = $this->path($d);
        foreach ([$this->cashier, $this->outsider, $this->user($this->center, 'warehouse')] as $u) {
            $this->act($u)->getJson($path)->assertForbidden();
            $this->postJson($path, $this->reportBody($d))->assertForbidden();
        }
        $this->act($this->owner)->postJson($path, $this->reportBody($d))->assertForbidden();
        $this->report($d);
        $p = $this->propose($d);
        foreach ([$this->owner, $this->manager, $this->warehouse, $this->cashier, $this->outsider] as $u) {
            $this->act($u)->postJson($path.'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertForbidden();
        }
        foreach ([$this->lead, $this->manager, $this->warehouse] as $u) {
            $this->act($u)->postJson($path.'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'type' => 'return', 'proposal' => 'Cannot authorize'])->assertForbidden();
        }
        $other = $this->create();
        $this->act($this->lead)->getJson('/api/v1/orders/'.$other['id'].'/shipments/'.$d['shipment']['id'].'/discrepancy')->assertNotFound();
        $this->act($this->outsider)->postJson($path.'/notes', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'message' => 'Foreign'])->assertForbidden();
        $this->postJson($path.'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'type' => 'credit', 'proposal' => 'Foreign'])->assertForbidden();
        $this->act($this->lead)->postJson('/api/v1/orders/'.$other['id'].'/shipments/'.$d['shipment']['id'].'/discrepancy/notes', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'message' => 'Wrong parent'])->assertNotFound();
        $this->act($this->warehouse)->getJson($path)->assertOk()->assertJsonPath('access_side', 'supplier')->assertJsonPath('actions.note', true)->assertJsonPath('actions.confirm_resolution', false);
    }

    public function test_note_stales_confirmation_reject_withdraw_and_resubmit_keep_immutable_history(): void
    {
        $d = $this->received();
        $this->report($d);
        $p = $this->propose($d, type: 'return');
        $this->act($this->warehouse)->postJson($this->path($d).'/notes', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'message' => 'Need more details'])->assertOk();
        $this->act($this->lead)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertConflict();
        $this->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/reject', ['operation_id' => (string) Str::uuid(), 'version' => 3, 'reason' => 'Revise plan'])->assertOk()->assertJsonPath('data.case.status', 'reported');
        $p = $this->propose($d, 4, 'replacement');
        $this->act($this->owner)->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/withdraw', ['operation_id' => (string) Str::uuid(), 'version' => 5, 'reason' => 'Withdraw for investigation'])->assertOk()->assertJsonPath('data.case.version', 6);
        $this->getJson($this->path($d))->assertJsonCount(2, 'resolutions.data')->assertJsonPath('pending_resolution', null)->assertJsonPath('actions.propose_resolution', true);
    }

    public function test_input_stale_and_inactive_organization_guards_leave_no_partial_claims(): void
    {
        $d = $this->received();
        $this->act($this->lead);
        $this->postJson($this->path($d), [...$this->reportBody($d), 'order_version' => 1])->assertConflict();
        $this->postJson($this->path($d), [...$this->reportBody($d), 'reason' => ''])->assertUnprocessable();
        $this->report($d);
        $this->postJson($this->path($d).'/notes', ['operation_id' => (string) Str::uuid(), 'version' => 99, 'message' => 'Stale'])->assertConflict();
        $this->act($this->owner)->postJson($this->path($d).'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'type' => 'cash_refund', 'proposal' => 'No policy'])->assertUnprocessable();
        $this->partner->update(['active' => false]);
        $this->postJson($this->path($d).'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'type' => 'credit', 'proposal' => 'Inactive'])->assertConflict();
        $this->partner->update(['active' => true]);
        $p = $this->propose($d);
        $this->center->update(['active' => false]);
        $this->act($this->lead)->getJson($this->path($d))->assertJsonPath('actions.confirm_resolution', false);
        $this->postJson($this->path($d).'/resolutions/'.$p['resolution']['id'].'/confirm', ['operation_id' => (string) Str::uuid(), 'version' => 2])->assertConflict();
    }

    public function test_internal_transfer_report_has_no_new_approval_policy_and_used_migration_cannot_drop_history(): void
    {
        $this->target = Store::create(['organization_id' => $this->center->id, 'name' => 'Own store', 'address' => 'Pacitan']);
        $this->localProduct = $this->supplierProduct;
        $this->manager = $this->owner;
        $this->lead = $this->owner;
        $d = $this->received();
        $this->report($d);
        $this->getJson($this->path($d))->assertJsonPath('order.kind', 'internal_transfer')->assertJsonPath('actions.propose_resolution', false);
        $this->postJson($this->path($d).'/resolutions', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'type' => 'return', 'proposal' => 'Needs own-store policy'])->assertForbidden();
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'shipment.discrepancy_reported')->count());
        $migration = require database_path('migrations/2026_11_02_000008_create_shipment_discrepancies.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_note_and_resolution_histories_paginate_independently_and_historical_notes_do_not_grant_audit_access(): void
    {
        $d = $this->received();
        $reported = $this->report($d);
        $case = $reported['case'];
        for ($i = 0; $i < 51; $i++) {
            $this->act($this->lead)->postJson($this->path($d).'/notes', ['operation_id' => (string) Str::uuid(), 'version' => $i + 1, 'message' => 'Follow up '.$i])->assertOk();
        }
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = ['id' => (string) Str::ulid(), 'shipment_discrepancy_id' => $case['id'], 'pending_case_id' => null, 'proposed_by' => $this->owner->id, 'decided_by' => $this->lead->id, 'type' => 'replacement', 'status' => 'rejected', 'proposal' => 'Historic plan', 'reference' => null, 'decision_note' => 'Revise', 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('shipment_discrepancy_resolutions')->insert($rows);
        $this->getJson($this->path($d))->assertJsonCount(50, 'data')->assertJsonCount(50, 'resolutions.data');
        $this->getJson($this->path($d).'?page=2&resolutions_page=2')->assertJsonCount(1, 'data')->assertJsonCount(1, 'resolutions.data');
        $this->target->update(['active' => false]);
        $this->postJson($this->path($d).'/notes', ['operation_id' => (string) Str::uuid(), 'version' => 52, 'message' => 'Follow up after store closure'])->assertOk()->assertJsonPath('data.case.version', 53);
        $this->act($this->owner)->getJson('/api/v1/organizations/'.$this->partner->id.'/audit?action=shipment.discrepancy_noted')->assertOk()->assertJsonPath('visibility', 'onboarding')->assertJsonPath('total', 0);
    }
}
