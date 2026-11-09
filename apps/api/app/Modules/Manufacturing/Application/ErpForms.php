<?php

namespace App\Modules\Manufacturing\Application;

use App\Modules\Materials\Domain\Quantity;

final class ErpForms
{
    public static function field(string $key, string $label, string $kind = 'text', array $options = [], bool $optional = false): array
    {
        return compact('key', 'label', 'kind', 'options', 'optional');
    }

    private static function form(string $operation, string $label, array $fields, array $fixed = [], ?array $row = null): array
    {
        return ['key' => $operation.($row ? '-'.$row['id'] : '').'-'.($fixed['decision'] ?? $fixed['source'] ?? ''), 'operation' => $operation, 'label' => $label, 'fields' => $fields, 'fixed' => $fixed, 'subject_id' => $row['id'] ?? null, 'expected_id' => in_array($operation, ['receive', 'transfer', 'count'], true) ? null : ($row['id'] ?? null), 'expected_version' => in_array($operation, ['receive', 'transfer', 'count'], true) ? 1 : ($row ? ($row['version'] ?? $row['stock_revision']) + 1 : null)];
    }

    public static function create(string $section, array $options): array
    {
        $f = self::field(...);
        $note = $f('note', 'Catatan / bukti pemeriksaan');
        $store = $f('store_id', 'Gudang', 'choice', $options['stores']);

        return match ($section) {
            'materials' => [self::form('material', 'Daftarkan bahan/kemasan', [$f('code', 'Kode bahan'), $f('name', 'Nama bahan'), $f('unit', 'Satuan pencatatan', 'choice', ['kg' => 'Kilogram', 'g' => 'Gram', 'l' => 'Liter', 'ml' => 'Mililiter', 'pcs' => 'Butir / pcs']), $f('minimum', 'Batas minimum (isi nol bila sesuai)'), $f('expiry_required', 'Wajib tanggal kedaluwarsa', 'choice', ['1' => 'Ya', '0' => 'Tidak']), $f('fefo_required', 'Wajib gunakan lot layak dengan kedaluwarsa terdekat dahulu', 'choice', ['1' => 'Ya', '0' => 'Tidak'])])],
            'suppliers' => [self::form('supplier', 'Daftarkan pemasok', [$f('name', 'Nama pemasok'), $f('contact', 'Kontak'), $f('address', 'Alamat')])],
            'purchases' => [self::form('purchase', 'Buat pembelian', [$store, $f('supplier_id', 'Pemasok', 'choice', $options['suppliers']), $f('number', 'Nomor pembelian'), $f('ordered_on', 'Tanggal pembelian', 'date'), $f('due_on', 'Jatuh tempo', 'date'), $f('quantities', 'Jumlah dibeli sesuai satuan bahan; kosong = tidak dibeli', 'quantity_map', $options['materials']), $f('amounts', 'Total harga per bahan (Rp), isi nol bila sesuai', 'money_map', $options['materials']), $note])],
            'lots' => [self::form('receive', 'Saldo awal bahan (bukan pembelian)', [$store, $f('material_id', 'Bahan', 'choice', $options['materials']), $f('quantity', 'Jumlah sesuai satuan bahan'), $f('opening_value', 'Nilai saldo awal (Rp)', 'integer'), $f('batch_code', 'Kode lot'), $f('received_on', 'Tanggal penerimaan', 'date'), $f('expires_on', 'Kedaluwarsa; kosong hanya untuk bahan tanpa kewajiban kedaluwarsa', 'date', [], true), $f('evidence', 'Bukti saldo awal'), $note], ['source' => 'opening'])],
            'recipes' => [self::form('recipe', 'Buat versi resep produksi', [$f('product_id', 'Produk jadi (pilih salah satu jenis hasil)', 'choice', $options['products'], true), $f('output_material_id', 'Bahan setengah jadi (pilih jika bukan produk jadi)', 'choice', $options['materials'], true), $f('output_quantity', 'Target hasil setengah jadi sesuai satuannya', 'text', [], true), $f('title', 'Nama / versi resep'), $f('output_packs', 'Target kemasan untuk produk jadi', 'integer', [], true), $f('ingredients', 'Bahan per proses dalam satuan yang tercantum; kosong = tidak digunakan', 'quantity_map', $options['materials']), $f('instructions', 'Instruksi produksi')])],
            'productions' => [self::form('production', 'Rencanakan produksi', [$store, $f('recipe_id', 'Resep dan versi', 'choice', $options['recipes']), $f('number', 'Nomor produksi'), $f('runs', 'Jumlah proses resep (bilangan bulat)', 'integer'), $note], ['recipe_versions' => $options['recipe_versions']])],
            default => [],
        };
    }

