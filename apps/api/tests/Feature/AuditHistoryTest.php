<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function actor(Organization $org, string $role): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function entry(Organization $org, ?User $actor, string $action = 'product.updated', string $subject = 'P1', string $time = '2026-10-29 17:00:00'): string
    {
        $id = (string) Str::ulid();
        DB::table('audit_entries')->insert(['id' => $id, 'organization_id' => $org->id, 'actor_id' => $actor?->id, 'action' => $action, 'subject_id' => $subject, 'before' => json_encode(['name' => 'Historis', 'wholesale_price' => 18000]), 'after' => json_encode(['name' => 'Baru', 'wholesale_price' => 20000]), 'created_at' => $time]);

        return $id;
    }

    public function test_audit_filters_never_expand_organization_or_central_onboarding_visibility(): void
    {
        $org = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $other = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $lead = $this->actor($org, 'partner_lead');
        $owner = $this->actor($center, 'owner');
        $product = $this->entry($org, $lead);
        Audit::record($lead->id, $org->id, 'store.updated', 'S1', ['active' => true], ['active' => false]);
        $this->entry($other, $lead);
        Audit::record($lead->id, null, 'user.password_changed', (string) $lead->id, null, null);
        $path = '/api/v1/organizations/'.$org->id.'/audit';
        Sanctum::actingAs($lead);
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('visibility', 'organization')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/organizations/'.$other->id.'/audit')->assertForbidden();
        foreach (['cashier', 'store_manager', 'warehouse'] as $role) {
            $u = $this->actor($org, $role);
            $s = Store::create(['organization_id' => $org->id, 'name' => $role]);
            DB::table('store_user')->insert(['user_id' => $u->id, 'store_id' => $s->id]);
            Sanctum::actingAs($u);
            $this->getJson($path)->assertForbidden();
        }
        Sanctum::actingAs($owner);
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('visibility', 'onboarding')->assertDontSee('wholesale_price')->assertDontSee($product);
        $this->getJson($path.'?action=product.updated&subject_id=P1&actor_id='.$lead->id)->assertJsonCount(0, 'data');
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'partner_lead']);
        $this->getJson($path)->assertJsonPath('visibility', 'organization')->assertJsonCount(2, 'data');
        $org->update(['active' => false]);
        Sanctum::actingAs($lead);
        $this->getJson($path)->assertForbidden();
    }

    public function test_combined_filters_utc_boundaries_and_snapshot_reads_do_not_write_audit(): void
    {
        $org = Organization::create(['name' => 'A', 'type' => 'partner']);
        $lead = $this->actor($org, 'partner_lead');
        $this->entry($org, $lead, time: '2026-10-29 16:59:59');
        $first = $this->entry($org, $lead);
        $last = $this->entry($org, $lead, time: '2026-10-30 16:59:59');
        $this->entry($org, $lead, time: '2026-10-30 17:00:00');
        $this->entry($org, null, 'store.updated', 'S1');
        $before = DB::table('audit_entries')->orderBy('id')->get()->toJson();
        $lead->update(['name' => 'Nama pelaku berubah']);
        $params = ['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-10-31T00:00:00+07:00', 'action' => 'product.updated', 'subject_id' => 'P1', 'actor_id' => $lead->id];
        Sanctum::actingAs($lead);
        $r = $this->getJson('/api/v1/organizations/'.$org->id.'/audit?'.http_build_query($params))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('organization.id', $org->id);
        $this->assertEqualsCanonicalizing([$first, $last], array_column($r->json('data'), 'id'));
        foreach ($r->json('data') as $row) {
            $this->assertSame(18000, json_decode($row['before'], true)['wholesale_price']);
            $this->assertStringEndsWith('+00:00', $row['occurred_at']);
            $this->assertSame($lead->id, $row['actor_id']);
        }
        $this->assertSame($before, DB::table('audit_entries')->orderBy('id')->get()->toJson());
        $this->getJson('/api/v1/organizations/'.$org->id.'/audit?action=store.updated')->assertJsonPath('data.0.actor_id', null);
    }

    public function test_legacy_all_history_paginates_fifty_plus_one_and_rejects_invalid_inputs(): void
    {
        $org = Organization::create(['name' => 'A', 'type' => 'partner']);
        $lead = $this->actor($org, 'partner_lead');
        for ($i = 0; $i < 51; $i++) {
            $this->entry($org, $lead);
        }
        Sanctum::actingAs($lead);
        $url = '/api/v1/organizations/'.$org->id.'/audit';
        $a = $this->getJson($url)->assertJsonCount(50, 'data')->assertJsonPath('last_page', 2)->json('data');
        $b = $this->getJson($url.'?page=2')->assertJsonCount(1, 'data')->json('data');
        $this->assertCount(51, array_unique(array_column([...$a, ...$b], 'id')));
        foreach ([['page' => 0], ['actor_id' => -1], ['actor_id' => 'abc'], ['action' => str_repeat('a', 81)], ['subject_id' => str_repeat('a', 81)], ['from' => '2026-10-01T00:00:00Z'], ['from' => '2026-10-01', 'to' => '2026-10-02'], ['from' => '2026-10-02T00:00:00Z', 'to' => '2026-10-01T00:00:00Z'], ['from' => '2026-10-01T00:00:00Z', 'to' => '2026-11-02T00:00:00Z']] as $params) {
            $this->getJson($url.'?'.http_build_query($params))->assertUnprocessable();
        }
    }
}
