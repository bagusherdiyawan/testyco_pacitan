<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $center;

    private Organization $partner;

    private Store $store;

    private User $owner;

    private User $lead;

    private User $cashier;

    private User $outsider;

    private PartnershipContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->center = Organization::create(['name' => 'Center', 'type' => 'center']);
        $this->partner = Organization::create(['name' => 'Partner', 'type' => 'partner']);
        $other = Organization::create(['name' => 'Other', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->partner->id, 'name' => 'Store']);
        $this->owner = $this->user($this->center, 'owner');
        $this->lead = $this->user($this->partner, 'partner_lead');
        $this->cashier = $this->user($this->partner, 'cashier');
        $this->outsider = $this->user($other, 'partner_lead');
        $this->contract = PartnershipContract::create(['organization_id' => $this->center->id, 'partner_id' => $this->partner->id, 'title' => 'Facilities', 'mode' => 'buyout', 'starts_on' => '2026-01-01', 'ends_on' => '2030-01-01', 'terms' => 'Loan terms', 'brand_terms' => 'Brand', 'data_scope' => 'Facilities', 'facilities' => 'Freezer', 'status' => 'accepted', 'version' => 3, 'created_by' => $this->owner->id]);
    }

    private function user(Organization $o, string $role): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $o->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function act(User $u): self
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function key(int $version): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => $version];
    }

    private function asset(): array
    {
        return $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/assets', ['operation_id' => (string) Str::uuid(), 'number' => 'FREEZER-1', 'kind' => 'freezer', 'brand' => 'Brand', 'serial_number' => 'SERIAL-1', 'condition_note' => 'Inspected', 'ownership_reference' => 'Private purchase evidence'])->assertCreated()->json('data');
    }

    private function placement(array $a): array
    {
        return $this->act($this->owner)->postJson('/api/v1/assets/'.$a['id'].'/placements', [...$this->key($a['version']), 'store_id' => $this->store->id, 'contract_id' => $this->contract->id, 'responsible_name' => 'Partner lead', 'handed_over_on' => '2026-11-04', 'condition_note' => 'Checked', 'agreement_reference' => 'Signed loan', 'evidence_reference' => 'Handover photo reference'])->assertCreated()->json('data');
    }

    public function test_unique_placement_explicit_handover_and_return_retain_history_and_replay(): void
    {
        $a = $this->asset();
        $p = $this->placement($a);
        $path = '/api/v1/assets/'.$a['id'];
        $this->assertSame('reserved', $p['asset']['status']);
        $this->postJson($path.'/placements', [...$this->key(2), 'store_id' => $this->store->id, 'responsible_name' => 'X', 'handed_over_on' => '2026-11-04', 'condition_note' => 'X', 'agreement_reference' => 'X', 'evidence_reference' => 'X'])->assertConflict();
        $url = $path.'/placements/'.$p['placement']['id'];
        $v = [...$this->key(2), 'consent' => true, 'reason' => 'Physically received'];
        $this->act($this->lead)->postJson($url.'/accept', $v)->assertOk()->assertJsonPath('data.asset.status', 'in_use');
        $this->postJson($url.'/accept', $v)->assertOk();
        $this->postJson($url.'/request_return', [...$this->key(3), 'reason' => 'Cooperation ended', 'returned_on' => '2026-11-05', 'return_condition' => 'Checked return', 'return_reference' => 'Return receipt'])->assertOk()->assertJsonPath('data.asset.store_id', $this->store->id);
        $this->act($this->owner)->postJson($url.'/confirm_return', [...$this->key(4), 'consent' => true, 'reason' => 'Actual receipt inspected', 'disposition' => 'available', 'inspection_note' => 'Inspected available'])->assertOk()->assertJsonPath('data.asset.store_id', null);
        $this->assertDatabaseHas('asset_placements', ['id' => $p['placement']['id'], 'status' => 'returned', 'active_asset_id' => null]);
        $this->getJson($path)->assertOk()->assertJsonPath('placements.total', 1);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_incident_holds_availability_cost_is_evidence_not_automatic_debt(): void
    {
        $a = $this->asset();
        $path = '/api/v1/assets/'.$a['id'];
        $p = $this->placement($a);
        $this->act($this->lead)->postJson($path.'/placements/'.$p['placement']['id'].'/accept', [...$this->key(2), 'consent' => true, 'reason' => 'Received'])->assertOk();
        $i = $this->postJson($path.'/incidents', [...$this->key(3), 'description' => 'Freezer not cooling', 'reported_on' => '2026-11-05', 'evidence_reference' => 'Incident inspection'])->assertCreated()->json('data');
        $this->assertSame('maintenance', $i['asset']['status']);
        $this->postJson($path.'/incidents/'.$i['incident']['id'].'/resolve', [...$this->key(4), 'resolution' => 'Fixed', 'cost_basis_reference' => 'Agreement', 'owner_cost' => 100000, 'recipient_cost' => 0, 'completed_on' => '2026-11-06'])->assertForbidden();
        $this->act($this->owner)->postJson($path.'/incidents/'.$i['incident']['id'].'/resolve', [...$this->key(4), 'resolution' => 'Repaired compressor', 'cost_basis_reference' => 'Actual invoice and loan contract', 'owner_cost' => 100000, 'recipient_cost' => 0, 'completed_on' => '2026-11-06'])->assertOk()->assertJsonPath('data.asset.status', 'in_use');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_roles_foreign_assets_private_ownership_stale_and_used_rollback(): void
    {
        $a = $this->asset();
        $path = '/api/v1/assets/'.$a['id'];
        foreach ([$this->lead, $this->cashier, $this->outsider] as $u) {
            $this->act($u)->getJson($path)->assertForbidden();
        }$p = $this->placement($a);
        $this->act($this->lead)->getJson($path)->assertOk()->assertJsonMissingPath('asset.ownership_reference');
        $this->getJson('/api/v1/organizations/'.$this->center->id.'/assets')->assertForbidden();
        $this->getJson('/api/v1/organizations/'.$this->partner->id.'/assets')->assertOk()->assertJsonMissingPath('data.0.ownership_reference');
        $this->postJson($path.'/placements/'.$p['placement']['id'].'/accept', [...$this->key(1), 'consent' => true, 'reason' => 'Old review'])->assertConflict();
        $this->expectException(\RuntimeException::class);
        $m = require database_path('migrations/2026_11_04_000017_create_asset_lifecycle.php');
        $m->down();
    }
}