    public static function row(string $section, array $row, array $options, int $actor): array
    {
        $f = self::field(...);
        $note = $f('note', 'Catatan / bukti pemeriksaan');
        $fixed = ['id' => $row['id'], 'version' => $row['version'] ?? $row['stock_revision']];
        $forms = [];
        $add = function (string $op, string $label, array $fields, array $extra = []) use (&$forms, $fixed, $row) {
            $forms[] = self::form($op, $label, $fields, [...$fixed, ...$extra], $row);
        };
        if ($section === 'materials') {
            $add('material_configure', 'Atur bahan dan batas minimum', [$f('name', 'Nama bahan'), $f('minimum', 'Batas minimum sesuai satuan bahan'), $f('expiry_required', 'Wajib tanggal kedaluwarsa', 'choice', ['1' => 'Ya', '0' => 'Tidak']), $f('fefo_required', 'Wajib FEFO saat produksi', 'choice', ['1' => 'Ya', '0' => 'Tidak']), $f('active', 'Aktif untuk transaksi baru', 'choice', ['1' => 'Ya', '0' => 'Tidak'])]);
        }
        if ($section === 'purchases') {
            if ($row['status'] === 'draft') {
                $add('purchase_decide', 'Setujui pembelian', [$note], ['decision' => 'approve']);
            }
            if (in_array($row['status'], ['draft', 'approved'], true) && $row['received_amount'] === 0) {
                $add('purchase_decide', 'Batalkan pembelian', [$note], ['decision' => 'cancel']);
            }
            if ($row['status'] === 'approved') {
                $lines = [];
                foreach ($row['lines'] as $id => $l) {
                    if ($l['received_quantity'] < $l['quantity']) {
                        $lines[$id] = $l['name'].' ('.$l['unit'].') sisa '.Quantity::display($l['quantity'] - $l['received_quantity'], $l['unit']);
                    }
                }
                if ($lines) {
                    $forms[] = self::form('receive', 'Terima bahan pembelian', [$f('material_id', 'Bahan pembelian', 'choice', $lines), $f('quantity', 'Jumlah diterima sesuai satuan bahan'), $f('batch_code', 'Kode lot'), $f('received_on', 'Tanggal penerimaan', 'date'), $f('expires_on', 'Kedaluwarsa (wajib sesuai bahan)', 'date', [], true), $f('evidence', 'Bukti penerimaan'), $note], ['purchase_id' => $row['id'], 'version' => $row['version'], 'source' => 'purchase', 'store_id' => $row['store_id']], $row);
                }
                if ($row['received_amount'] - $row['credited_amount'] - $row['paid_amount'] + $row['refunded_amount'] != 0) {
                    $add('payment', 'Catat pembayaran supplier', [$f('kind', 'Arah transaksi terverifikasi', 'choice', ['payment' => 'Pembayaran ke supplier', 'supplier_refund' => 'Pengembalian dana dari supplier']), $f('amount', 'Nominal (Rp)', 'integer'), $f('method', 'Metode', 'choice', ['cash' => 'Tunai', 'bank_transfer' => 'Transfer manual']), $f('reference', 'Referensi bukti'), $f('paid_on', 'Tanggal dibayar', 'date'), $f('verified', 'Saya sudah memverifikasi bukti', 'consent'), $note]);
                }
            }
        }
        if ($section === 'purchases' && $row['status'] === 'approved') {
            foreach ($row['payments'] as $payment) {
                if (! isset($payment['reversed_at']) && isset($payment['actor_id']) && $payment['actor_id'] !== $actor) {
                    $forms[] = self::form('payment_reverse', 'Balik pembayaran '.$payment['reference'], [$f('verified', 'Bukti kesalahan dan koreksi sudah diperiksa', 'consent'), $note], [...$fixed, 'payment_id' => $payment['id']], $row);
                }
            }
        }
        if ($section === 'lots') {
            $unit = $row['unit'];
            if ($row['quarantined_quantity'] > 0) {
                foreach (['release' => 'Lolos pemeriksaan bahan', 'reject' => 'Tolak / catat bahan rusak'] as $action => $label) {
                    $add('material_quality', $label, [$f('quantity', 'Jumlah ('.$unit.')'), $note], ['decision' => $action]);
                }
            }
            $add('material_quality', $row['blocked'] ? 'Lepas blok bahan' : 'Blok bahan', [$note], ['decision' => $row['blocked'] ? 'unhold' : 'hold']);
            if ($row['purchase_id'] && $row['usable_quantity'] + $row['quarantined_quantity'] > 0) {
                $add('material_quality', 'Retur bahan ke supplier', [$f('bucket', 'Asal stok', 'choice', ['usable' => 'Lolos pemeriksaan', 'quarantined' => 'Karantina']), $f('quantity', 'Jumlah retur ('.$unit.')'), $f('supplier_credit', 'Pengurangan utang yang sudah disetujui supplier (Rp, boleh nol)', 'integer'), $f('verified', 'Saya sudah memverifikasi bukti retur dan kredit supplier', 'consent'), $note], ['decision' => 'return']);
            }
            if ($row['usable_quantity'] > 0 && ! $row['blocked']) {
                $add('transfer', 'Pindahkan bahan antar gudang', [$f('store_id', 'Gudang penerima', 'choice', $options['stores']), $f('quantity', 'Jumlah dipindahkan ('.$unit.')'), $note]);
            }
            $add('count', 'Ajukan stok opname bahan', [$f('bucket', 'Bagian stok dihitung', 'choice', ['usable' => 'Lolos pemeriksaan', 'quarantined' => 'Karantina']), $f('quantity', 'Jumlah fisik ('.$unit.')'), $f('adjustment_value', 'Nilai koreksi absolut (Rp, isi nol jika tidak berubah)', 'integer'), $note]);
        }
        if ($section === 'counts' && $row['status'] === 'pending' && $row['created_by'] !== $actor) {
            foreach (['approve' => 'Setujui opname', 'reject' => 'Tolak opname'] as $action => $label) {
                $add('count_decide', $label, [$note], ['decision' => $action]);
            }
        }
        if ($section === 'productions') {
            if ($row['status'] === 'planned') {
                $lots = [];
                $revisions = [];
                foreach ($options['lot_rows'] as $lot) {
                    if ($lot['store_id'] === $row['store_id'] && isset($row['recipe_snapshot']['ingredients'][$lot['material_id']]) && $lot['usable_quantity'] > 0 && ! $lot['blocked']) {
                        $lots[$lot['id']] = $lot['material_name'].' · '.$lot['batch_code'].' ('.$lot['unit'].') tersedia '.Quantity::display($lot['usable_quantity'], $lot['unit']).' · kedaluwarsa '.($lot['expires_on'] ?? 'tidak diwajibkan');
                        $revisions[$lot['id']] = $lot['stock_revision'];
                    }
                }
                $add('complete', 'Catat hasil dan pemakaian aktual', [$f('allocations', 'Pemakaian aktual per lot; kosong = tidak dipakai', 'quantity_map', $lots), $f('batch_code', 'Kode batch hasil baru'), $f('produced_on', 'Tanggal produksi', 'date'), $f('expires_on', 'Kedaluwarsa hasil sesuai SOP yang disetujui', 'date'), ...($row['recipe_snapshot']['output_kind'] === 'material' ? [$f('good_quantity', 'Hasil baik sesuai satuan bahan'), $f('failed_quantity', 'Hasil gagal sesuai satuan bahan (boleh nol)')] : [$f('good_packs', 'Hasil baik (kemasan, boleh nol)', 'integer'), $f('failed_pieces', 'Hasil gagal (biji, boleh nol)', 'integer')]), $f('labor_cost', 'Biaya tenaga kerja (Rp, isi nol bila sesuai)', 'integer'), $f('overhead_cost', 'Biaya overhead (Rp, isi nol bila sesuai)', 'integer'), $f('cost_allocation', 'Dasar alokasi biaya yang disetujui', 'choice', ['all_to_good_output' => 'Seluruh biaya proses dibebankan ke hasil baik; jika nihil menjadi biaya gagal']), $f('cost_evidence', 'Bukti biaya dan dasar alokasi'), $note], ['allocation_revisions' => $revisions]);
                $add('production_cancel', 'Batalkan rencana', [$note]);
            }
            if ($row['status'] === 'awaiting_quality') {
                foreach (['release' => 'Loloskan hasil produksi', 'reject' => 'Tolak hasil produksi'] as $action => $label) {
                    $add('production_quality', $label, [$note], ['decision' => $action, 'stock_revision' => $row['output_stock_revision']]);
                }
            }
        }

        return $forms;
    }
}
