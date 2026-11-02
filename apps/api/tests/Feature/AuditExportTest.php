<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $type = 'partner'): Organization
    {
        return Organization::create(['name' => 'Organization', 'type' => $type]);
    }

    private function actor(Organization $org, string $role): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['user_id' => $u->id, 'organization_id' => $org->id, 'role' => $role]);

        return $u;
    }

    private function row(?Organization $o, string $action = 'product.updated', string $time = '2026-10-29 17:00:00', ?int $actor = null): array
    {
        return ['id' => (string) Str::ulid(), 'organization_id' => $o?->id, 'actor_id' => $actor, 'action' => $action, 'subject_id' => 'P1', 'before' => $action === 'product.updated' ? '{"sku":"HISTORICAL8","wholesale_price":18000}' : '{"active":false}', 'after' => null, 'created_at' => $time];
    }

    private function url(Organization $o, array $filters = []): string
    {
        return "/api/v1/organizations/$o->id/audit/export?".http_build_query([...['from' => '2026-10-30T00:00:00+07:00', 'to' => '2026-10-31T00:00:00+07:00'], ...$filters]);
    }

    public function test_scope_roles_and_central_onboarding_are_shared_with_reader(): void
    {
        $o = $this->org();
        $other = $this->org();
        $center = $this->org('center');
        DB::table('audit_entries')->insert([$this->row($o), $this->row($o, 'user.reactivated'), $this->row($other), $this->row(null, 'user.password_changed')]);
        $this->getJson($this->url($o))->assertUnauthorized();
        foreach (['cashier', 'store_manager', 'warehouse'] as $role) {
            Sanctum::actingAs($this->actor($o, $role));
            $this->getJson($this->url($o))->assertForbidden();
        }
        $lead = $this->actor($o, 'partner_lead');
        Sanctum::actingAs($lead);
        $this->getJson($this->url($other))->assertForbidden();
        $this->getJson($this->url($o))->assertOk()->assertJsonPath('visibility', 'organization')->assertJsonCount(2, 'data')->assertJsonPath('row_count', 2)->assertHeader('Cache-Control', 'no-store, private');
        $owner = $this->actor($center, 'owner');
        Sanctum::actingAs($owner);
        $this->getJson($this->url($o))->assertOk()->assertJsonPath('visibility', 'onboarding')->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'user.reactivated')->assertDontSee('wholesale_price');
        $this->getJson($this->url($o, ['action' => 'product.updated']))->assertJsonCount(0, 'data');
        $o->update(['active' => false]);
        Sanctum::actingAs($lead);
        $this->getJson($this->url($o))->assertForbidden();
        Sanctum::actingAs($owner);
        $this->getJson($this->url($o))->assertJsonPath('visibility', 'onboarding');
    }

    public function test_exact_filters_wib_boundaries_all_pages_and_no_writes_or_master_join(): void
    {
        $o = $this->org();
        $lead = $this->actor($o, 'partner_lead');
        Sanctum::actingAs($lead);
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = $this->row($o, actor: $lead->id);
        }
        DB::table('audit_entries')->insert([...$rows, $this->row($o, time: '2026-10-29 16:59:59', actor: $lead->id), $this->row($o, time: '2026-10-30 17:00:00', actor: $lead->id), $this->row($o, 'store.updated', actor: $lead->id), $this->row($o, actor: null)]);
        $lead->update(['name' => 'Changed now']);
        $before = DB::table('audit_entries')->orderBy('id')->get()->toJson();
        $result = $this->getJson($this->url($o, ['action' => 'product.updated', 'subject_id' => 'P1', 'actor_id' => $lead->id, 'page' => 999, 'organization_id' => 'OTHER']))->assertOk()->assertJsonCount(51, 'data')->json();
        $ids = array_column($rows, 'id');
        rsort($ids);
        $this->assertSame($ids, array_column($result['data'], 'id'));
        $this->assertSame('2026-10-29T17:00:00+00:00', $result['data'][0]['occurred_at']);
        $this->assertSame('{"sku":"HISTORICAL8","wholesale_price":18000}', $result['data'][0]['before']);
        $this->assertSame($before, DB::table('audit_entries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('operation_receipts', 0);
        $this->assertDatabaseCount('stock_entries', 0);
    }

    public function test_ten_thousand_rows_allowed_but_next_row_rejects_without_partial_export(): void
    {
        $o = $this->org();
        Sanctum::actingAs($this->actor($o, 'partner_lead'));
        for ($batch = 0; $batch < 100; $batch++) {
            $rows = [];
            for ($i = 0; $i < 100; $i++) {
                $rows[] = $this->row($o);
            }
            DB::table('audit_entries')->insert($rows);
        }
        $this->getJson($this->url($o))->assertOk()->assertJsonPath('row_count', 10000)->assertJsonCount(10000, 'data');
        DB::table('audit_entries')->insert($this->row($o));
        $this->getJson($this->url($o))->assertUnprocessable()->assertJsonValidationErrors('export')->assertJsonMissingPath('data');
        $this->getJson($this->url($o, ['action' => 'no.match']))->assertOk()->assertJsonPath('row_count', 0);
        $this->assertDatabaseCount('audit_entries', 10001);
    }

    public function test_oversized_snapshot_is_rejected_before_streaming_without_writes(): void
    {
        $o = $this->org();
        Sanctum::actingAs($this->actor($o, 'partner_lead'));
        DB::table('audit_entries')->insert([...$this->row($o), 'before' => str_repeat('x', 8 * 1024 * 1024)]);
        $this->getJson($this->url($o))->assertUnprocessable()->assertJsonValidationErrors('export')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_period_required_and_existing_filter_validation_preserved(): void
    {
        $o = $this->org();
        Sanctum::actingAs($this->actor($o, 'partner_lead'));
        $this->getJson("/api/v1/organizations/$o->id/audit/export")->assertUnprocessable();
        foreach ([['from' => '2026-10-30'], ['to' => '2026-10-31'], ['actor_id' => 0], ['action' => str_repeat('a', 81)], ['subject_id' => ['array']], ['to' => '2026-10-29T00:00:00Z'], ['from' => '2026-09-01T00:00:00Z']] as $filters) {
            $this->getJson($this->url($o, $filters))->assertUnprocessable();
        }
        $this->assertDatabaseCount('audit_entries', 0);
    }
}
