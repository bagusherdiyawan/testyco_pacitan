<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private User $owner;

    private User $reviewer;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9));
        $this->org = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'Produksi']);
        $this->owner = $this->user('owner');
        $this->reviewer = $this->user('admin');
        $this->operator = $this->user('warehouse');
        DB::table('store_user')->insert(['user_id' => $this->operator->id, 'store_id' => $this->store->id]);
        Sanctum::actingAs($this->owner);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $this->org->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function url(string $action): string
    {
        return '/api/v1/organizations/'.$this->org->id.'/erp/'.$action;
    }

    private function command(string $a, array $v): array
    {
        return $this->postJson($this->url($a), ['operation_id' => (string) Str::uuid(), ...$v])->assertCreated()->json('data');
    }

    private function account(string $code, string $kind, string $normal): array
    {
        return $this->command('account', ['code' => $code, 'name' => $code, 'kind' => $kind, 'normal_balance' => $normal]);
    }

    private function period(): array
    {
        return $this->command('accounting_period', ['name' => 'November2026', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'note' => 'Periode dipilih pemilik']);
    }

    private function journal(array $p, array $d, array $c, string $number = 'J-1'): array
    {
        return $this->command('journal', ['period_id' => $p['id'], 'number' => $number, 'posted_on' => '2026-11-09', 'debits' => $d, 'credits' => $c, 'evidence' => 'Bukti transaksi bukan posting ERP otomatis', 'note' => 'Saldo awal eksplisit']);
    }

    public function test_balanced_journal_requires_independent_review_and_replay_does_not_double_trial_balance(): void
    {
        $cash = $this->account('CASH', 'asset', 'debit');
        $equity = $this->account('CAPITAL', 'equity', 'credit');
        $p = $this->period();
        $j = $this->journal($p, [$cash['id'] => 100000], [$equity['id'] => 100000]);
        $review = ['operation_id' => (string) Str::uuid(), 'id' => $j['id'], 'version' => 1, 'decision' => 'post', 'note' => 'Bukti sesuai'];
        $this->postJson($this->url('journal_decide'), $review)->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('journal_decide'), $review)->assertCreated()->assertJsonPath('data.status', 'posted');
        $this->postJson($this->url('journal_decide'), $review)->assertCreated();
        $this->getJson($this->url('context').'?section=trial_balance&period_id='.$p['id'])->assertOk()->assertJsonCount(3, 'data')->assertSee('debit Rp100000')->assertSee('aset Rp100000');
        $this->assertDatabaseCount('accounting_journals', 1);
    }

    public function test_unbalanced_and_foreign_accounts_or_periods_cannot_create_journals(): void
    {
        $a = $this->account('A', 'asset', 'debit');
        $b = $this->account('B', 'equity', 'credit');
        $p = $this->period();
        $v = ['operation_id' => (string) Str::uuid(), 'period_id' => $p['id'], 'number' => 'BAD', 'posted_on' => '2026-11-09', 'debits' => [$a['id'] => 10], 'credits' => [$b['id'] => 9], 'evidence' => 'Evidence', 'note' => 'Review'];
        $this->postJson($this->url('journal'), $v)->assertUnprocessable();
        $v['credits'] = [(string) Str::ulid() => 10];
        $this->postJson($this->url('journal'), $v)->assertNotFound();
        $this->assertDatabaseCount('accounting_journals', 0);
    }

    public function test_closed_period_blocks_new_postings_and_draft_close_requires_resolution(): void
    {
        $a = $this->account('A', 'asset', 'debit');
        $b = $this->account('B', 'equity', 'credit');
        $p = $this->period();
        $j = $this->journal($p, [$a['id'] => 10], [$b['id'] => 10]);
        $this->postJson($this->url('period_status'), ['operation_id' => (string) Str::uuid(), 'id' => $p['id'], 'version' => 2, 'decision' => 'close', 'note' => 'Tutup'])->assertStatus(409);
        Sanctum::actingAs($this->reviewer);
        $this->command('journal_decide', ['id' => $j['id'], 'version' => 1, 'decision' => 'reject', 'note' => 'Bukti tidak cukup']);
        $this->command('period_status', ['id' => $p['id'], 'version' => 3, 'decision' => 'close', 'note' => 'Draft selesai']);
        $this->postJson($this->url('journal'), ['operation_id' => (string) Str::uuid(), 'period_id' => $p['id'], 'number' => 'CLOSED', 'posted_on' => '2026-11-09', 'debits' => [$a['id'] => 10], 'credits' => [$b['id'] => 10], 'evidence' => 'Evidence', 'note' => 'No'])->assertStatus(409);
        $this->command('period_status', ['id' => $p['id'], 'version' => 4, 'decision' => 'reopen', 'note' => 'Koreksi disetujui']);
    }

    public function test_reversal_preserves_original_and_posts_exact_opposite_only_once(): void
    {
        $a = $this->account('A', 'asset', 'debit');
        $b = $this->account('B', 'equity', 'credit');
        $p = $this->period();
        $j = $this->journal($p, [$a['id'] => 123], [$b['id'] => 123]);
        Sanctum::actingAs($this->reviewer);
        $this->command('journal_decide', ['id' => $j['id'], 'version' => 1, 'decision' => 'post', 'note' => 'Reviewed']);
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $j['id'], 'version' => 2, 'period_id' => $p['id'], 'number' => 'REV-1', 'posted_on' => '2026-11-09', 'evidence' => 'Bukti pembalikan', 'note' => 'Koreksi'];
        $this->postJson($this->url('journal_reverse'), $v)->assertCreated();
        $this->postJson($this->url('journal_reverse'), $v)->assertCreated();
        $this->assertDatabaseCount('accounting_journals', 2);
        $original = DB::table('accounting_journals')->where('id', $j['id'])->first();
        $reverse = DB::table('accounting_journals')->where('id', $original->reversed_by)->first();
        $this->assertSame($j['id'], $reverse->reversal_of);
        $this->assertSame(123, json_decode($reverse->lines, true)[0]['debit'] + json_decode($reverse->lines, true)[0]['credit']);
        $this->getJson($this->url('context').'?section=trial_balance&period_id='.$p['id'])->assertOk()->assertSee('aset Rp0');
    }

    public function test_operator_grant_is_store_scoped_revocable_and_does_not_allow_qc_or_finance(): void
    {
        $assignment = $this->operator->id.':'.$this->store->id;
        Sanctum::actingAs($this->operator);
        $q = '?section=productions&store_id='.$this->store->id;
        $this->getJson($this->url('context').$q)->assertForbidden();
        Sanctum::actingAs($this->owner);
        $grant = $this->command('operator_grant', ['assignment' => $assignment, 'grant_versions' => [], 'enabled' => true, 'note' => 'Produksi dan biaya gudang ini']);
        Sanctum::actingAs($this->operator);
        $this->getJson($this->url('context').$q)->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('can_operate', true)->assertJsonPath('forms.0.operation', 'production');
        foreach (['purchases', 'accounts', 'journals', 'operators', 'trial_balance'] as $section) {
            $this->getJson($this->url('context').'?section='.$section.'&store_id='.$this->store->id)->assertForbidden();
        }
        $other = Store::create(['organization_id' => $this->org->id, 'name' => 'Other']);
        $this->getJson($this->url('context').'?section=productions&store_id='.$other->id)->assertForbidden();
        $this->postJson($this->url('production_quality'), ['operation_id' => (string) Str::uuid(), 'id' => (string) Str::ulid(), 'version' => 1, 'stock_revision' => 1, 'decision' => 'release', 'note' => 'Not authorized'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->command('operator_grant', ['assignment' => $assignment, 'grant_versions' => [$assignment => $grant['version']], 'enabled' => false, 'note' => 'Cabut izin']);
        Sanctum::actingAs($this->operator);
        $this->getJson($this->url('context').$q)->assertForbidden();
    }

    public function test_grant_does_not_create_store_assignment_and_accounting_periods_cannot_overlap(): void
    {
        DB::table('store_user')->where('user_id', $this->operator->id)->delete();
        $this->postJson($this->url('operator_grant'), ['operation_id' => (string) Str::uuid(), 'assignment' => $this->operator->id.':'.$this->store->id, 'grant_versions' => [], 'enabled' => true, 'note' => 'No assignment'])->assertForbidden();
        $this->assertDatabaseCount('erp_operator_grants', 0);
        $this->period();
        $this->postJson($this->url('accounting_period'), ['operation_id' => (string) Str::uuid(), 'name' => 'Overlap', 'starts_on' => '2026-11-30', 'ends_on' => '2026-12-31', 'note' => 'No'])->assertStatus(409);
    }

    public function test_granted_operator_executes_production_but_only_owner_can_release_output(): void
    {
        $m = $this->command('material', ['code' => 'FLOUR', 'name' => 'Terigu', 'unit' => 'kg', 'minimum' => '0', 'expiry_required' => true, 'fefo_required' => false]);
        $lot = $this->command('receive', ['source' => 'opening', 'store_id' => $this->store->id, 'material_id' => $m['id'], 'quantity' => '1', 'opening_value' => 10000, 'batch_code' => 'OP-RAW', 'received_on' => '2026-11-09', 'expires_on' => '2026-12-09', 'evidence' => 'Saldo fisik', 'note' => 'Receipt']);
        $this->command('material_quality', ['id' => $lot['id'], 'version' => 1, 'decision' => 'release', 'quantity' => '1', 'note' => 'Owner inspected']);
        $product = Product::create(['organization_id' => $this->org->id, 'sku' => 'DIMSUM', 'name' => 'Dimsum', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 1, 'wholesale_price' => 20000, 'retail_price' => 3000]);
        $recipe = $this->command('recipe', ['product_id' => $product->id, 'title' => 'Resep operator', 'output_packs' => 1, 'ingredients' => [$m['id'] => '1'], 'instructions' => 'Measured']);
        $this->command('operator_grant', ['assignment' => $this->operator->id.':'.$this->store->id, 'grant_versions' => [], 'enabled' => true, 'note' => 'Production with process cost entry']);
        Sanctum::actingAs($this->operator);
        $p = $this->command('production', ['store_id' => $this->store->id, 'recipe_id' => $recipe['id'], 'recipe_versions' => [$recipe['id'] => 1], 'number' => 'OP-PRD', 'runs' => 1, 'note' => 'Operator plan']);
        $done = $this->command('complete', ['id' => $p['id'], 'version' => 1, 'allocations' => [$lot['id'] => '1'], 'allocation_revisions' => [$lot['id'] => 2], 'batch_code' => 'OP-OUT', 'produced_on' => '2026-11-09', 'expires_on' => '2026-12-01', 'good_packs' => 1, 'failed_pieces' => 0, 'labor_cost' => 0, 'overhead_cost' => 0, 'cost_allocation' => 'all_to_good_output', 'cost_evidence' => 'Explicit zero extra cost', 'note' => 'Actual output']);
        $this->getJson($this->url('context').'?section=productions&store_id='.$this->store->id)->assertOk()->assertJsonPath('data.0.forms', []);
        $quality = ['id' => $p['id'], 'version' => 2, 'stock_revision' => 1, 'decision' => 'release', 'note' => 'Inspection'];
        $this->postJson($this->url('production_quality'), ['operation_id' => (string) Str::uuid(), ...$quality])->assertForbidden();
        $this->assertDatabaseHas('inventory_batches', ['id' => $done['output_batch_id'], 'usable_pieces' => 0, 'quarantined_pieces' => 10]);
        Sanctum::actingAs($this->owner);
        $this->command('production_quality', $quality);
        $this->assertDatabaseHas('inventory_batches', ['id' => $done['output_batch_id'], 'usable_pieces' => 10, 'quarantined_pieces' => 0]);
    }

    public function test_accounting_has_no_central_onboarding_override_or_cross_organization_references(): void
    {
        $other = Organization::create(['name' => 'Another center', 'type' => 'center']);
        $foreign = (string) Str::ulid();
        DB::table('accounting_accounts')->insert(['id' => $foreign, 'organization_id' => $other->id, 'code' => 'PRIVATE', 'name' => 'Private', 'kind' => 'asset', 'normal_balance' => 'debit', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/v1/organizations/'.$other->id.'/erp/context?section=accounts')->assertForbidden();
        $a = $this->account('OWN', 'equity', 'credit');
        $p = $this->period();
        $this->postJson($this->url('journal'), ['operation_id' => (string) Str::uuid(), 'period_id' => $p['id'], 'number' => 'FOREIGN', 'posted_on' => '2026-11-09', 'debits' => [$foreign => 10], 'credits' => [$a['id'] => 10], 'evidence' => 'Invalid source', 'note' => 'No'])->assertNotFound();
        $this->getJson($this->url('context').'?section=journals&period_id='.(string) Str::ulid())->assertNotFound();
        $this->assertDatabaseCount('accounting_journals', 0);
    }

    public function test_grant_can_be_revoked_after_target_deactivation_or_assignment_removal(): void
    {
        $assignment = $this->operator->id.':'.$this->store->id;
        $g = $this->command('operator_grant', ['assignment' => $assignment, 'grant_versions' => [], 'enabled' => true, 'note' => 'Grant']);
        DB::table('store_user')->where('user_id', $this->operator->id)->delete();
        $this->operator->update(['active' => false]);
        $this->store->update(['active' => false]);
        $this->getJson($this->url('context').'?section=operators')->assertOk()->assertJsonPath('data.0.forms.0.operation', 'operator_grant');
        $this->command('operator_grant', ['assignment' => $assignment, 'grant_versions' => [$assignment => 1], 'enabled' => false, 'note' => 'Revoke stale access']);
        $this->assertDatabaseHas('erp_operator_grants', ['id' => $g['id'], 'enabled' => false, 'version' => 2]);
    }

    public function test_reversal_in_later_period_preserves_closed_period_and_carries_opening_balance(): void
    {
        $a = $this->account('A', 'asset', 'debit');
        $b = $this->account('B', 'equity', 'credit');
        $p = $this->command('accounting_period', ['name' => 'First', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-08', 'note' => 'Explicit period']);
        $j = $this->command('journal', ['period_id' => $p['id'], 'number' => 'OLD', 'posted_on' => '2026-11-08', 'debits' => [$a['id'] => 100], 'credits' => [$b['id'] => 100], 'evidence' => 'Opening proof', 'note' => 'Initial']);
        Sanctum::actingAs($this->reviewer);
        $this->command('journal_decide', ['id' => $j['id'], 'version' => 1, 'decision' => 'post', 'note' => 'Independent']);
        $this->command('period_status', ['id' => $p['id'], 'version' => 3, 'decision' => 'close', 'note' => 'Reviewed and closed']);
        $next = $this->command('accounting_period', ['name' => 'Next', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-30', 'note' => 'Explicit period']);
        $this->command('journal_reverse', ['id' => $j['id'], 'version' => 2, 'period_id' => $next['id'], 'number' => 'REV-NEXT', 'posted_on' => '2026-11-09', 'evidence' => 'Correction proof', 'note' => 'Correct in open period']);
        $this->getJson($this->url('context').'?section=trial_balance&period_id='.$p['id'])->assertOk()->assertSee('aset Rp100');
        $this->getJson($this->url('context').'?section=trial_balance&period_id='.$next['id'])->assertOk()->assertSee('aset Rp0')->assertSee('awal bertanda Rp100');
    }

    public function test_trial_balance_rejects_oversized_source_before_returning_partial_totals(): void
    {
        $p = $this->period();
        DB::table('accounting_journals')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->org->id, 'period_id' => $p['id'], 'number' => 'LARGE', 'posted_on' => '2026-11-09', 'status' => 'posted', 'version' => 1, 'lines' => json_encode([['text' => str_repeat('x', 8 * 1024 * 1024)]]), 'total_amount' => 1, 'evidence' => 'Large historical fixture', 'note' => 'Bounded read', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($this->url('context').'?section=trial_balance&period_id='.$p['id'])->assertUnprocessable()->assertJsonMissingPath('data');
    }
}
