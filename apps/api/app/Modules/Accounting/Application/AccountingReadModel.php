<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Manufacturing\Application\ErpForms as F;
use Illuminate\Support\Facades\DB;

final class AccountingReadModel
{
    public const SECTIONS = ['accounts', 'periods', 'journals', 'trial_balance', 'posting', 'posting_events', 'valuations', 'valuation_stock', 'cost_ledger'];

    private function options(string $table, string $org): array
    {
        $r = DB::table($table)->where('organization_id', $org)->limit(1001)->get();
        abort_if($r->count() > 1000, 422, 'Pilihan akun/periode melebihi1000.');

        return $r->map(fn ($x) => (array) $x)->all();
    }

    public function context(string $org, string $section, array $v, int $actor): array
    {
        // Writers use the same organization lock: chart/period/journal totals are one coherent view.
        return DB::transaction(function () use ($org, $section, $v, $actor) {
            Organization::whereKey($org)->lockForUpdate()->firstOrFail();

            return $this->read($org, $section, $v, $actor);
        });
    }

    private function read(string $org, string $section, array $v, int $actor): array
    {
        if (isset($v['period_id'])) {
            abort_unless(DB::table('accounting_periods')->where('organization_id', $org)->where('id', $v['period_id'])->exists(), 404);
        }
        if (in_array($section, ['posting', 'posting_events', 'valuations', 'valuation_stock', 'cost_ledger'], true)) {
            return app(PostingReadModel::class)->context($org, $section, $actor);
        }
        $accounts = $this->options('accounting_accounts', $org);
        $periods = $this->options('accounting_periods', $org);
        $accountOptions = [];
        foreach ($accounts as $a) {
            if ($a['active']) {
                $accountOptions[$a['id']] = $a['code'].' · '.$a['name'];
            }
        }$periodOptions = [];
        foreach ($periods as $p) {
            if ($p['status'] === 'open') {
                $periodOptions[$p['id']] = $p['name'].' ('.$p['starts_on'].' – '.$p['ends_on'].')';
            }
        }
        $field = F::field(...);
        $note = $field('note', 'Alasan / catatan');
        $make = function ($operation, $label, $fields, $fixed = [], $id = null, $version = null) {
            return ['key' => $operation.'-'.($id ?? '').'-'.($fixed['decision'] ?? ''), 'operation' => $operation, 'label' => $label, 'fields' => $fields, 'fixed' => $fixed, 'subject_id' => $id, 'expected_id' => $id, 'expected_version' => $version];
        };
        $forms = match ($section) {
            'accounts' => [$make('account', 'Buat akun buku besar', [$field('code', 'Kode akun'), $field('name', 'Nama akun'), $field('kind', 'Kelompok akun', 'choice', ['asset' => 'Aset', 'liability' => 'Liabilitas', 'equity' => 'Ekuitas', 'revenue' => 'Pendapatan', 'expense' => 'Beban']), $field('normal_balance', 'Saldo normal eksplisit', 'choice', ['debit' => 'Debit', 'credit' => 'Kredit'])])],
            'periods' => [$make('accounting_period', 'Buat periode pembukuan', [$field('name', 'Nama periode'), $field('starts_on', 'Mulai', 'date'), $field('ends_on', 'Akhir inklusif', 'date'), $note])],
            'journals' => [$make('journal', 'Ajukan jurnal manual', [$field('period_id', 'Periode terbuka', 'choice', $periodOptions), $field('number', 'Nomor jurnal'), $field('posted_on', 'Tanggal buku WIB', 'date'), $field('debits', 'Debit per akun (Rp)', 'money_map', $accountOptions), $field('credits', 'Kredit per akun (Rp)', 'money_map', $accountOptions), $field('evidence', 'Referensi bukti dan transaksi asal'), $note])],default => []
        };
        $base = ['forms' => $forms, 'filter_periods' => array_column($periods, 'name', 'id'), 'filter_stores' => [], 'filter_materials' => []];
        if ($section === 'trial_balance') {
            return [...$base, ...$this->trial($org, $v, $accounts)];
        }
        $table = match ($section) {
            'accounts' => 'accounting_accounts','periods' => 'accounting_periods',default => 'accounting_journals'
        };
        $q = DB::table($table)->where('organization_id', $org);
        if ($section === 'journals' && isset($v['period_id'])) {
            $q->where('period_id', $v['period_id']);
        }$page = $q->orderByDesc('id')->paginate(30);
        $rows = [];
        foreach ($page->items() as $object) {
            $r = (array) $object;
            $actions = [];
            $text = [];
            foreach (['code' => 'Kode', 'name' => 'Nama', 'kind' => 'Kelompok', 'normal_balance' => 'Saldo normal', 'number' => 'Nomor', 'status' => 'Status', 'version' => 'Versi', 'starts_on' => 'Mulai', 'ends_on' => 'Akhir', 'posted_on' => 'Tanggal buku', 'total_amount' => 'Nilai (Rp)', 'evidence' => 'Bukti', 'note' => 'Catatan'] as $k => $label) {
                if (isset($r[$k])) {
                    $text[] = $label.': '.$r[$k];
                }
            }
            if ($section === 'journals') {
                $r['lines'] = json_decode($r['lines'], true);
                foreach ($r['lines'] as $l) {
                    $text[] = $l['code'].' '.$l['name'].' · debit Rp'.$l['debit'].' · kredit Rp'.$l['credit'];
                }
                if ($r['status'] === 'draft' && $r['created_by'] !== $actor) {
                    foreach (['post' => 'Setujui dan bukukan', 'reject' => 'Tolak jurnal'] as $d => $label) {
                        $actions[] = $make('journal_decide', $label, [$note], ['id' => $r['id'], 'version' => $r['version'], 'decision' => $d], $r['id'], $r['version'] + 1);
                    }
                }
                if (! DB::table('accounting_events')->where('journal_id', $r['id'])->exists() && $r['status'] === 'posted' && ! $r['reversed_by'] && ! $r['reversal_of'] && $r['created_by'] !== $actor) {
                    $actions[] = $make('journal_reverse', 'Balik jurnal dengan jejak', [$field('period_id', 'Periode pembalikan terbuka', 'choice', $periodOptions), $field('number', 'Nomor jurnal pembalikan'), $field('posted_on', 'Tanggal pembalikan WIB', 'date'), $field('evidence', 'Bukti pembalikan'), $note], ['id' => $r['id'], 'version' => $r['version']], $r['id'], $r['version'] + 1);
                }
            }
            if ($section === 'periods') {
                $d = $r['status'] === 'open' ? 'close' : 'reopen';
                $actions[] = $make('period_status', $d === 'close' ? 'Tutup periode' : 'Buka kembali periode', [$note], ['id' => $r['id'], 'version' => $r['version'], 'decision' => $d], $r['id'], $r['version'] + 1);
            }
            $rows[] = [...$r, 'summary' => implode("\n", $text), 'forms' => $actions];
        }

        return [...$page->toArray(), ...$base, 'data' => $rows];
    }

