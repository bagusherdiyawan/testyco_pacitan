<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockControlTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private InventoryBatch $batch;

    private User $lead;

    private User $manager;

    private User $cashier;

    private User $otherLead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'A1']);
        $this->lead = $this->user($this->org, 'partner_lead');
        $this->manager = $this->user($this->org, 'store_manager', [$this->store]);
        $this->cashier = $this->user($this->org, 'cashier', [$this->store]);
        $other = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $this->otherLead = $this->user($other, 'partner_lead');
        $product = Product::create(['organization_id' => $this->org->id, 'sku' => 'AYAM10', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 20000]);
        $v = DB::transaction(fn () => app(StockLedger::class)->credit($this->org->id, $this->store->id, $product->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'LOT10', '2030-01-01', 1, $this->lead->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Fixture actual receipt'));
        $this->batch = InventoryBatch::findOrFail($v['id']);
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

    private function act(User $u): self
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function processBody(string $kind = 'open'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'batch_id' => $this->batch->id, 'expected_revision' => $this->batch->fresh()->stock_revision, 'kind' => $kind, 'reason' => 'Pemeriksaan dan pengolahan fisik', ...($kind === 'open' ? ['quantity_packs' => 1] : ['input_pieces' => 6, 'output_pieces' => 6])];
    }

    private function process(array $changes = [], ?User $actor = null): array
    {
        $v = [...$this->processBody($changes['kind'] ?? 'open'), ...$changes];

        return $this->act($actor ?? $this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', $v)->assertCreated()->json('data');
    }

    private function countBody(array $changes = []): array
    {
        return [...['operation_id' => (string) Str::uuid(), 'batch_id' => $this->batch->id, 'expected_revision' => $this->batch->fresh()->stock_revision, 'purpose' => 'stocktake', 'bucket' => 'opened', 'counted_pieces' => 7, 'reason' => 'Hitungan fisik selisih tiga biji'], ...$changes];
    }

    private function propose(array $changes = [], ?User $actor = null): array
    {
        return $this->act($actor ?? $this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/counts', $this->countBody($changes))->assertCreated()->json('data');
    }

    private function review(array $count, string $action = 'approve', ?User $actor = null): array
    {
        return $this->act($actor ?? $this->lead)->postJson('/api/v1/inventory/counts/'.$count['id'].'/'.$action, ['operation_id' => (string) Str::uuid(), 'version' => $count['version'], 'review_note' => 'Hasil pemeriksaan ulang disetujui'])->assertOk()->json('data');
    }

    public function test_open_one_pack_of_ten_and_cook_six_leaves_four_opened_and_six_ready(): void
    {
        $this->assertSame(10, $this->batch->usable_pieces);
        $revision = $this->batch->stock_revision;
        $open = $this->process();
        $this->assertSame(10, $open['input_pieces']);
        $this->assertSame($revision + 1, $this->batch->fresh()->stock_revision);
        $cook = $this->process(['kind' => 'cook']);
        $b = $this->batch->fresh();
        $this->assertSame(0, $b->usable_pieces);
        $this->assertSame(4, $b->opened_pieces);
        $this->assertSame(6, $b->ready_pieces);
        $this->assertSame(0, $b->waste_pieces);
        $this->assertSame(10, $b->available_pieces);
        $this->assertSame(0, (int) DB::table('stock_entries')->where('source_id', $cook['id'])->sum('quantity_pieces'));
        $this->assertDatabaseHas('audit_entries', ['action' => 'inventory.processed', 'subject_id' => $cook['id']]);
    }

    public function test_actual_cooking_loss_is_recorded_and_excluded_from_available_stock(): void
    {
        $this->process();
        $cook = $this->process(['kind' => 'cook', 'input_pieces' => 6, 'output_pieces' => 5]);
        $b = $this->batch->fresh();
        $this->assertSame(4, $b->opened_pieces);
        $this->assertSame(5, $b->ready_pieces);
        $this->assertSame(1, $b->waste_pieces);
        $this->assertSame(9, $b->available_pieces);
        $this->assertSame(1, $cook['waste_pieces']);
        $this->assertSame(0, (int) DB::table('stock_entries')->where('source_id', $cook['id'])->sum('quantity_pieces'));
    }

    public function test_zero_cooking_output_records_all_consumed_input_as_loss(): void
    {
        $this->process();
        $this->process(['kind' => 'cook', 'input_pieces' => 6, 'output_pieces' => 0]);
        $b = $this->batch->fresh();
        $this->assertSame(4, $b->opened_pieces);
        $this->assertSame(0, $b->ready_pieces);
        $this->assertSame(6, $b->waste_pieces);
    }

    public function test_process_replay_is_exact_and_changed_payload_or_actor_conflicts(): void
    {
        $v = $this->processBody();
        $url = '/api/v1/stores/'.$this->store->id.'/inventory/processes';
        $this->act($this->manager);
        $id = $this->postJson($url, $v)->assertCreated()->json('data.id');
        $this->postJson($url, $v)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('inventory_processes', 1);
        $this->assertSame(10, $this->batch->fresh()->opened_pieces);
        $this->postJson($url, [...$v, 'reason' => 'Diubah'])->assertConflict();
        $this->act($this->lead)->postJson($url, $v)->assertConflict();
    }

    public function test_insufficient_sealed_or_opened_stock_is_rejected_without_partial_ledgers(): void
    {
        $url = '/api/v1/stores/'.$this->store->id.'/inventory/processes';
        $entries = DB::table('stock_entries')->count();
        $this->act($this->manager)->postJson($url, [...$this->processBody(), 'quantity_packs' => 2])->assertConflict();
        $this->postJson($url, $this->processBody('cook'))->assertConflict();
        $this->assertDatabaseCount('stock_entries', $entries);
        $this->assertDatabaseCount('inventory_processes', 0);
        $this->assertDatabaseCount('operation_receipts', 0);
    }

    public function test_invalid_yield_negative_or_fractional_quantities_are_rejected(): void
    {
        $this->process();
        $url = '/api/v1/stores/'.$this->store->id.'/inventory/processes';
        $v = $this->processBody('cook');
        $this->act($this->manager);
        foreach ([['output_pieces' => 7], ['output_pieces' => -1], ['input_pieces' => 0], ['input_pieces' => 1.5], ['reason' => '']] as $bad) {
            $this->postJson($url, [...$v, ...$bad])->assertUnprocessable();
        }$this->assertSame(10, $this->batch->fresh()->opened_pieces);
    }

    public function test_stale_process_and_stale_count_submission_require_reloading_snapshot(): void
    {
        $v = $this->processBody();
        $count = $this->countBody(['bucket' => 'usable', 'counted_pieces' => 0]);
        $this->process();
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', [...$v, 'operation_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/v1/stores/'.$this->store->id.'/inventory/counts', $count)->assertConflict();
        $this->assertDatabaseCount('inventory_counts', 0);
    }

    public function test_stocktake_minus_three_does_not_change_balance_before_other_lead_approval(): void
    {
        $this->process();
        $c = $this->propose();
        $this->assertSame('pending', $c['status']);
        $this->assertSame(-3, $c['difference_pieces']);
        $this->assertSame(10, $this->batch->fresh()->opened_pieces);
        $this->review($c);
        $this->assertSame(7, $this->batch->fresh()->opened_pieces);
        $this->assertSame(0, $this->batch->fresh()->waste_pieces);
        $this->assertDatabaseHas('stock_entries', ['source_type' => 'stock_count', 'source_id' => $c['id'], 'quantity_pieces' => -3]);
    }

    public function test_review_replay_never_applies_count_twice_and_new_key_cannot_review_again(): void
    {
        $this->process();
        $c = $this->propose();
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Sesuai pemeriksaan ulang'];
        $url = '/api/v1/inventory/counts/'.$c['id'].'/approve';
        $this->act($this->lead)->postJson($url, $v)->assertOk();
        $this->postJson($url, $v)->assertOk();
        $this->postJson($url, [...$v, 'operation_id' => (string) Str::uuid(), 'version' => 2])->assertConflict();
        $this->assertSame(7, $this->batch->fresh()->opened_pieces);
        $this->assertSame(1, DB::table('stock_entries')->where('source_type', 'stock_count')->count());
    }

    public function test_creator_cannot_self_review_even_when_already_a_partner_lead(): void
    {
        $this->process();
        $c = $this->propose([], $this->lead);
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Diperiksa'];
        foreach (['approve', 'reject'] as $action) {
            $this->act($this->lead)->postJson('/api/v1/inventory/counts/'.$c['id'].'/'.$action, $v)->assertForbidden();
        }$this->assertSame(10, $this->batch->fresh()->opened_pieces);
    }

    public function test_store_manager_cannot_approve_even_a_different_users_count(): void
    {
        $this->process();
        $c = $this->propose([], $this->lead);
        $this->act($this->manager)->postJson('/api/v1/inventory/counts/'.$c['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Diperiksa'])->assertForbidden();
    }

    public function test_stock_movement_after_snapshot_blocks_approval_without_overwriting_new_stock(): void
    {
        $this->process();
        $c = $this->propose();
        $this->process(['kind' => 'cook', 'input_pieces' => 6, 'output_pieces' => 6]);
        $before = $this->batch->fresh()->stock_revision;
        $this->act($this->lead)->postJson('/api/v1/inventory/counts/'.$c['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Diperiksa'])->assertConflict();
        $b = $this->batch->fresh();
        $this->assertSame(4, $b->opened_pieces);
        $this->assertSame(6, $b->ready_pieces);
        $this->assertSame($before, $b->stock_revision);
        $this->assertDatabaseHas('inventory_counts', ['id' => $c['id'], 'status' => 'pending']);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/inventory/counts')->assertJsonPath('data.0.is_stale', true);
    }

    public function test_approved_waste_moves_lost_pieces_to_cumulative_loss_bucket(): void
    {
        $this->process();
        $c = $this->propose(['purpose' => 'waste']);
        $this->assertSame(0, $this->batch->fresh()->waste_pieces);
        $this->review($c);
        $b = $this->batch->fresh();
        $this->assertSame(7, $b->opened_pieces);
        $this->assertSame(3, $b->waste_pieces);
        $this->assertSame(7, $b->available_pieces);
        $this->assertSame(0, (int) DB::table('stock_entries')->where('source_id', $c['id'])->sum('quantity_pieces'));
    }

    public function test_reject_or_cancel_never_changes_inventory_and_preserves_history(): void
    {
        $this->process();
        $reject = $this->propose();
        $this->review($reject, 'reject');
        $cancel = $this->propose();
        $this->review($cancel, 'cancel', $this->manager);
        $this->assertSame(10, $this->batch->fresh()->opened_pieces);
        $this->assertDatabaseHas('inventory_counts', ['id' => $reject['id'], 'status' => 'rejected']);
        $this->assertDatabaseHas('inventory_counts', ['id' => $cancel['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'stock_count.cancel', 'subject_id' => $cancel['id']]);
    }

    public function test_only_creator_can_cancel_and_stale_review_version_is_denied(): void
    {
        $this->process();
        $c = $this->propose();
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Batal'];
        $this->act($this->lead)->postJson('/api/v1/inventory/counts/'.$c['id'].'/cancel', $v)->assertForbidden();
        $this->postJson('/api/v1/inventory/counts/'.$c['id'].'/approve', [...$v, 'version' => 999])->assertConflict();
    }

    public function test_sealed_counts_must_be_complete_historical_packages(): void
    {
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/counts', $this->countBody(['bucket' => 'usable', 'counted_pieces' => 7]))->assertUnprocessable();
        $c = $this->propose(['bucket' => 'usable', 'counted_pieces' => 20]);
        $this->review($c);
        $this->assertSame(20, $this->batch->fresh()->usable_pieces);
    }

    public function test_waste_proposal_cannot_increase_stock_or_edit_cumulative_loss(): void
    {
        $this->process();
        $url = '/api/v1/stores/'.$this->store->id.'/inventory/counts';
        $this->act($this->manager)->postJson($url, $this->countBody(['purpose' => 'waste', 'counted_pieces' => 11]))->assertUnprocessable();
        $this->postJson($url, $this->countBody(['bucket' => 'waste', 'counted_pieces' => 0]))->assertUnprocessable();
    }

    public function test_cashier_other_tenant_and_unassigned_staff_are_denied_all_stock_control_actions(): void
    {
        $unassigned = $this->user($this->org, 'store_manager');
        foreach ([$this->otherLead, $unassigned] as $u) {
            $this->act($u);
            foreach (['processes', 'counts'] as $path) {
                $this->getJson('/api/v1/stores/'.$this->store->id.'/inventory/'.$path)->assertForbidden();
            }$this->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', $this->processBody())->assertForbidden();
            $this->postJson('/api/v1/stores/'.$this->store->id.'/inventory/counts', $this->countBody())->assertForbidden();
        }
        // Assigned cashier may open/cook and view process history, but cannot propose or read opname counts.
        $this->act($this->cashier);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/inventory/processes')->assertOk();
        $this->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', $this->processBody())->assertCreated();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/inventory/counts')->assertForbidden();
        $this->postJson('/api/v1/stores/'.$this->store->id.'/inventory/counts', $this->countBody())->assertForbidden();
    }

    public function test_batch_and_count_ids_do_not_bypass_store_or_partner_scoping(): void
    {
        $otherStore = Store::create(['organization_id' => $this->org->id, 'name' => 'A2']);
        $this->act($this->lead)->postJson('/api/v1/stores/'.$otherStore->id.'/inventory/processes', $this->processBody())->assertNotFound();
        $this->process();
        $c = $this->propose();
        $this->act($this->otherLead)->postJson('/api/v1/inventory/counts/'.$c['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Luar organisasi'])->assertForbidden();
    }

    public function test_disabled_store_or_organization_blocks_processing_and_approval(): void
    {
        $this->process();
        $c = $this->propose();
        $this->store->update(['active' => false]);
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', $this->processBody('cook'))->assertConflict();
        $this->act($this->lead)->postJson('/api/v1/inventory/counts/'.$c['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Diperiksa'])->assertConflict();
        $this->store->update(['active' => true]);
        $this->org->update(['active' => false]);
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', $this->processBody('cook'))->assertForbidden();
    }

    public function test_cannot_cook_sealed_or_quarantined_stock_and_ready_sku_is_not_a_frozen_batch(): void
    {
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', $this->processBody('cook'))->assertConflict();
        $this->batch->update(['condition' => 'ready']);
        $this->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', $this->processBody())->assertUnprocessable();
    }

    public function test_no_difference_count_creates_review_record_without_stock_entry_or_revision_change(): void
    {
        $c = $this->propose(['bucket' => 'usable', 'counted_pieces' => 10]);
        $entries = DB::table('stock_entries')->count();
        $revision = $this->batch->stock_revision;
        $this->review($c);
        $this->assertDatabaseCount('stock_entries', $entries);
        $this->assertSame($revision, $this->batch->fresh()->stock_revision);
        $this->assertDatabaseHas('inventory_counts', ['id' => $c['id'], 'status' => 'approved']);
    }

    public function test_snapshot_is_invalidated_by_existing_credit_and_dispatch_paths(): void
    {
        $this->process();
        $c = $this->propose();
        $before = $this->batch->fresh()->stock_revision;
        DB::transaction(fn () => app(StockLedger::class)->credit($this->org->id, $this->store->id, ['id' => $this->batch->product_id, 'sku' => 'AYAM10', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 10], 'LOT10', '2030-01-01', 1, $this->lead->id, 'shipment_receipt', (string) Str::ulid(), 'usable'));
        $this->assertSame($before + 1, $this->batch->fresh()->stock_revision);
        $this->act($this->lead)->postJson('/api/v1/inventory/counts/'.$c['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Diperiksa'])->assertConflict();
        $before = $this->batch->fresh()->stock_revision;
        DB::transaction(fn () => app(StockLedger::class)->dispatch($this->batch->id, $this->store->id, $this->batch->product_id, 10, 1, $this->lead->id, (string) Str::ulid()));
        $this->assertSame($before + 1, $this->batch->fresh()->stock_revision);
    }

    public function test_two_counts_from_same_snapshot_cannot_both_adjust_inventory(): void
    {
        $this->process();
        $first = $this->propose();
        $second = $this->propose(['counted_pieces' => 5]);
        $this->review($first);
        $this->act($this->lead)->postJson('/api/v1/inventory/counts/'.$second['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Snapshot sudah dipakai'])->assertConflict();
        $this->assertSame(7, $this->batch->fresh()->opened_pieces);
        $this->assertDatabaseHas('inventory_counts', ['id' => $second['id'], 'status' => 'pending']);
    }

    public function test_center_owner_can_review_assigned_warehouse_count_but_not_partner_count(): void
    {
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $store = Store::create(['organization_id' => $center->id, 'name' => 'Gudang']);
        $owner = $this->user($center, 'owner');
        $warehouse = $this->user($center, 'warehouse', [$store]);
        $product = Product::create(['organization_id' => $center->id, 'sku' => 'PUSAT10', 'name' => 'Pusat', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 1, 'retail_price' => 1000, 'wholesale_price' => 10000]);
        $batch = DB::transaction(fn () => app(StockLedger::class)->credit($center->id, $store->id, $product->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'PUSAT', '2030-01-01', 1, $owner->id, 'warehouse_intake', (string) Str::ulid(), 'usable', 'Penerimaan gudang'));
        $count = $this->act($warehouse)->postJson('/api/v1/stores/'.$store->id.'/inventory/counts', ['operation_id' => (string) Str::uuid(), 'batch_id' => $batch['id'], 'expected_revision' => $batch['stock_revision'], 'purpose' => 'stocktake', 'bucket' => 'usable', 'counted_pieces' => 0, 'reason' => 'Hasil fisik gudang'])->assertCreated()->json('data');
        $this->review($count, 'approve', $owner);
        $this->assertDatabaseHas('inventory_batches', ['id' => $batch['id'], 'usable_pieces' => 0]);
        $this->process();
        $partnerCount = $this->propose();
        $this->act($owner)->postJson('/api/v1/inventory/counts/'.$partnerCount['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Pusat tidak otomatis berhak'])->assertForbidden();
    }

    public function test_used_stock_control_schema_cannot_be_rolled_back_and_erase_history(): void
    {
        $this->process();
        $migration = require database_path('migrations/2026_10_28_000003_add_stock_processing_and_counts.php');
        try {
            $migration->down();
            $this->fail('Used stock schema must reject destructive rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback ditolak', $e->getMessage());
        }
        $this->assertDatabaseCount('inventory_processes', 1);
        $this->assertSame(10, $this->batch->fresh()->opened_pieces);
    }

    public function test_native_control_context_is_scoped_and_reports_review_permission_without_prices(): void
    {
        $url = '/api/v1/stores/'.$this->store->id.'/inventory/control-context';
        $this->act($this->manager)->getJson($url)->assertOk()->assertExactJson(['data' => ['store_id' => $this->store->id, 'store_name' => 'A1', 'active' => true, 'can_process' => true, 'can_propose' => true, 'can_review' => false]]);
        $this->act($this->lead)->getJson($url)->assertOk()->assertJsonPath('data.can_review', true);
        $this->store->update(['active' => false]);
        $this->act($this->lead)->getJson($url)->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.can_process', false)->assertJsonPath('data.can_propose', false)->assertJsonPath('data.can_review', false);
    }

    public function test_native_context_denies_cashier_cross_tenant_unassigned_and_onboarding_override(): void
    {
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $url = '/api/v1/stores/'.$this->store->id.'/inventory/control-context';
        $this->act($this->cashier)->getJson($url)->assertOk()->assertJsonPath('data.can_process', true)->assertJsonPath('data.can_propose', false)->assertJsonPath('data.can_review', false);
        foreach ([$this->otherLead, $this->user($this->org, 'warehouse'), $this->user($center, 'owner')] as $actor) {
            $this->act($actor)->getJson($url)->assertForbidden();
        }
    }

    public function test_ready_lots_track_separate_cook_deadlines_and_quarantine_release_requires_independent_review(): void
    {
        // Enable 60-minute ready life SOP.
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->org->id.'/food-safety', [
            'operation_id' => (string) Str::uuid(),
            'version' => 0,
            'enabled' => true,
            'block_expired' => true,
            'fefo' => 'off',
            'ready_life_minutes' => 60,
            'reference' => 'SOP-READY-01',
        ])->assertOk();

        // Open 1 pack (10 opened) and cook 4 pieces at T0.
        $this->process(['kind' => 'open', 'quantity_packs' => 1]);
        $this->process(['kind' => 'cook', 'input_pieces' => 4, 'output_pieces' => 4], $this->cashier);

        // Expire the first ready lot in DB, then cook 6 fresh pieces as cashier.
        $firstLot = DB::table('inventory_ready_lots')->where('inventory_batch_id', $this->batch->id)->first();
        DB::table('inventory_ready_lots')->where('id', $firstLot->id)->update(['ready_expires_at' => now()->subMinutes(5)]);
        $this->process(['kind' => 'cook', 'input_pieces' => 6, 'output_pieces' => 6], $this->cashier);

        // Batch remains eligible for ready sale because the second lot (6 pieces) is still fresh.
        $this->assertTrue(app(\App\Modules\Inventory\Application\FoodSafety::class)->eligible($this->batch->fresh(), 'ready'));

        // Propose and independently approve quarantine release of 10 pieces (1 pack) to usable.
        DB::transaction(fn () => app(StockLedger::class)->apply($this->batch->fresh(), $this->lead->id, ['quarantined' => 10], 'shipment_receipt', (string) Str::ulid(), 'Damaged'));
        $rev = $this->batch->fresh()->stock_revision;
        $release = $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/quarantine-releases', [
            'operation_id' => (string) Str::uuid(),
            'batch_id' => $this->batch->id,
            'expected_revision' => $rev,
            'target_bucket' => 'usable',
            'quantity_pieces' => 10,
            'reason' => 'Kemasan luar penyok ringan, isi vakum utuh',
        ])->assertCreated()->json('data');

        // Proposer cannot self-approve; partner_lead approves.
        $this->act($this->manager)->postJson('/api/v1/inventory/quarantine-releases/'.$release['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Self'])->assertForbidden();
        $this->act($this->lead)->postJson('/api/v1/inventory/quarantine-releases/'.$release['id'].'/approve', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'review_note' => 'Terverifikasi layak'])->assertOk()->assertJsonPath('data.status', 'approved');
        $fresh = $this->batch->fresh();
        $this->assertSame(0, $fresh->quarantined_pieces);
        $this->assertSame(10, $fresh->usable_pieces);
    }
}
