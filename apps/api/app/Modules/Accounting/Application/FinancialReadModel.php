<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Manufacturing\Application\ErpForms as F;
use Illuminate\Support\Facades\DB;

final class FinancialReadModel
{
    public function context(string $org, string $section, array $v, int $actor): array
    {
        $periods = DB::table('accounting_periods')->where('organization_id', $org)->orderByDesc('starts_on')->limit(1001)->get();
        abort_if($periods->count() > 1000, 422, 'Pilihan maksimal1000periode.');
        $base = ['selected_period_id' => $v['period_id'] ?? null, 'forms' => [], 'filter_stores' => [], 'filter_materials' => [], 'filter_periods' => $periods->pluck('name', 'id')->all()];
        if ($section === 'closings') {
            $equity = DB::table('accounting_accounts')->where('organization_id', $org)->where('kind', 'equity')->where('normal_balance', 'credit')->where('active', true)->orderBy('code')->limit(1001)->get();
            abort_if($equity->count() > 1000, 422);
            $rows = [];
            foreach ($periods as $p) {
                if ($p->status !== 'open' || $p->ends_on > now('Asia/Jakarta')->format('Y-m-d') || DB::table('accounting_closings')->where('active_period_id', $p->id)->exists()) {
                    continue;
                }
                if (isset($v['period_id']) && $p->id !== $v['period_id']) {
                    continue;
                }
                $base['forms'][] = ['key' => 'closing_propose-'.$p->id, 'operation' => 'closing_propose', 'label' => 'Ajukan closing '.$p->name, 'fields' => [F::field('retained_account_id', 'Akun ekuitas laba ditahan', 'choice', $equity->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name])->all()), F::field('note', 'Bukti rekonsiliasi dan catatan closing')], 'fixed' => ['id' => $p->id, 'version' => $p->version], 'subject_id' => $p->id, 'expected_id' => null, 'expected_version' => null];
            }
            $page = DB::table('accounting_closings')->where('organization_id', $org)->when(isset($v['period_id']), fn ($q) => $q->where('period_id', $v['period_id']))->orderByDesc('id')->paginate(30);
            foreach ($page->items() as $object) {
                $r = (array) $object;
                $snapshot = json_decode($r['snapshot'], true);
                $forms = [];
                if ($r['status'] === 'draft' && $r['created_by'] !== $actor) {
                    foreach (['approve' => 'Setujui jurnal penutup dan tutup periode', 'reject' => 'Tolak proposal closing'] as $decision => $label) {
                        $forms[] = ['key' => 'closing_decide-'.$r['id'].'-'.$decision, 'operation' => 'closing_decide', 'label' => $label, 'fields' => [F::field('note', 'Bukti pemeriksaan independen')], 'fixed' => ['id' => $r['id'], 'version' => $r['version'], 'decision' => $decision], 'subject_id' => $r['id'], 'expected_id' => $r['id'], 'expected_version' => $r['version'] + 1];
                    }
                }
                $text = 'Periode '.$r['period_id'].' · '.$r['status'].' · versi'.$r['version'].' · basis '.$r['basis_hash']."\nLaba kumulatif yang dipindahkan Rp".$r['profit'].' · jurnal '.$r['journal_id'].' · pembukaan kembali '.$r['reopening_journal_id']."\nLaba periode sebelum penutupan Rp".$snapshot['totals']['profit']."\n".$r['note'];
                foreach (json_decode($r['lines'], true) as $line) {
                    $text .= "\n".$line['code'].' '.$line['name'].' · debit Rp'.$line['debit'].' · kredit Rp'.$line['credit'];
                }
                $rows[] = [...$r, 'snapshot' => $snapshot, 'lines' => json_decode($r['lines'], true), 'summary' => $text, 'forms' => $forms];
            }

            return [...$page->toArray(), ...$base, 'data' => $rows, 'notice' => 'Closing memerlukan periode yang sudah berakhir, antrean/draft selesai, periode sebelumnya ditutup dan peninjau berbeda. Snapshot berubah harus ditolak dan diajukan ulang. Buka kembali melalui Periode; jurnal penutup dibalik dengan jejak.'];
        }
        if (empty($v['period_id'])) {
            return [...$base, 'data' => [], 'current_page' => 1, 'next_page_url' => null, 'notice' => 'Pilih periode untuk laporan laba rugi atau posisi keuangan.'];
        }
        $p = DB::table('accounting_periods')->where('organization_id', $org)->where('id', $v['period_id'])->firstOrFail();
        $s = app(FinancialBooks::class)->snapshot($org, $p);
        $rows = [];
        foreach ($s['accounts'] as $a) {
            $income = in_array($a['kind'], ['revenue', 'expense'], true);
            if (($section === 'income_statement') !== $income) {
                continue;
            }
            $amount = $section === 'income_statement' ? ($a['kind'] === 'revenue' ? -$a['income'] : $a['income']) : ($a['kind'] === 'asset' ? $a['closing'] : -$a['closing']);
            $rows[] = ['id' => $a['id'], 'organization_id' => $org, 'account' => $a, 'amount' => $amount, 'summary' => $a['code'].' '.$a['name'].' ('.$a['kind'].') · Rp'.$amount, 'forms' => []];
        }
        $t = $s['totals'];
        $text = $section === 'income_statement' ? 'Pendapatan Rp'.$t['revenue'].' · beban Rp'.$t['expense'].' · laba/rugi periode Rp'.$t['profit'] : 'Aset Rp'.$t['assets'].' = liabilitas Rp'.$t['liabilities'].' + ekuitas Rp'.$t['equity'].' + hasil belum ditutup Rp'.$t['unclosed_profit'];
        $rows[] = ['id' => 'TOTAL', 'organization_id' => $org, 'summary' => $p->name.' · '.$text, 'forms' => []];
        $pending = app(FinancialBooks::class)->pending($org, $p);

        return [...$base, 'data' => $rows, 'current_page' => 1, 'next_page_url' => null, 'totals' => $t, 'pending' => $pending, 'basis_hash' => $s['basis_hash'], 'notice' => 'Hanya jurnal posted, tanggal buku WIB. Laba rugi tidak memasukkan transfer closing/pembukaannya. '.(array_sum($pending) ? 'Ada draft/antrean/periode terdahulu terbuka: laporan sementara, belum lengkap.' : 'Tidak ada antrean sumber tertahan sampai akhir periode; rekonsiliasi dan pemeriksaan usaha tetap diperlukan.')];
    }
}
