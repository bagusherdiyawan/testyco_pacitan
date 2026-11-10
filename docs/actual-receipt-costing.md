# Biaya penerimaan aktual — ADR058

Pembaruan10November2026 memperluas ADR053/054/057 untuk buyout dengan policy keuangan prospektif yang disetujui masing-masing organisasi. Penerimaan aktual tetap melalui workflow distribusi, StockLedger dan pemeriksaan fisik yang sama.

Pembeli mencatat nilai sementara dari harga snapshot order yang disetujui dikali jumlah kemasan benar-benar diterima: baik dan karantina. Barang karantina mempunyai nilai tetapi tetap tidak boleh dijual. Kekurangan kiriman tidak menambah stok atau nilai pembeli. Bukti immutable menyimpan jumlah baik/karantina/belum diterima dalam biji, nilai sementara, policy, tanggal dan actor; UUID replay tidak menggandakan stok, bukti atau jurnal. Kegagalan penyimpanan bukti membatalkan seluruh transaksi penerimaan.

Nilai nol harus terbukti dari sumber, bukan pengganti biaya unknown. Bukti nol tidak membuat jurnal nominal nol dan tetap mencegah konversi diam-diam stok milik pembeli menjadi konsinyasi. Bukti lama tidak diisi balik dengan asumsi baru.

HPP pemasok menggunakan biaya dispatch asli secara proporsional terhadap kemasan diterima (baik + karantina), dibulatkan ke bawah ke rupiah. Contoh biaya dispatch Rp100001 untuk100kemasan, diterima90: HPP Rp90000; sisa Rp10001 tetap kasus review sumber. Sisa itu tidak otomatis menjadi kerugian, klaim atau barang diterima fiktif. Sumber yang sudah direkonsiliasi manual, policy/account tidak cocok atau biaya unknown tetap review sehingga tidak terjadi HPP ganda.

Rekonsiliasi invoice membagi nilai final ke entri baik/karantina berdasarkan biji dengan konservasi pembulatan. Satu batch/invoice tetap satu penyesuaian moneter. Replay mencakup penjualan, refund dan susut berbukti; koreksi stok/HPP/beban dipisahkan tanpa menulis ulang histori. Kuantitas invoice wajib cocok dengan jumlah baik + karantina yang diterima. Invoice dispatched100 dengan penerimaan90 tetap review, bukan membagi biaya100ke90barang. Basis good_received dapat selesai bila tidak mengecualikan karantina bernilai positif; baris invoice nol tidak boleh menyembunyikan karantina.

Web/Flutter menampilkan bukti penerimaan di ringkasan antrean invoice. Pembacaan tetap keuangan organisasi sendiri. Periode tertutup, sumber yang belum cocok dan lineage tanpa bukti tetap memerlukan pemeriksaan; retry tidak mengubah sumber atau menerobos periode.

Batas tersisa: penyelesaian finansial barang hilang, valuasi barang pengganti/retur distribusi, transfer internal parsial/rusak dan physical consignor COGS belum otomatis. Aturan biaya pengganti masih memerlukan jawaban pengguna; jangan menganggap penggantian tanpa tagihan berarti biaya pemasok/pembeli tertentu. Persetujuan kasus operasional pemasok→pimpinan mitra tetap berlaku terpisah.
