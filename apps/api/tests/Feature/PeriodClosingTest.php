<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodClosingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $reviewer;

    private array $accounts;

    private array $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 10));
        $this->org = Organization::create(['name' => 'Center', 'type' => 'center']);
        $this->owner = $this->member('owner');
        $this->reviewer = $this->member('admin');
        Sanctum::actingAs($this->owner);
        foreach (['cash' => ['asset', 'debit'], 'capital' => ['equity', 'credit'], 'retained' => ['equity', 'credit'], 'sales' => ['revenue', 'credit'], 'expense' => ['expense', 'debit']] as $key => [$kind,$normal]) {
            $this->accounts[$key] = $this->command('account', ['code' => $key, 'name' => $key, 'kind' => $kind, 'normal_balance' => $normal])['id'];
        }
        $this->period = $this->command('accounting_period', ['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'note' => 'Explicit period']);
        $this->postJournal(['cash' => 1000], ['capital' => 1000]);
        $this->postJournal(['cash' => 300], ['sales' => 300]);
        $this->postJournal(['expense' => 80], ['cash' => 80]);
    }

    private function member(string $role): User
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

    private function postJournal(array $debits, array $credits): array
    {
        Sanctum::actingAs($this->owner);
        $map = fn ($v) => collect($v)->mapWithKeys(fn ($n, $key) => [$this->accounts[$key] => $n])->all();
        $j = $this->command('journal', ['period_id' => $this->period['id'], 'number' => (string) Str::ulid(), 'posted_on' => '2026-10-31', 'debits' => $map($debits), 'credits' => $map($credits), 'evidence' => 'Transaction evidence', 'note' => 'Recorded']);
        Sanctum::actingAs($this->reviewer);
        $this->command('journal_decide', ['id' => $j['id'], 'version' => 1, 'decision' => 'post', 'note' => 'Reviewed']);
        Sanctum::actingAs($this->owner);

        return $j;
    }

    private function proposal(): array
    {
        return $this->command('closing_propose', ['id' => $this->period['id'], 'version' => DB::table('accounting_periods')->where('id', $this->period['id'])->value('version'), 'retained_account_id' => $this->accounts['retained'], 'note' => 'Reconciled source and trial balance']);
    }

    private function decide(array $c, string $decision = 'approve'): array
    {
        return ['id' => $c['id'], 'version' => 1, 'decision' => $decision, 'note' => 'Independent closing review'];
    }

    public function test_close_is_independent_exact_once_and_statements_preserve_operating_profit(): void
    {
        $before = $this->getJson($this->url('context').'?section=income_statement&period_id='.$this->period['id'])->assertOk()->assertJsonPath('totals.profit', 220)->json();
        $c = $this->proposal();
        $this->assertSame(220, $c['profit']);
        $v = ['operation_id' => (string) Str::uuid(), ...$this->decide($c)];
        $this->postJson($this->url('closing_decide'), $v)->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $ack = $this->postJson($this->url('closing_decide'), $v)->assertCreated()->assertJsonPath('data.status', 'posted')->json('data');
        $this->postJson($this->url('closing_decide'), $v)->assertCreated()->assertJsonPath('data.journal_id', $ack['journal_id']);
        $this->assertDatabaseCount('accounting_journals', 4);
        $this->getJson($this->url('context').'?section=income_statement&period_id='.$this->period['id'])->assertOk()->assertJsonPath('totals.revenue', 300)->assertJsonPath('totals.expense', 80)->assertJsonPath('totals.profit', 220);
        $this->getJson($this->url('context').'?section=balance_sheet&period_id='.$this->period['id'])->assertOk()->assertJsonPath('totals.assets', 1220)->assertJsonPath('totals.equity', 1220)->assertJsonPath('totals.unclosed_profit', 0);
        $this->postJson($this->url('journal_reverse'), ['operation_id' => (string) Str::uuid(), 'id' => $ack['journal_id'], 'version' => 1, 'period_id' => $this->period['id'], 'number' => 'BYPASS', 'posted_on' => '2026-10-31', 'evidence' => 'No', 'note' => 'No'])->assertStatus(409);
        $p = DB::table('accounting_periods')->where('id', $this->period['id'])->first();
        $reopen = ['operation_id' => (string) Str::uuid(), 'id' => $p->id, 'version' => $p->version, 'decision' => 'reopen', 'note' => 'Approved source correction'];
        $this->postJson($this->url('period_status'), $reopen)->assertCreated();
        $this->postJson($this->url('period_status'), $reopen)->assertCreated();
        $this->assertDatabaseCount('accounting_journals', 5);
        $this->getJson($this->url('context').'?section=balance_sheet&period_id='.$p->id)->assertOk()->assertJsonPath('totals.equity', 1000)->assertJsonPath('totals.unclosed_profit', 220);
        $this->getJson($this->url('context').'?section=income_statement&period_id='.$p->id)->assertOk()->assertJsonPath('totals.profit', 220);
        Sanctum::actingAs($this->owner);
        $again = $this->proposal();
        Sanctum::actingAs($this->reviewer);
        $this->command('closing_decide', $this->decide($again));
        $this->assertDatabaseCount('accounting_closings', 2);
        $this->assertSame(220, $before['totals']['profit']);
    }

    public function test_changes_after_proposal_require_reject_and_new_snapshot(): void
    {
        $c = $this->proposal();
        $this->postJournal(['expense' => 30], ['cash' => 30]);
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('closing_decide'), ['operation_id' => (string) Str::uuid(), ...$this->decide($c)])->assertStatus(409);
        $this->command('closing_decide', $this->decide($c, 'reject'));
        Sanctum::actingAs($this->owner);
        $again = $this->proposal();
        $this->assertSame(190, $again['profit']);
        $this->assertNotSame($c['basis_hash'], $again['basis_hash']);
    }

    public function test_queues_prior_drafts_and_earlier_open_periods_prevent_closing(): void
    {
        $earlier = $this->command('accounting_period', ['name' => 'Earlier', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'note' => 'Earlier period']);
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $this->period['id'], 'version' => 7, 'retained_account_id' => $this->accounts['retained'], 'note' => 'Premature'];
        $this->postJson($this->url('closing_propose'), $v)->assertStatus(409);
        $this->command('period_status', ['id' => $earlier['id'], 'version' => 1, 'decision' => 'close', 'note' => 'No activity']);
        DB::table('accounting_events')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->org->id, 'policy_id' => (string) Str::ulid(), 'source_type' => 'test_pending', 'source_id' => 'old', 'event_on' => '2026-09-01', 'status' => 'review_required', 'version' => 1, 'actor_id' => $this->owner->id, 'terms' => '[]', 'evidence' => 'Missing source', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($this->url('closing_propose'), $v)->assertStatus(409);
        $this->getJson($this->url('context').'?section=income_statement&period_id='.$this->period['id'])->assertOk()->assertJsonPath('pending.source_events', 1)->assertSee('laporan sementara');
        $this->assertDatabaseCount('accounting_closings', 0);
    }

    public function test_invalid_equity_foreign_tenant_and_operator_are_denied(): void
    {
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $this->period['id'], 'version' => 7, 'retained_account_id' => $this->accounts['cash'], 'note' => 'Invalid'];
        $this->postJson($this->url('closing_propose'), $v)->assertUnprocessable();
        $v['retained_account_id'] = (string) Str::ulid();
        $this->postJson($this->url('closing_propose'), $v)->assertNotFound();
        $other = Organization::create(['name' => 'Private partner', 'type' => 'partner']);
        $this->getJson('/api/v1/organizations/'.$other->id.'/erp/context?section=balance_sheet')->assertForbidden();
        Sanctum::actingAs($this->member('warehouse'));
        $this->getJson($this->url('context').'?section=closings')->assertForbidden();
        $this->postJson($this->url('closing_propose'), $v)->assertForbidden();
    }

    public function test_net_loss_is_debited_to_retained_earnings_and_zero_activity_needs_no_fake_journal(): void
    {
        $this->postJournal(['expense' => 300], ['cash' => 300]);
        $c = $this->proposal();
        $this->assertSame(-80, $c['profit']);
        $lines = json_decode($c['lines'], true);
        $this->assertSame(80, collect($lines)->firstWhere('account_id', $this->accounts['retained'])['debit']);
        Sanctum::actingAs($this->reviewer);
        $this->command('closing_decide', $this->decide($c));
        $next = $this->command('accounting_period', ['name' => 'Empty', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-09', 'note' => 'No new activity']);
        $this->period = $next;
        Sanctum::actingAs($this->owner);
        $c = $this->proposal();
        $this->assertSame(0, $c['total_amount']);
        Sanctum::actingAs($this->reviewer);
        $ack = $this->command('closing_decide', $this->decide($c));
        $this->assertNull($ack['journal_id']);
        $p = DB::table('accounting_periods')->where('name', 'October')->first();
        $this->postJson($this->url('period_status'), ['operation_id' => (string) Str::uuid(), 'id' => $p->id, 'version' => $p->version, 'decision' => 'reopen', 'note' => 'Wrong dependency order'])->assertStatus(409);
    }

    public function test_account_metadata_change_invalidates_snapshot_even_without_period_activity(): void
    {
        $c = $this->proposal();
        DB::table('accounting_accounts')->where('id', $this->accounts['retained'])->update(['name' => 'Reviewed account changed']);
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->url('closing_decide'), ['operation_id' => (string) Str::uuid(), ...$this->decide($c)])->assertStatus(409);
        $this->assertDatabaseCount('accounting_journals', 3);
        $this->assertDatabaseHas('accounting_closings', ['id' => $c['id'], 'status' => 'draft']);
    }

    public function test_closing_write_failure_rolls_back_then_same_uuid_recovers_without_double_journal(): void
    {
        $c = $this->proposal();
        Sanctum::actingAs($this->reviewer);
        $v = ['operation_id' => (string) Str::uuid(), ...$this->decide($c)];
        DB::unprepared("CREATE TRIGGER fail_closing_update BEFORE UPDATE ON accounting_closings WHEN NEW.status = 'posted' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->postJson($this->url('closing_decide'), $v)->assertStatus(500);
        $this->assertDatabaseCount('accounting_journals', 3);
        $this->assertDatabaseHas('accounting_periods', ['id' => $this->period['id'], 'status' => 'open', 'version' => 7]);
        $this->assertDatabaseHas('accounting_closings', ['id' => $c['id'], 'status' => 'draft', 'version' => 1]);
        DB::unprepared('DROP TRIGGER fail_closing_update');
        $this->postJson($this->url('closing_decide'), $v)->assertCreated();
        $this->postJson($this->url('closing_decide'), $v)->assertCreated();
        $this->assertDatabaseCount('accounting_journals', 4);
    }
}
