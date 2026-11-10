<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PeriodClosing
{
    private function ready(string $org, object $p): array
    {
        abort_unless($p->status === 'open' && $p->ends_on <= now('Asia/Jakarta')->format('Y-m-d'), 409, 'Closing formal memerlukan periode terbuka yang sudah berakhir.');
        abort_if(array_sum(app(FinancialBooks::class)->pending($org, $p)) > 0, 409, 'Selesaikan draft, antrean sumber/biaya dan periode sebelumnya sebelum closing.');

        return app(FinancialBooks::class)->snapshot($org, $p);
    }

    public function propose(User $u, string $org, array $v): array
    {
        $p = DB::table('accounting_periods')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($p->version === $v['version'], 409, 'Versi periode berubah.');
        abort_if(DB::table('accounting_closings')->where('active_period_id', $p->id)->exists(), 409, 'Proposal/closing aktif sudah ada.');
        $a = DB::table('accounting_accounts')->where('organization_id', $org)->where('id', $v['retained_account_id'])->lockForUpdate()->firstOrFail();
        abort_unless($a->active && $a->kind === 'equity' && $a->normal_balance === 'credit', 422, 'Pilih akun ekuitas laba ditahan aktif dengan saldo normal kredit.');
        $s = $this->ready($org, $p);
        $lines = [];
        $profit = $debit = $credit = 0;
        foreach ($s['accounts'] as $row) {
            if (! in_array($row['kind'], ['revenue', 'expense'], true) || $row['closing'] === 0) {
                continue;
            }
            $n = -$row['closing'];
            $profit += $n;
            $d = max(0, $n);
            $c = max(0, -$n);
            $debit = Rupiah::add($debit, $d);
            $credit = Rupiah::add($credit, $c);
            $lines[] = ['account_id' => $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'kind' => $row['kind'], 'normal_balance' => $row['normal_balance'], 'debit' => $d, 'credit' => $c];
        }
        if ($profit !== 0) {
            $d = max(0, -$profit);
            $c = max(0, $profit);
            $debit = Rupiah::add($debit, $d);
            $credit = Rupiah::add($credit, $c);
            $lines[] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'normal_balance' => $a->normal_balance, 'debit' => $d, 'credit' => $c];
        }
        abort_unless($debit === $credit, 409);
        $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'period_id' => $p->id, 'active_period_id' => $p->id, 'retained_account_id' => $a->id, 'status' => 'draft', 'version' => 1, 'period_version' => $p->version, 'basis_hash' => $s['basis_hash'], 'snapshot' => json_encode($s, JSON_THROW_ON_ERROR), 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'profit' => $profit, 'total_amount' => $debit, 'created_by' => $u->id, 'note' => $v['note'], 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_closings')->insert($r);

        return $r;
    }

    public function decide(User $u, string $org, array $v): array
    {
        $r = (array) DB::table('accounting_closings')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($r['status'] === 'draft' && $r['version'] === $v['version'], 409);
        abort_if($r['created_by'] === $u->id, 403, 'Closing memerlukan pengelola peninjau berbeda.');
        $new = ['status' => 'rejected', 'active_period_id' => null, 'version' => $r['version'] + 1, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'note' => $v['note'], 'updated_at' => now()];
        if ($v['decision'] === 'approve') {
            $p = DB::table('accounting_periods')->where('organization_id', $org)->where('id', $r['period_id'])->lockForUpdate()->firstOrFail();
            abort_unless($p->version === $r['period_version'], 409, 'Aktivitas periode berubah; tolak dan ajukan snapshot baru.');
            $s = $this->ready($org, $p);
            abort_unless(hash_equals($r['basis_hash'], $s['basis_hash']), 409, 'Buku/akun berubah; tolak dan ajukan snapshot baru.');
            $journal = null;
            if ($r['total_amount'] > 0) {
                $journal = (string) Str::ulid();
                $this->journal($journal, $org, $p, 'CLOSE-'.$r['id'], json_decode($r['lines'], true), $r['total_amount'], $r['created_by'], $u->id, 'Closing '.$r['id']);
            }
            $new = [...$new, 'status' => 'posted', 'active_period_id' => $p->id, 'journal_id' => $journal];
            DB::table('accounting_periods')->where('id', $p->id)->update(['status' => 'closed', 'version' => $p->version + 1, 'updated_at' => now()]);
        }
        DB::table('accounting_closings')->where('id', $r['id'])->update($new);

        return [...$r, ...$new];
    }

    private function journal(string $id, string $org, object $p, string $number, array $lines, int $total, int $author, int $reviewer, string $note, ?string $reversal = null): void
    {
        DB::table('accounting_journals')->insert(['id' => $id, 'organization_id' => $org, 'period_id' => $p->id, 'number' => $number, 'posted_on' => $p->ends_on, 'status' => 'posted', 'version' => 1, 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total_amount' => $total, 'evidence' => $note, 'note' => $note, 'created_by' => $author, 'reviewed_by' => $reviewer, 'reviewed_at' => now(), 'reversal_of' => $reversal, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Period reopening reverses only the closing transfer, never historical operating journals. */
    public function reopen(string $org, object $p, int $actor): void
    {
        abort_if(DB::table('accounting_closings')->where('organization_id', $org)->where('status', 'posted')->whereIn('period_id', DB::table('accounting_periods')->where('organization_id', $org)->where('starts_on', '>', $p->ends_on)->select('id'))->exists(), 409, 'Buka kembali closing formal periode berikutnya terlebih dahulu.');
        $r = DB::table('accounting_closings')->where('organization_id', $org)->where('active_period_id', $p->id)->where('status', 'posted')->lockForUpdate()->first();
        if (! $r) {
            return;
        }
        $journal = null;
        if ($r->journal_id) {
            $lines = json_decode($r->lines, true);
            foreach ($lines as &$l) {
                [$l['debit'], $l['credit']] = [$l['credit'], $l['debit']];
            } unset($l);
            $journal = (string) Str::ulid();
            $this->journal($journal, $org, $p, 'REOPEN-'.$r->id, $lines, (int) $r->total_amount, $actor, $actor, 'Buka kembali closing '.$r->id, $r->journal_id);
            DB::table('accounting_journals')->where('id', $r->journal_id)->update(['reversed_by' => $journal, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
        DB::table('accounting_closings')->where('id', $r->id)->update(['status' => 'reopened', 'active_period_id' => null, 'version' => $r->version + 1, 'reopening_journal_id' => $journal, 'updated_at' => now()]);
    }

    public function protected(string $org, string $id): bool
    {
        return DB::table('accounting_closings')->where('organization_id', $org)->where(fn ($q) => $q->where('journal_id', $id)->orWhere('reopening_journal_id', $id))->exists();
    }
}
