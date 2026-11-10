<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GeneralLedger
{
    private function row(string $table, string $org, string $id): array
    {
        return (array) DB::table($table)->where('organization_id', $org)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    public function account(string $org, array $v): array
    {
        abort_if(DB::table('accounting_accounts')->where('organization_id', $org)->where('code', $v['code'])->exists(), 409, 'Kode akun sudah digunakan.');
        $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'code' => $v['code'], 'name' => $v['name'], 'kind' => $v['kind'], 'normal_balance' => $v['normal_balance'], 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_accounts')->insert($r);

        return $r;
    }

    public function period(string $org, array $v): array
    {
        abort_unless($v['ends_on'] >= $v['starts_on'], 422);
        abort_if(DB::table('accounting_periods')->where('organization_id', $org)->where('starts_on', '<=', $v['ends_on'])->where('ends_on', '>=', $v['starts_on'])->exists(), 409, 'Periode akuntansi tidak boleh tumpang tindih.');
        $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'name' => $v['name'], 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'], 'status' => 'open', 'version' => 1, 'note' => $v['note'], 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_periods')->insert($r);

        return $r;
    }

    private function periodFor(string $org, string $id, string $day): array
    {
        $p = $this->row('accounting_periods', $org, $id);
        abort_unless($p['status'] === 'open' && $day >= $p['starts_on'] && $day <= $p['ends_on'], 409, 'Tanggal di luar periode terbuka.');

        return $p;
    }

    public function journal(User $u, string $org, array $v): array
    {
        $this->periodFor($org, $v['period_id'], $v['posted_on']);
        abort_if(DB::table('accounting_journals')->where('organization_id', $org)->where('number', $v['number'])->exists(), 409, 'Nomor jurnal sudah digunakan.');
        $lines = [];
        $debit = $credit = 0;
        $ids = array_unique([...array_keys($v['debits']), ...array_keys($v['credits'])]);
        sort($ids);
        foreach ($ids as $id) {
            $d = (int) ($v['debits'][$id] ?? 0);
            $c = (int) ($v['credits'][$id] ?? 0);
            if (! $d && ! $c) {
                continue;
            }
            abort_unless(($d > 0) !== ($c > 0), 422, 'Satu akun tidak boleh debit dan kredit sekaligus.');
            $a = $this->row('accounting_accounts', $org, $id);
            abort_unless($a['active'], 409, 'Akun nonaktif.');
            $debit = Rupiah::add($debit, $d);
            $credit = Rupiah::add($credit, $c);
            $lines[] = ['account_id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'kind' => $a['kind'], 'normal_balance' => $a['normal_balance'], 'debit' => $d, 'credit' => $c];
        }
        abort_unless(count($lines) >= 2 && $debit > 0 && $debit === $credit, 422, 'Jurnal wajib seimbang dan mempunyai setidaknya dua akun.');
        $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'period_id' => $v['period_id'], 'number' => $v['number'], 'posted_on' => $v['posted_on'], 'status' => 'draft', 'version' => 1, 'lines' => json_encode($lines, JSON_THROW_ON_ERROR), 'total_amount' => $debit, 'evidence' => $v['evidence'], 'note' => $v['note'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_journals')->insert($r);
        DB::table('accounting_periods')->where('id', $r['period_id'])->increment('version');
        $r['lines'] = $lines;

        return $r;
    }

    public function decide(User $u, string $org, array $v): array
    {
        $j = $this->row('accounting_journals', $org, $v['id']);
        abort_unless($j['version'] === $v['version'] && $j['status'] === 'draft', 409, 'Versi/status jurnal berubah.');
        abort_if($j['created_by'] === $u->id, 403, 'Jurnal wajib ditinjau pengelola lain.');
        $this->periodFor($org, $j['period_id'], $j['posted_on']);
        $status = $v['decision'] === 'post' ? 'posted' : 'rejected';
        DB::table('accounting_journals')->where('id', $j['id'])->update(['status' => $status, 'version' => $j['version'] + 1, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'note' => $v['note'], 'updated_at' => now()]);
        DB::table('accounting_periods')->where('id', $j['period_id'])->increment('version');

        return [...$j, 'lines' => json_decode($j['lines'], true), 'status' => $status, 'version' => $j['version'] + 1];
    }

    public function periodStatus(string $org, array $v, int $actor): array
    {
        $p = $this->row('accounting_periods', $org, $v['id']);
        abort_unless($p['version'] === $v['version'], 409);
        $status = $v['decision'] === 'close' ? 'closed' : 'open';
        abort_if($p['status'] === $status, 409);
        if ($status === 'closed') {
            abort_if(DB::table('accounting_distribution_jobs')->where('organization_id', $org)->where('event_on', '<=', $p['ends_on'])->where('status', '!=', 'applied')->exists() || DB::table('accounting_missing_costs')->where(fn ($q) => $q->where('owner_organization_id', $org)->orWhere('counterparty_organization_id', $org))->where('event_on', '<=', $p['ends_on'])->where('status', '!=', 'approved')->exists(), 409, 'Selesaikan costing distribusi dan persetujuan biaya hilang sebelum menutup periode.');
            abort_if(DB::table('accounting_invoice_revaluations')->where('organization_id', $org)->where('event_on', '<=', $p['ends_on'])->where('status', '!=', 'applied')->exists(), 409, 'Selesaikan antrean penyesuaian nilai invoice sebelum menutup periode.');
            abort_if(DB::table('accounting_events')->where('organization_id', $org)->where('event_on', '<=', $p['ends_on'])->whereNotIn('status', ['posted', 'reconciled'])->exists(), 409, 'Selesaikan antrean jurnal sumber sebelum menutup periode.');
            abort_if(DB::table('accounting_journals')->where('period_id', $p['id'])->where('status', 'draft')->exists(), 409, 'Selesaikan jurnal draft sebelum menutup periode.');
        }
        if ($status === 'open') {
            app(PeriodClosing::class)->reopen($org, (object) $p, $actor);
        } else {
            abort_if(DB::table('accounting_closings')->where('active_period_id', $p['id'])->where('status', 'draft')->exists(), 409, 'Selesaikan proposal closing sebelum mengunci periode.');
        }
        DB::table('accounting_periods')->where('id', $p['id'])->update(['status' => $status, 'version' => $p['version'] + 1, 'note' => $v['note'], 'updated_at' => now()]);

        return [...$p, 'status' => $status, 'version' => $p['version'] + 1];
    }

    public function reverse(User $u, string $org, array $v): array
    {
        $j = $this->row('accounting_journals', $org, $v['id']);
        abort_unless($j['version'] === $v['version'] && $j['status'] === 'posted' && ! $j['reversed_by'] && ! $j['reversal_of'], 409, 'Jurnal tidak dapat dibalik lagi.');
        abort_if($j['created_by'] === $u->id, 403, 'Pembalikan wajib dilakukan pengelola lain.');
        abort_if(app(PeriodClosing::class)->protected($org, $j['id']), 409, 'Jurnal closing dikoreksi dengan membuka kembali periode.');
        abort_if(DB::table('accounting_events')->where('journal_id', $j['id'])->exists(), 409, 'Jurnal otomatis dikoreksi melalui transaksi sumber, bukan pembalikan manual.');
        $this->periodFor($org, $v['period_id'], $v['posted_on']);
        abort_if($v['posted_on'] < $j['posted_on'], 422);
        $lines = json_decode($j['lines'], true);
        foreach ($lines as &$l) {
            [$l['debit'],$l['credit']] = [$l['credit'], $l['debit']];
        }unset($l);
        $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'period_id' => $v['period_id'], 'number' => $v['number'], 'posted_on' => $v['posted_on'], 'status' => 'posted', 'version' => 1, 'lines' => json_encode($lines), 'total_amount' => $j['total_amount'], 'evidence' => $v['evidence'], 'note' => $v['note'], 'created_by' => $u->id, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'reversal_of' => $j['id'], 'created_at' => now(), 'updated_at' => now()];
        abort_if(DB::table('accounting_journals')->where('organization_id', $org)->where('number', $v['number'])->exists(), 409, 'Nomor jurnal sudah digunakan.');
        DB::table('accounting_journals')->insert($r);
        DB::table('accounting_periods')->where('id', $r['period_id'])->increment('version');
        DB::table('accounting_journals')->where('id', $j['id'])->update(['reversed_by' => $r['id'], 'version' => $j['version'] + 1, 'updated_at' => now()]);

        return [...$j, 'lines' => json_decode($j['lines'], true), 'reversed_by' => $r['id'], 'version' => $j['version'] + 1];
    }
}
