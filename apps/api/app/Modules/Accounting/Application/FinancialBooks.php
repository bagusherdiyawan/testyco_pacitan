<?php

namespace App\Modules\Accounting\Application;

use Illuminate\Support\Facades\DB;

/** Bounded, exact posted-book snapshot shared by statements and independent close review. */
final class FinancialBooks
{
    public function snapshot(string $org, object $period): array
    {
        $accounts = DB::table('accounting_accounts')->where('organization_id', $org)->orderBy('id')->limit(1001)->get()->keyBy('id');
        abort_if($accounts->count() > 1000, 422, 'Laporan maksimal1000akun.');
        $closingIds = DB::table('accounting_closings')->where('organization_id', $org)->whereIn('period_id', DB::table('accounting_periods')->where('organization_id', $org)->where('ends_on', '<=', $period->ends_on)->select('id'))->limit(10001)->get(['journal_id', 'reopening_journal_id']);
        abort_if($closingIds->count() > 10000, 422, 'Riwayat closing melampaui10.000.');
        $excluded = [];
        foreach ($closingIds as $c) {
            foreach ([$c->journal_id, $c->reopening_journal_id] as $id) {
                if ($id) {
                    $excluded[$id] = true;
                }
            }
        }
        $totals = [];
        $proof = hash_init('sha256');
        hash_update($proof, json_encode([$period, $accounts], JSON_THROW_ON_ERROR));
        $count = 0;
        $bytes = 0;
        foreach (DB::table('accounting_journals')->where('organization_id', $org)->where('status', 'posted')->where('posted_on', '<=', $period->ends_on)->orderBy('id')->limit(10001)->cursor() as $j) {
            $raw = json_encode((array) $j, JSON_THROW_ON_ERROR);
            $bytes += strlen($raw);
            abort_if(++$count > 10000 || $bytes > 8 * 1024 * 1024, 422, 'Buku maksimal10.000jurnal/8MiB sampai akhir periode; laporan tidak dipotong.');
            hash_update($proof, $raw);
            $debits = $credits = 0;
            foreach (json_decode($j->lines, true, 512, JSON_THROW_ON_ERROR) as $l) {
                $a = $accounts->get($l['account_id'] ?? '');
                abort_unless($a && is_int($l['debit'] ?? null) && is_int($l['credit'] ?? null) && $l['debit'] >= 0 && $l['credit'] >= 0 && ($l['debit'] === 0 || $l['credit'] === 0), 409, 'Bukti jurnal/akun tidak valid; rekonsiliasi buku diperlukan.');
                $debits += $l['debit'];
                $credits += $l['credit'];
                $id = $a->id;
                $t = $totals[$id] ?? ['opening' => 0, 'debit' => 0, 'credit' => 0, 'income' => 0];
                $delta = $l['debit'] - $l['credit'];
                if ($j->posted_on < $period->starts_on) {
                    $t['opening'] += $delta;
                } else {
                    $t['debit'] += $l['debit'];
                    $t['credit'] += $l['credit'];
                    // Close/reopen transfers never count as operating income or expense.
                    if (! isset($excluded[$j->id])) {
                        $t['income'] += $delta;
                    }
                }
                $totals[$id] = $t;
            }
            abort_unless($debits === $credits && $debits === (int) $j->total_amount, 409, 'Jurnal sumber tidak seimbang.');
        }
        $rows = [];
        $assets = $liabilities = $equity = $unclosed = $revenue = $expense = $sum = 0;
        foreach ($accounts as $a) {
            $t = $totals[$a->id] ?? ['opening' => 0, 'debit' => 0, 'credit' => 0, 'income' => 0];
            $closing = $t['opening'] + $t['debit'] - $t['credit'];
            $sum += $closing;
            match ($a->kind) {
                'asset' => $assets += $closing,
                'liability' => $liabilities -= $closing,
                'equity' => $equity -= $closing,
                'revenue' => $revenue -= $t['income'],
                'expense' => $expense += $t['income'],
            };
            if (in_array($a->kind, ['revenue', 'expense'], true)) {
                $unclosed -= $closing;
            }
            $rows[] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'normal_balance' => $a->normal_balance, ...$t, 'closing' => $closing];
        }
        abort_unless($sum === 0 && $assets === $liabilities + $equity + $unclosed, 409, 'Buku tidak seimbang; laporan ditolak.');

        return ['basis_hash' => hash_final($proof), 'journal_count' => $count, 'accounts' => $rows, 'totals' => ['assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'unclosed_profit' => $unclosed, 'revenue' => $revenue, 'expense' => $expense, 'profit' => $revenue - $expense]];
    }

    public function pending(string $org, object $period): array
    {
        return [
            'source_events' => DB::table('accounting_events')->where('organization_id', $org)->where('event_on', '<=', $period->ends_on)->whereNotIn('status', ['posted', 'reconciled'])->count(),
            'distribution_revaluations' => DB::table('accounting_distribution_jobs')->where('organization_id', $org)->where('event_on', '<=', $period->ends_on)->where('status', '!=', 'applied')->count(),
            'missing_agreements' => DB::table('accounting_missing_costs')->where(fn ($q) => $q->where('owner_organization_id', $org)->orWhere('counterparty_organization_id', $org))->where('event_on', '<=', $period->ends_on)->where('status', '!=', 'approved')->count(),
            'invoice_revaluations' => DB::table('accounting_invoice_revaluations')->where('organization_id', $org)->where('event_on', '<=', $period->ends_on)->where('status', '!=', 'applied')->count(),
            'draft_journals' => DB::table('accounting_journals')->where('organization_id', $org)->where('posted_on', '<=', $period->ends_on)->where('status', 'draft')->count(),
            'earlier_open_periods' => DB::table('accounting_periods')->where('organization_id', $org)->where('ends_on', '<', $period->starts_on)->where('status', 'open')->count(),
        ];
    }
}
