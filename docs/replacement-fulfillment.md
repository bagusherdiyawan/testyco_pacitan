# Pengiriman dan penerimaan pengganti

Alur grosir API/web tersedia dari awal sampai akhir: laporkan selisih penerimaan → owner/admin pusat mengusulkan **pengganti** → pimpinan mitra mengonfirmasi → petugas sumber mengalokasikan batch dan mengirim → penerima memeriksa fisik → stok aktual dibukukan → status **Penggantian selesai diterima baik**. Buka detail pesanan, kasus selisih, kemudian **Buka pengiriman dan penerimaan pengganti** pada rencana terkonfirmasi.

Ini menuntaskan eksekusi pengganti pada API/web untuk SKU/isi kemasan asli, dari gudang sumber asli. [Layar native Flutter kasus/pengganti](native-shipment-cases.md) kini tersedia; ledger Flutter juga menampilkan/filter pergerakannya. Eksekusi retur fisik dan kredit belum dibuat. Jangan menyamakan pengganti dengan refund atau pengembalian barang rusak.

## Jumlah dan izin

Batas penggantian per baris kiriman asal adalah jumlah rusak + kurang yang sudah dicatat dan dibekukan pada kasus. Ini batas pengiriman, bukan jumlah penerimaan baik. Sisa pesanan yang belum pernah dikirim tidak termasuk. Contoh20 dikirim,17 baik,2 rusak,1 kurang memberi batas3 pengganti, bukan20. Pengiriman parsial1 lalu2 diperbolehkan, selama total seluruh alokasi/batch tidak melebihi3. Tidak ada SKU pengganti/substitusi atau perubahan isi kemasan.

Kuota hanya tersedia setelah rencana bertipe `replacement` berstatus `confirmed` sesuai D-11. Rencana retur/kredit, rencana pending/rejected/withdrawn dan transfer internal tidak dapat dipakai. Owner/admin pemasok atau gudang yang ditugaskan ke sumber dapat mengirim. Penerima memakai hak petugas penerimaan pesanan existing. Kasir, pihak lain dan gudang tanpa assignment ditolak. Keduanya harus mempunyai organisasi aktif; toko sumber dan tujuan harus aktif untuk mutasi. Riwayat toko nonaktif tetap terbaca sesuai hak, tanpa batch/action baru.

Batch harus dari gudang sumber asli, SKU sumber yang benar, konversi dan kondisi historis yang sama; hanya stok layak kirim yang dapat didebit. Tidak ada FEFO, horizon kedaluwarsa atau biaya komersial otomatis baru. Penerimaan harus mengisi semua baris tepat sekali, jumlah baik/rusak termasuk nol secara eksplisit, total tidak melebihi kiriman, dan alasan bila tidak semuanya baik.

## Pencatatan stok dan penyelesaian

Pengiriman memotong stok usable gudang melalui StockLedger dan sumber ledger `replacement_dispatch`. Penerimaan menambah stok usable mitra untuk yang baik, quarantine untuk yang rusak, sumber `replacement_receipt`; barang kurang tidak dikreditkan. Setiap perubahan menaikkan revision stok. Batch tujuan memakai batch/tanggal fisik kiriman pengganti dan metadata SKU/kemasan historis yang dibekukan, sehingga perubahan master tidak menulis ulang histori.

Barang rusak awal tetap di karantina. Alur ini tidak membuang atau mengembalikannya otomatis. Dokumen kasus, order/jumlah/harga/status/versi induk serta receipt asal dipertahankan. Kiriman pengganti memiliki ID/tabel sendiri dan tidak mengubah kuota dispatch pesanan awal. Tidak ada invoice, piutang, kas atau nota kredit baru.

| Status eksekusi | Arti |
| --- | --- |
| `ready` | Rencana sah, belum ada pengganti dikirim |
| `in_transit` | Ada kiriman pengganti belum diterima |
| `partially_fulfilled` | Sebagian diterima baik, masih ada kuota kirim |
| `fulfilled` | Seluruh batas pengganti tiap baris diterima baik |
| `requires_follow_up` | Ada kekurangan/kerusakan pada pengganti yang sudah diterima |

Status dihitung dari seluruh histori, bukan halaman yang terlihat. Jika pengganti rusak/hilang, kuota yang sudah dikirim tidak pulih otomatis; operator melihat **Perlu tindak lanjut** dan dapat memakai catatan kasus. Menambah persetujuan kuota baru, mengubah rencana terkonfirmasi atau membuat rantai pengganti tanpa batas belum diaktifkan. `fulfilled` menutup kebutuhan penerimaan barang pengganti ini; bukan klaim barang rusak awal telah diretur atau urusan keuangan selesai. Status `partially_received` pada order asli tetap menggambarkan penerimaan asli; gunakan status eksekusi pengganti untuk penyelesaiannya.

## Konsistensi dan retry

Semua operasi mengunci order→shipment asal→case→resolution→replacement, lalu batch. Pengiriman mengurutkan batch sumber; penerimaan mengurutkan kunci batch tujuan. Satu transaksi mencakup debit/kredit ledger/revision, shipment/lines, versi kasus, audit kedua organisasi dan hasil UUID. Kegagalan di alokasi berikutnya membatalkan seluruh debit sebelumnya. Audit operasional tidak masuk allowlist onboarding pusat.

Versi yang dikirim adalah **versi kasus**, bukan versi order. Catatan/usulan/keputusan dan eksekusi juga menaikkan versi kasus; review stale ditolak409. UUID identik/actor/scope/payload mengembalikan hasil tersimpan tanpa mutasi lagi, termasuk sesudah operasi lain; sesudah ACK baca context terbaru.

Web memakai HTTP ke API, CSRF, komponen atomic plan/shipment, link pagination lokal dan escaped snapshot. Form hanya mengirim field yang diizinkan; tidak menerima nominal/tujuan atau kuota dari browser. Sebelum POST, versi/capability/baris/batch diperiksa kembali. UUID/path/body/review disimpan di session per kiriman asal sebelum request. Timeout/5xx/408/429/ACK salah mempertahankan pending untuk retry identik, termasuk sesudah denial berikutnya. Pending menahan aksi pengganti baru di alur ini; GET tidak mengganti review dan retry mengabaikan browser edits.401/logout/login membersihkan session lama. Bukan antrean offline/lintas restart; retry setelah respons definitif harus meninjau ulang context.

API0.25.0 menambah GET/POST `/orders/{order}/shipments/{shipment}/discrepancy/replacements` dan POST `.../replacements/{replacement}/receive`, dengan private/no-store dan history50. Context memisahkan quota global, batch yang hanya terlihat bagi dispatcher berwenang, dan capability tiap shipment. CSV/reader ledger web dan Flutter mengenali dua sumber baru.

Migrasi `2026_11_03_000009_create_replacement_shipments.php` menambah tabel tanpa reset; rollback history terpakai ditolak. PostgreSQL concurrency, perangkat/browser interaktif dan UAT masih perlu verifikasi. Lihat [bukti uji](verification.md) dan [daftar delivery tetap](product/delivery-status.md).


Pembaruan4 November2026 (menggantikan keterangan backlog keuangan/retur/non-tunai pada irisan lama): [retur, tagihan dan penyesuaian POS](finance-and-pos-adjustments.md) kini diimplementasikan untuk DL-13/DL-15/DL-16 dengan input eksplisit, persetujuan dan verifikasi manual. D-01/D-03/D-08 belum menjadi kebijakan global; gateway, bagi hasil otomatis dan pendanaan promo lintas organisasi belum aktif. Laporan tunai tetap struk tunai sebelum pengurangan refund; histori refund/shift terpisah.
