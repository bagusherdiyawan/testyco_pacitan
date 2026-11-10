<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Manufacturing\Application\ErpForms;
use App\Shared\Domain\Proportion;
use Illuminate\Support\Facades\DB;

final class DistributionSettlementReadModel
{
    private function form(string $action, string $label, object $r, array $fields, array $fixed = []): array
    {
        return ['key' => $action.'-'.$r->id.'-'.($fixed['decision'] ?? ''), 'operation' => $action, 'label' => $label, 'fields' => $fields, 'fixed' => ['id' => $r->id, 'version' => $r->version, ...$fixed], 'subject_id' => $r->id, 'expected_id' => $r->id, 'expected_version' => $r->version + 1];
    }

    public function context(string $org): array
    {
        $jobs = DB::table('accounting_distribution_jobs')->where('organization_id', $org)->select('id', 'created_at')->selectRaw("'job' AS record_kind");
        $missing = DB::table('accounting_missing_costs')->where(fn ($q) => $q->where('owner_organization_id', $org)->orWhere('counterparty_organization_id', $org))->select('id', 'created_at')->selectRaw("'missing' AS record_kind");
        $page = DB::query()->fromSub($jobs->unionAll($missing), 's')->orderByDesc('created_at')->orderByDesc('id')->paginate(30);
        $rows = [];
        $note = ErpForms::field('note', 'Bukti pemeriksaan / alasan');
        foreach ($page->items() as $seed) {
            $forms = [];
            if ($seed->record_kind === 'job') {
                $r = DB::table('accounting_distribution_jobs')->where('organization_id', $org)->where('id', $seed->id)->firstOrFail();
                $summary = ($r->kind === 'replacement' ? 'Pembagian biaya pengganti' : 'Kepemilikan retur').' · sumber '.$r->source_id.' · status '.$r->status.' · versi'.$r->version."\n".($r->reason ?? '');
                $result = $r->result ? json_decode($r->result, true) : [];
                if (isset($result['conserved_value'])) {
                    $summary .= "\nSisa ".$result['remaining_pieces'].' biji · nilai stok tetap Rp'.$result['conserved_value'].' · HPP historis tidak diubah.';
                }
                if (isset($result['recovered_value'])) {
                    $summary .= "\nBiaya asal dipulihkan Rp".$result['recovered_value'].'; barang menjadi milik pemasok dalam karantina.';
                }
                if (isset($result['returned_value'])) {
                    $summary .= "\nNilai retur Rp".$result['returned_value'].' · kredit biaya Rp'.($result['credit_capital'] ?? 0).' · selisih beban Rp'.($result['loss_adjustment'] ?? 0);
                }

                if ($r->status === 'review_required') {
                    $forms[] = $this->form('distribution_retry', 'Antrekan ulang sesudah sumber/periode diperiksa', $r, [$note]);
                }
                if ($r->kind === 'return') {
                    $custody = DB::table('accounting_return_costs')->where('return_line_id', $r->source_id)->where(fn ($q) => $q->where('buyer_organization_id', $org)->orWhere('supplier_organization_id', $org))->first();
                    if ($custody && $custody->status !== 'released') {
                        if ($custody->buyer_organization_id === $org) {
                            $heldValue = $custody->status === 'in_transit' ? (int) $custody->buyer_value : Proportion::floor((int) $custody->buyer_value, (int) $custody->received_pieces, (int) $custody->sent_pieces);
                            $summary .= "\nNilai retur dalam perjalanan/titipan milik pembeli Rp".$heldValue.'; bagian hilang mengikuti kesepakatan terpisah.';
                        } else {
                            $summary .= "\nTitipan ".$custody->received_pieces.' biji belum masuk nilai persediaan pemasok.';
                        }
                    }
                }
                $proofs = DB::table('inventory_distribution_values')->where('organization_id', $org)->where('job_id', $r->id)->orderBy('id')->limit(101)->get();
                abort_if($proofs->count() > 100, 422, 'Bukti nilai distribusi melampaui100batch.');
                foreach ($proofs as $proof) {
                    $summary .= "\nBatch ".$proof->inventory_batch_id.' · jumlah milik '.$proof->before_quantity.' → '.$proof->after_quantity.' biji · nilai Rp'.$proof->before_value.' → Rp'.$proof->after_value.' · revisi '.$proof->stock_revision;
                }
                $data = ['kind' => $r->kind, 'source_id' => $r->source_id, 'event_on' => $r->event_on, 'result' => (object) $result, 'value_proofs' => $proofs->map(fn ($p) => (array) $p)->all()];
            } else {
                $r = DB::table('accounting_missing_costs')->where(fn ($q) => $q->where('owner_organization_id', $org)->orWhere('counterparty_organization_id', $org))->where('id', $seed->id)->firstOrFail();
                $order = DB::table('supply_orders')->where('id', $r->order_id)->firstOrFail();
                $owner = DB::table('organizations')->where('id', $r->owner_organization_id)->value('name');
                $summary = 'Biaya hilang · order '.$r->order_id.' · pemilik saat kirim '.$owner.' · belum diterima '.($r->sent_pieces - $r->received_pieces).' biji · sisa Rp'.$r->missing_value.' · status '.$r->status.' · versi'.$r->version;
                if ($r->owner_share_basis_points !== null) {
                    $summary .= "\nPorsi pemilik ".$r->owner_share_basis_points.'/10000; persetujuan pusat → pimpinan mitra. Klaim adalah utang/piutang, bukan bukti pembayaran.';
                }
                $summary .= "\n".($r->note ?? '')."\n".($r->decision_note ?? '');
                $hasCase = DB::table('shipment_discrepancies')->where('supply_order_id', $r->order_id)->where('shipment_id', $r->shipment_id)->exists();
                if ($org === $order->supplier_organization_id && $hasCase && in_array($r->status, ['awaiting_agreement', 'rejected'], true)) {
                    $forms[] = $this->form('missing_propose', 'Usulkan pembagian biaya hilang', $r, [ErpForms::field('owner_share_basis_points', 'Porsi ditanggung '.$owner.' (0–10000 basis point)', 'integer'), $note]);
                }
                if ($org === $order->organization_id && $r->status === 'proposed') {
                    foreach (['approve' => 'Setujui pembagian biaya hilang', 'reject' => 'Tolak pembagian biaya hilang'] as $decision => $label) {
                        $forms[] = $this->form('missing_decide', $label, $r, [ErpForms::field('consent', 'Saya memeriksa porsi dan nominal klaim dua pihak', 'consent'), $note], ['decision' => $decision]);
                    }
                }
                $data = ['kind' => 'missing', 'missing_value' => (int) $r->missing_value, 'owner_share_basis_points' => $r->owner_share_basis_points, 'event_on' => $r->event_on];
            }
            $rows[] = ['id' => $r->id, 'organization_id' => $org, 'status' => $r->status, 'version' => $r->version, 'summary' => $summary, 'forms' => $forms, ...$data];
        }

        return [...$page->toArray(), 'data' => $rows, 'forms' => [], 'filter_stores' => [], 'filter_materials' => [], 'filter_periods' => [], 'notice' => 'Biaya pengganti memakai sisa nilai stok pembeli; HPP historis tetap. Retur masih titipan sampai fisik dan kredit tercakup/disetujui. Biaya hilang wajib kesepakatan pusat dan pimpinan mitra. Tidak mengirim dana. Kasus unknown/cutover/lineage tidak terbukti tetap review.'];
    }
}
