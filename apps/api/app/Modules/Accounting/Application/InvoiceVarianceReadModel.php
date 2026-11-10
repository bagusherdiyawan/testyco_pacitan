<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Manufacturing\Application\ErpForms;
use Illuminate\Support\Facades\DB;

final class InvoiceVarianceReadModel
{
    public function context(string $org): array
    {
        $page = DB::table('accounting_invoice_revaluations')->where('organization_id', $org)->orderByDesc('id')->paginate(30);
        $rows = [];
        foreach ($page->items() as $object) {
            $r = (array) $object;
            $forms = [];
            if ($r['status'] === 'review_required') {
                $forms[] = ['key' => 'invoice_revalue_retry-'.$r['id'], 'operation' => 'invoice_revalue_retry', 'label' => 'Antrekan ulang setelah sumber/periode diperiksa', 'fields' => [ErpForms::field('note', 'Bukti pemeriksaan dan alasan mengantrekan ulang')], 'fixed' => ['id' => $r['id'], 'version' => $r['version']], 'subject_id' => $r['id'], 'expected_id' => $r['id'], 'expected_version' => $r['version'] + 1];
            }
            $details = DB::table('inventory_invoice_variances')->where('organization_id', $org)->where('revaluation_id', $r['id'])->orderBy('id')->limit(101)->get();
            abort_if($details->count() > 100, 422);
            $text = 'Invoice: '.$r['invoice_id'].' · status: '.$r['status'].' · versi'.$r['version']."\nTanggal: ".$r['event_on'].($r['status'] === 'applied' ? ' · penyesuaian stok Rp'.$r['stock_adjustment'].' · HPP Rp'.$r['cogs_adjustment'].' · susut Rp'.$r['loss_adjustment'] : ' · belum diterapkan; alokasi stok/HPP belum disahkan')."\n".($r['reason'] ?? '');
            foreach ($details as $d) {
                $text .= "\nBatch ".$d->inventory_batch_id.' · koreksi '.$d->id.' · awal Rp'.$d->original_value.' · final Rp'.$d->final_value.' · sisa '.$d->remaining_quantity.' biji · stok Rp'.$d->stock_adjustment.' · HPP Rp'.$d->cogs_adjustment.' · susut Rp'.$d->loss_adjustment;
            }
            $rows[] = [...$r, 'summary' => $text, 'forms' => $forms];
        }

        return [...$page->toArray(), 'data' => $rows, 'forms' => [], 'filter_stores' => [], 'filter_materials' => [], 'filter_periods' => [], 'notice' => 'Applied berarti biaya stok/HPP sudah disesuaikan; periksa Antrean jurnal sumber untuk status GL. Histori struk/biaya asli tidak ditimpa. Periode tertutup, transfer atau sumber campuran memerlukan review.'];
    }
}