    private function trial(string $org, array $v, array $accounts): array
    {
        if (empty($v['period_id'])) {
            return ['data' => [], 'current_page' => 1, 'next_page_url' => null, 'notice' => 'Pilih periode. Laporan hanya jurnal posted; saldo awal harus dicatat dengan jurnal berimbang eksplisit.'];
        }
        $p = DB::table('accounting_periods')->where('organization_id', $org)->where('id', $v['period_id'])->firstOrFail();
        $journals = DB::table('accounting_journals')->where('organization_id', $org)->where('status', 'posted')->where('posted_on', '<=', $p->ends_on)->orderBy('id')->limit(10001)->cursor();
        $totals = [];
        $count = 0;
        $bytes = 2;
        foreach ($journals as $j) {
            $count++;
            $bytes += strlen(json_encode((array) $j, JSON_THROW_ON_ERROR)) + 1;
            abort_if($count > 10000 || $bytes > 8 * 1024 * 1024, 422, 'Laporan maksimal10.000jurnal/8MiB sampai akhir periode.');
            foreach (json_decode($j->lines, true) as $l) {
                $id = $l['account_id'];
                $t = $totals[$id] ?? ['opening' => 0, 'debit' => 0, 'credit' => 0];
                if ($j->posted_on < $p->starts_on) {
                    $t['opening'] += $l['debit'] - $l['credit'];
                } else {
                    $t['debit'] += $l['debit'];
                    $t['credit'] += $l['credit'];
                }$totals[$id] = $t;
            }
        }
        $rows = [];
        $sumD = $sumC = $sumClosing = $revenue = $expense = $assets = $liabilities = $equity = $cumulativeProfit = 0;
        foreach ($accounts as $a) {
            $t = $totals[$a['id']] ?? ['opening' => 0, 'debit' => 0, 'credit' => 0];
            $closing = $t['opening'] + $t['debit'] - $t['credit'];
            $sumD += $t['debit'];
            $sumC += $t['credit'];
            $sumClosing += $closing;
            match ($a['kind']) {
                'asset' => $assets += $closing,'liability' => $liabilities -= $closing,'equity' => $equity -= $closing,'revenue' => $revenue += $t['credit'] - $t['debit'],'expense' => $expense += $t['debit'] - $t['credit']
            };
            if (in_array($a['kind'], ['revenue', 'expense'], true)) {
                $cumulativeProfit -= $closing;
            }
            $rows[] = ['id' => $a['id'], 'organization_id' => $org, 'summary' => $a['code'].' '.$a['name'].' ('.$a['kind'].') · awal bertanda Rp'.$t['opening'].' · debit Rp'.$t['debit'].' · kredit Rp'.$t['credit'].' · akhir bertanda Rp'.$closing, 'forms' => []];
        }
        abort_unless($sumD === $sumC && $sumClosing === 0, 409, 'Buku besar tidak seimbang; laporan ditolak.');
        $rows[] = ['id' => 'TOTAL', 'organization_id' => $org, 'summary' => 'Periode '.$p->name.' · debit Rp'.$sumD.' · kredit Rp'.$sumC.' · neto akun pendapatan periode Rp'.$revenue.' · neto akun beban periode Rp'.$expense.' · selisih nominal periode Rp'.($revenue - $expense).' · aset Rp'.$assets.' · liabilitas Rp'.$liabilities.' · ekuitas Rp'.$equity.' · hasil kumulatif belum ditutup Rp'.$cumulativeProfit, 'forms' => []];

        return ['data' => $rows, 'current_page' => 1, 'next_page_url' => null, 'notice' => 'Saldo bertanda debit positif/kredit negatif. Pembalikan tetap memasukkan jurnal asli dan lawannya sesuai tanggal buku. Laporan mencakup jurnal manual dan jurnal sumber otomatis yang sudah posted. Periksa antrean sumber agar laporan tidak dibaca sebagai lengkap saat masih ada kasus tertahan.'];
    }
}
