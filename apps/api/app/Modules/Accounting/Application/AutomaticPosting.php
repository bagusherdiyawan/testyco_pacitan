<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AutomaticPosting
{
    // Inventory accounts include quarantine; operating clearing accounts are not proof of payment.
    public const ROLES = [
        'cash' => ['Kas', 'asset', 'debit'], 'bank' => ['Bank/non-tunai terverifikasi', 'asset', 'debit'],
        'raw' => ['Persediaan bahan, termasuk karantina', 'asset', 'debit'],
        'finished' => ['Persediaan produk jadi, termasuk karantina', 'asset', 'debit'],
        'wip' => ['Barang dalam proses', 'asset', 'debit'], 'payable' => ['Utang / refund supplier', 'liability', 'credit'],
        'sales' => ['Pendapatan POS bruto', 'revenue', 'credit'], 'discount' => ['Diskon POS', 'expense', 'debit'],
        'refund' => ['Pengembalian penjualan', 'expense', 'debit'], 'cogs' => ['HPP penjualan', 'expense', 'debit'],
        'loss' => ['Kerugian persediaan / produksi gagal', 'expense', 'debit'], 'opening' => ['Lawan saldo awal persediaan', 'equity', 'credit'],
        'adjustment' => ['Selisih opname', 'expense', 'debit'], 'labor' => ['Akrual / penampung tenaga kerja', 'liability', 'credit'],
        'overhead' => ['Akrual / penampung overhead', 'liability', 'credit'],
    ];

    public function policy(User $u, string $org, array $v): array
    {
        $mapping = [];
        foreach (self::ROLES as $role => [$label,$kind,$normal]) {
            $a = DB::table('accounting_accounts')->where('organization_id', $org)->where('id', $v[$role.'_account'])->where('active', true)->firstOrFail();
            abort_unless($a->kind === $kind && $a->normal_balance === $normal, 422, 'Kelompok/saldo normal tidak sesuai untuk '.$label);
            $mapping[$role] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'normal_balance' => $a->normal_balance];
        }
        abort_unless(count(array_unique(array_column($mapping, 'account_id'))) === count($mapping), 422, 'Setiap fungsi wajib akun tersendiri agar rekonsiliasi dapat ditelusuri.');
        $r = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'status' => 'draft', 'version' => 1, 'mapping' => json_encode($mapping), 'note' => $v['note'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_policies')->insert($r);

        return $r;
    }

    public function decide(User $u, string $org, array $v): array
    {
        $p = (array) DB::table('accounting_policies')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($p['version'] === $v['version'] && $p['status'] === 'draft', 409);
        abort_if($p['created_by'] === $u->id, 403, 'Persetujuan pemetaan wajib pengelola lain.');
        $status = $v['decision'] === 'approve' ? 'active' : 'rejected';
        if ($status === 'active') {
            DB::table('accounting_policies')->where('organization_id', $org)->where('status', 'active')->update(['status' => 'superseded', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
        }
        $new = ['status' => $status, 'version' => $p['version'] + 1, 'approved_by' => $u->id, 'activated_at' => $status === 'active' ? now() : null, 'updated_at' => now()];
        DB::table('accounting_policies')->where('id', $p['id'])->update($new);

        return [...$p, ...$new];
    }

    public function suspend(string $org, array $v): array
    {
        $p = (array) DB::table('accounting_policies')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($p['version'] === $v['version'] && $p['status'] === 'active', 409);
        $new = ['status' => 'suspended', 'version' => $p['version'] + 1, 'updated_at' => now()];
        DB::table('accounting_policies')->where('id', $p['id'])->update($new);

        return [...$p, ...$new];
    }

    /** Terms are server-built pairs [debit role, credit role, exact IDR or null]. */
    public function record(string $org, int $actor, string $type, string $source, string $day, array $terms, string $evidence, ?string $reason = null): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $p = DB::table('accounting_policies')->where('organization_id', $org)->where('status', 'active')->first();
        if (! $p) {
            return;
        } // Explicit prospective activation, never import old sources.
        $gross = 0;
        foreach ($terms as $term) {
            if ($term[2] !== null) {
                $gross += $term[2];
            }
        }
        if ($gross > Rupiah::LIMIT) {
            $reason = 'Total sumber melebihi batas jurnal development; rekonsiliasi manual diperlukan.';
        }
        $terms = array_values(array_filter($terms, fn ($t) => $t[2] === null || $t[2] > 0));
        if (! $terms && ! $reason) {
            return;
        }
        DB::table('accounting_events')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $org, 'policy_id' => $p->id, 'source_type' => $type, 'source_id' => $source, 'event_on' => $day, 'terms' => json_encode($terms, JSON_THROW_ON_ERROR), 'evidence' => $evidence, 'status' => $reason ? 'review_required' : 'pending', 'reason' => $reason, 'version' => 1, 'actor_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function retry(string $org, array $v): array
    {
        $e = (array) DB::table('accounting_events')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($e['version'] === $v['version'] && $e['status'] === 'review_required', 409);
        abort_unless(str_starts_with($e['reason'] ?? '', 'Tanggal sumber'), 409, 'Kasus nilai/komersial memerlukan rekonsiliasi sumber; tidak dapat dilewati dengan retry.');
        abort_if(collect(json_decode($e['terms'], true))->contains(fn ($t) => $t[2] === null) || ! json_decode($e['terms'], true), 409, 'Sumber tanpa biaya/dukungan tidak dapat diberi nilai tebakan. Rekonsiliasi sumber diperlukan.');
        DB::table('accounting_events')->where('id', $e['id'])->update(['status' => 'pending', 'reason' => null, 'version' => $e['version'] + 1, 'updated_at' => now()]);

        return [...$e, 'status' => 'pending', 'version' => $e['version'] + 1];
    }

    public function reconcile(User $u, string $org, array $v): array
    {
        $e = (array) DB::table('accounting_events')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($e['version'] === $v['version'] && $e['status'] === 'review_required', 409);
        $j = DB::table('accounting_journals')->where('organization_id', $org)->where('id', $v['journal_id'])->lockForUpdate()->firstOrFail();
        abort_unless($j->status === 'posted' && ! $j->reversed_by && ! $j->reversal_of && $j->posted_on === $e['event_on'] && ! DB::table('accounting_events')->where('journal_id', $j->id)->exists(), 409, 'Gunakan jurnal manual reviewed yang belum ditautkan, pada tanggal sumber yang sama.');
        abort_if($j->created_by === $u->id, 403, 'Penautan rekonsiliasi wajib pengelola berbeda dari pembuat jurnal.');
        abort_unless(DB::table('accounting_periods')->where('organization_id', $org)->where('id', $j->period_id)->where('status', 'open')->lockForUpdate()->exists(), 409, 'Buka periode asal sebelum rekonsiliasi.');
        $new = ['status' => 'reconciled', 'journal_id' => $j->id, 'reason' => 'Rekonsiliasi manual: '.$v['note'], 'version' => $e['version'] + 1, 'updated_at' => now()];
        DB::table('accounting_events')->where('id', $e['id'])->update($new);
        DB::table('accounting_periods')->where('id', $j->period_id)->increment('version');

        return [...$e, ...$new];
    }

    public function drain(int $limit = 100): int
    {
        $ids = DB::table('accounting_events')->where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id');
        $done = 0;
        foreach ($ids as $id) {
            if ($this->post($id)) {
                $done++;
            }
        }

        return $done;
    }

    public function post(string $id): bool
    {
        $org = DB::table('accounting_events')->where('id', $id)->value('organization_id');
        if (! $org) {
            return false;
        }

        return DB::transaction(function () use ($id, $org) {
            Organization::whereKey($org)->lockForUpdate()->firstOrFail();
            $e = DB::table('accounting_events')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($e->status !== 'pending') {
                return false;
            }
            $p = DB::table('accounting_policies')->where('organization_id', $org)->where('id', $e->policy_id)->firstOrFail();
            $mapping = json_decode($p->mapping, true, 512, JSON_THROW_ON_ERROR);
            $period = DB::table('accounting_periods')->where('organization_id', $org)->where('starts_on', '<=', $e->event_on)->where('ends_on', '>=', $e->event_on)->where('status', 'open')->lockForUpdate()->first();
            $reason = $period ? null : 'Tanggal sumber tidak mempunyai periode terbuka; buka periode asal lalu coba kembali.';
            $totals = [];
            foreach (json_decode($e->terms, true) as [$d,$c,$amount]) {
                if ($amount === null) {
                    $reason = 'Nilai batch asal belum diketahui; tidak boleh menganggap HPP nol.';

                    continue;
                }
                foreach ([[$d, 1], [$c, -1]] as [$role,$sign]) {
                    abort_unless(isset($mapping[$role]) && is_int($amount) && $amount >= 0 && $amount <= Rupiah::LIMIT, 500);
                    $a = $mapping[$role];
                    $key = $a['account_id'];
                    $totals[$key] = ($totals[$key] ?? 0) + $sign * $amount;
                }
            }
            if ($reason) {
                DB::table('accounting_events')->where('id', $id)->update(['status' => 'review_required', 'reason' => $reason, 'version' => $e->version + 1, 'updated_at' => now()]);

                return false;
            }
            $lines = [];
            $debit = $credit = 0;
            foreach ($mapping as $a) {
                $n = $totals[$a['account_id']] ?? 0;
                if (! $n) {
                    continue;
                }
                $d = max(0, $n);
                $c = max(0, -$n);
                $debit = Rupiah::add($debit, $d);
                $credit = Rupiah::add($credit, $c);
                $lines[] = [...$a, 'debit' => $d, 'credit' => $c];
            }
            abort_unless($debit === $credit && $debit > 0, 500, 'Jurnal sumber tidak seimbang.');
            $j = (string) Str::ulid();
            DB::table('accounting_journals')->insert(['id' => $j, 'organization_id' => $org, 'period_id' => $period->id, 'number' => 'AUTO-'.$e->id, 'posted_on' => $e->event_on, 'status' => 'posted', 'version' => 1, 'lines' => json_encode($lines), 'total_amount' => $debit, 'evidence' => $e->evidence, 'note' => $e->source_type.' · '.$e->source_id.' · pemetaan '.$p->id, 'created_by' => $e->actor_id, 'reviewed_by' => $p->approved_by, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('accounting_periods')->where('id', $period->id)->increment('version');
            DB::table('accounting_events')->where('id', $id)->update(['status' => 'posted', 'journal_id' => $j, 'reason' => null, 'version' => $e->version + 1, 'updated_at' => now()]);

            return true;
        }, 3);
    }
}
