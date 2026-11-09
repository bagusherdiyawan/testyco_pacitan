<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Manufacturing\Application\ErpForms as F;
use Illuminate\Support\Facades\DB;

final class PostingReadModel
{
    public function context(string $org, string $section, int $actor): array
    {
        $field = F::field(...);
        $note = $field('note', 'Bukti dan alasan pemeriksaan');
        $make = fn ($op, $label, $fields, $fixed = [], $id = null, $version = null) => ['key' => $op.'-'.($id ?? '').'-'.($fixed['decision'] ?? ''), 'operation' => $op, 'label' => $label, 'fields' => $fields, 'fixed' => $fixed, 'subject_id' => $id, 'expected_id' => $id, 'expected_version' => $version];
        $forms = [];
        $manual = collect();
        if ($section === 'posting_events') {
            $manual = DB::table('accounting_journals')->where('organization_id', $org)->where('status', 'posted')->whereNull('reversed_by')->whereNull('reversal_of')->where('created_by', '!=', $actor)->whereNotIn('id', DB::table('accounting_events')->where('organization_id', $org)->whereNotNull('journal_id')->select('journal_id'))->orderByDesc('id')->limit(1001)->get();
            abort_if($manual->count() > 1000, 422, 'Pilihan jurnal rekonsiliasi melebihi1000.');
        }
        if ($section === 'posting') {
            $accounts = DB::table('accounting_accounts')->where('organization_id', $org)->where('active', true)->limit(1001)->get();
            abort_if($accounts->count() > 1000, 422);
            $fields = [];
            foreach (AutomaticPosting::ROLES as $role => [$label,$kind,$normal]) {
                $choices = $accounts->filter(fn ($a) => $a->kind === $kind && $a->normal_balance === $normal)->mapWithKeys(fn ($a) => [$a->id => $a->code.' · '.$a->name])->all();
                $fields[] = $field($role.'_account', $label, 'choice', $choices);
            }
            $forms[] = $make('posting_policy', 'Ajukan pemetaan jurnal otomatis', [...$fields, $note]);
        }
        $table = match ($section) {
            'posting' => 'accounting_policies','posting_events' => 'accounting_events','cost_ledger' => 'inventory_cost_entries',default => 'accounting_value_proposals'
        };
        $page = DB::table($table)->where('organization_id', $org)->orderByDesc('id')->paginate(30);
        $rows = [];
        foreach ($page->items() as $object) {
            $r = (array) $object;
            $actions = [];
            $text = [];
            foreach (['status' => 'Status', 'version' => 'Versi', 'source_type' => 'Sumber', 'source_id' => 'Referensi sumber', 'event_on' => 'Tanggal buku WIB', 'journal_id' => 'Jurnal', 'reason' => 'Perlu diperiksa', 'quantity' => 'Jumlah biji', 'amount' => 'Nilai Rp', 'note' => 'Bukti', 'inventory_batch_id' => 'Batch', 'stock_revision' => 'Revisi stok', 'quantity_delta' => 'Perubahan biji', 'value_delta' => 'Perubahan nilai Rp', 'origin_id' => 'Alokasi penjualan asal'] as $key => $label) {
                if (isset($r[$key])) {
                    $text[] = $label.': '.$r[$key];
                }
            }
            if ($section === 'posting') {
                foreach (json_decode($r['mapping'], true) as $role => $a) {
                    $text[] = AutomaticPosting::ROLES[$role][0].': '.$a['code'].' '.$a['name'];
                }
            }
            if ($section === 'posting_events') {
                $text[] = 'Pemetaan: '.$r['policy_id'];
                foreach (json_decode($r['terms'], true) as [$d,$c,$amount]) {
                    $text[] = 'Debit '.AutomaticPosting::ROLES[$d][0].' / kredit '.AutomaticPosting::ROLES[$c][0].' · Rp'.($amount ?? 'belum diketahui');
                }
            }
            if ($section === 'cost_ledger' && $r['value_delta'] === null) {
                $text[] = 'Nilai belum diketahui; bukan nol.';
            }
            $fixed = ['id' => $r['id'], 'version' => $r['version'] ?? 1];
            if (in_array($section, ['posting', 'valuations'], true) && $r['status'] === 'draft' && $r['created_by'] !== $actor) {
                foreach (['approve' => 'Setujui', 'reject' => 'Tolak'] as $d => $label) {
                    $actions[] = $make($section === 'posting' ? 'posting_decide' : 'value_decide', $label, [$note], [...$fixed, 'decision' => $d], $r['id'], $r['version'] + 1);
                }
            }
            if ($section === 'posting' && $r['status'] === 'active') {
                $actions[] = $make('posting_suspend', 'Hentikan tangkapan transaksi baru', [$note], $fixed, $r['id'], $r['version'] + 1);
            }
            if ($section === 'posting_events' && $r['status'] === 'review_required' && str_starts_with($r['reason'] ?? '', 'Tanggal sumber')) {
                $actions[] = $make('posting_retry', 'Antrekan ulang sesudah periode diperbaiki', [$note], $fixed, $r['id'], $r['version'] + 1);
            }
            if ($section === 'posting_events' && $r['status'] === 'review_required') {
                $choices = $manual->where('posted_on', $r['event_on'])->mapWithKeys(fn ($j) => [$j->id => $j->number.' · '.$j->posted_on.' · Rp'.$j->total_amount])->all();
                if ($choices) {
                    $actions[] = $make('posting_reconcile', 'Tautkan jurnal rekonsiliasi yang sudah ditinjau', [$field('journal_id', 'Jurnal manual pada tanggal sumber', 'choice', $choices), $note], $fixed, $r['id'], $r['version'] + 1);
                }
            }
            $rows[] = [...$r, 'summary' => implode("\n", $text), 'forms' => $actions];
        }
        if ($section === 'valuation_stock') {
            $batches = InventoryBatch::where('organization_id', $org)->where(fn ($q) => $q->where('usable_pieces', '>', 0)->orWhere('opened_pieces', '>', 0)->orWhere('ready_pieces', '>', 0)->orWhere('quarantined_pieces', '>', 0))->orderBy('id')->paginate(30);
            $rows = [];
            foreach ($batches as $b) {
                $pool = DB::table('inventory_values')->where('inventory_batch_id', $b->id)->first();
                $qty = app(InventoryValuation::class)->quantity($b);
                $actions = [];
                if (! $pool || $pool->remaining_value === null) {
                    $actions[] = $make('value_propose', 'Ajukan nilai saldo awal batch', [$field('amount', 'Nilai total stok tersisa (Rp)', 'integer'), $note], ['batch_id' => $b->id, 'stock_revision' => $b->stock_revision]);
                }
                $rows[] = ['id' => $b->id, 'organization_id' => $org, 'summary' => $b->name.' · '.$b->batch_code.' · '.$qty.'biji · nilai tersisa Rp'.($pool?->remaining_value ?? 'belum diketahui').' · revisi'.$b->stock_revision, 'forms' => $actions];
            }
            $page = $batches;
        }
        $pending = DB::table('accounting_events')->where('organization_id', $org)->whereNotIn('status', ['posted', 'reconciled'])->count();

        return [...$page->toArray(), 'data' => $rows, 'forms' => $forms, 'filter_stores' => [], 'filter_materials' => [], 'filter_periods' => [],
            'notice' => 'Otomatisasi berlaku untuk transaksi baru setelah pemetaan disetujui pengelola lain. Persediaan mencakup karantina. Tidak mengimpor histori. Antrean belum posted: '.$pending.'. Nilai tidak diketahui dan transaksi distribusi/konsinyasi/promo sponsor menunggu rekonsiliasi; tidak diasumsikan nol.'];
    }
}
