<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Manufacturing\Application\ErpForms;
use Illuminate\Support\Facades\DB;

final class ConsignorReadModel
{
    private function form(string $action, string $label, array $fixed, ?string $id = null, ?int $version = null): array
    {
        return ['key' => $action.'-'.($id ?? $fixed['agreement_id'] ?? ''), 'operation' => $action, 'label' => $label, 'fields' => [ErpForms::field('note', 'Bukti pemeriksaan dan alasan')], 'fixed' => $fixed, 'subject_id' => $id, 'expected_id' => $id, 'expected_version' => $version];
    }

    public function context(string $org, string $section): array
    {
        $base = ['forms' => [], 'filter_stores' => [], 'filter_materials' => [], 'filter_periods' => [], 'notice' => 'Stok tetap milik pemasok. HPP aktual terpisah dari biaya formula bagi hasil. Nilai/unit cost hanya untuk pemilik; mitra melihat jumlah custody dan nilai aset sendiri nol. Periksa antrean jurnal sumber untuk status posted. Unknown bukan biaya nol; tidak mengimpor penjualan sebelum aktivasi.'];
        if ($section === 'consignor_ledger') {
            $page = DB::table('consignor_cost_entries')->where('organization_id', $org)->orderByDesc('id')->paginate(30);
            $rows = [];
            foreach ($page->items() as $r) {
                $rows[] = ['id' => $r->id, 'organization_id' => $org, 'summary' => $r->source_type.' · sumber '.$r->source_id.' · batch titipan '.$r->inventory_batch_id.' · perubahan '.$r->quantity_delta.' biji · nilai Rp'.($r->value_delta ?? 'belum diketahui').' · tanggal '.($r->event_on ?? 'menunggu sumber transaksi'), 'forms' => [], 'quantity_delta' => (int) $r->quantity_delta, 'value_delta' => $r->value_delta === null ? null : (int) $r->value_delta];
            }

            return [...$base, ...$page->toArray(), 'data' => $rows];
        }
        $page = DB::table('consignor_valuations')->where(fn ($q) => $q->where('organization_id', $org)->orWhere('partner_id', $org))->orderByDesc('id')->paginate(30);
        $rows = [];
        foreach ($page->items() as $r) {
            $pools = DB::table('consignor_pools')->where('valuation_id', $r->id);
            $lots = DB::table('consignor_lots')->where('valuation_id', $r->id);
            $returns = DB::table('consignor_returns')->where('valuation_id', $r->id);
            $held = (int) (clone $pools)->sum('quantity');
            $transit = (int) (clone $lots)->sum(DB::raw('quantity-received_quantity-settled_missing_quantity')) + (int) (clone $returns)->sum(DB::raw('quantity-received_quantity-settled_missing_quantity'));
            $summary = 'Perjanjian '.$r->agreement_id.' · order '.$r->order_id.' · status '.$r->status.' · titipan '.$held.' biji · perjalanan/selisih '.$transit.' biji';
            $forms = [];
            $fields = ['held_quantity' => $held, 'transit_quantity' => $transit, 'status' => $r->status, 'version' => $r->version, 'order_id' => $r->order_id];
            if ($org === $r->organization_id) {
                $unknown = (clone $pools)->whereNull('remaining_value')->where('quantity', '>', 0)->exists() || (clone $lots)->whereNull('value')->whereRaw('quantity>received_quantity+settled_missing_quantity')->exists() || (clone $returns)->whereNull('value')->whereRaw('quantity>received_quantity+settled_missing_quantity')->exists();
                $heldValue = (int) (clone $pools)->sum('remaining_value');
                $transitValue = (int) (clone $lots)->sum(DB::raw('value-received_value-settled_missing_value')) + (int) (clone $returns)->sum(DB::raw('value-received_value-settled_missing_value'));
                $dispositions = DB::table('consignor_dispositions as d')->join('consignor_cost_entries as e', 'e.id', '=', 'd.entry_id')->join('consignor_pools as p', 'p.id', '=', 'e.pool_id')->where('p.valuation_id', $r->id)->where('d.status', 'review_required')->limit(101)->get(['d.*']);
                abort_if($dispositions->count() > 100, 422, 'Selesaikan susut konsinyasi lebih dahulu; batas100kasus.');
                $pending = $dispositions->contains(fn ($d) => $d->value === null) ? null : (int) $dispositions->sum('value');
                $fields += ['held_value' => $unknown ? null : $heldValue, 'transit_value' => $unknown ? null : $transitValue, 'pending_disposition_value' => $pending];
                $summary .= "\nNilai titipan Rp".($unknown ? 'belum diketahui' : $heldValue).' · perjalanan/selisih Rp'.($unknown ? 'belum diketahui' : $transitValue).' · susut menunggu pemeriksaan Rp'.($pending ?? 'belum diketahui');
                if ($r->reason) {
                    $summary .= "\n".$r->reason;
                }
                if ($r->status === 'review_required') {
                    $forms[] = $this->form('consignor_retry', 'Periksa ulang aktivasi setelah sumber dibenahi', ['id' => $r->id, 'version' => $r->version], $r->id, $r->version + 1);
                }
                foreach ($dispositions as $d) {
                    $summary .= "\nSusut ".$d->id.' · '.$d->quantity.' biji · biaya Rp'.($d->value ?? 'belum diketahui');
                    if ($d->value !== null) {
                        $forms[] = $this->form('consignor_dispose', 'Pemilik menyetujui beban susut '.$d->id, ['id' => $d->id, 'version' => $d->version], $d->id, $d->version + 1);
                    }
                }
            } else {
                $summary .= "\nBarang milik pemasok; nilai persediaan milik mitra Rp0. HPP aktual pemasok tidak dibuka dalam buku mitra.";
                $fields['owned_value'] = 0;
            }
            $rows[] = ['id' => $r->id, 'organization_id' => $org, 'summary' => $summary, 'forms' => $forms, ...$fields];
        }
        $options = DB::table('commercial_agreements as g')->where('g.organization_id', $org)->where('g.kind', 'consignment')->where('g.status', 'accepted')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('consignor_valuations as v')->whereColumn('v.agreement_id', 'g.id'))->orderByDesc('g.id')->limit(101)->get(['g.id', 'g.supply_order_id']);
        if ($options->isNotEmpty()) {
            abort_if($options->count() > 100, 422, 'Pilihan aktivasi melebihi100perjanjian.');
            $base['forms'][] = ['key' => 'consignor_enable-', 'operation' => 'consignor_enable', 'label' => 'Aktifkan valuasi fisik konsinyasi sebelum penjualan pertama', 'fields' => [ErpForms::field('agreement_id', 'Perjanjian disetujui kedua pihak', 'choice', $options->mapWithKeys(fn ($g) => [$g->id => $g->id.' · order '.$g->supply_order_id])->all()), ErpForms::field('note', 'Bukti biaya dispatch dan pemeriksaan ownership')], 'fixed' => [], 'subject_id' => null, 'expected_id' => null, 'expected_version' => null];
        }

        return [...$base, ...$page->toArray(), 'data' => $rows];
    }
}
