# Pengolahan, susut dan stok opname

Cakupan PRD 05-COOK/COUNT/MOV. Web dan Flutter mobile/tablet memiliki aksi buka/kukus, pengajuan opname/susut dan review melalui API yang sama. POS tunai kini menggunakan ledger yang sama; lihat [panduan POS](cash-pos.md). Kontrol mutu/SOP penuh belum tersedia.

## Keadaan stok per batch

| Kolom API | Makna |
| --- | --- |
| usable_pieces | Biji yang masih di kemasan utuh dan diterima baik |
| opened_pieces | Biji beku yang sudah dikeluarkan dari kemasan |
| ready_pieces | Hasil aktual yang dicatat siap jual setelah pengukusan |
| quarantined_pieces | Barang yang dicatat rusak/karantina; bukan sumber pengukusan/pengiriman biasa |
| waste_pieces | Susut kumulatif dari proses atau pengajuan susut yang disetujui |
| available_pieces | Utuh + terbuka + siap; tidak termasuk karantina/susut, bukan sertifikasi mutu atau izin jual |
| stock_revision | Revisi monoton setiap pergerakan batch untuk mendeteksi snapshot lama |

Migrasi menambah kolom dengan nilai awal nol dan tidak mengubah saldo utuh/karantina atau ledger lama. Biji tetap satuan dasar; isi kemasan memakai snapshot batch, tidak mengikuti perubahan master SKU.

## Buka dan kukus

Buka **Workspace → Pesanan dan stok → Pengolahan, susut dan stok opname**. Pimpinan/pengelola/gudang yang memiliki akses toko dapat mencatat proses; kasir tidak memiliki izin mutasi stok ini.

1. Pilih batch beku, masukkan jumlah kemasan yang benar-benar dibuka dan catatan.
2. Sistem memindahkan `jumlah kemasan × isi historis` dari utuh ke beku terbuka, tanpa menambah total biji.
3. Untuk pengukusan, masukkan input biji beku terbuka dan hasil aktual. Hasil tidak diisi otomatis atau melebihi input.
4. Selisih input–hasil masuk susut kumulatif pada transaksi pengolahan yang sama. Seluruh hasil gagal/nihil dapat dicatat dengan alasan; ini catatan hasil fisik, bukan resep atau rasio hasil baku.

Contoh: 1 kemasan isi 10 dibuka, lalu 6 biji dikukus dengan hasil 6 → utuh 0, beku terbuka 4, siap jual 6. Jika hasil aktual 5 → terbuka 4, siap 5, susut 1. Jumlah input dan output/susut harus dapat direkonsiliasi di ledger.

Tahap ini hanya memproses batch beku yang sama; tidak ada konversi resep lintas SKU. Tanggal batch tetap tanggal kedaluwarsa kemasan. Sistem belum menghitung masa simpan hasil kukus atau menyatakan produk aman otomatis; SOP hasil pilot harus menetapkannya sebelum penggunaan operasional.

## Opname dan susut terpisah

Pilih batch sebelum menghitung, tujuan `stocktake` atau `waste`, bucket dan jumlah fisik yang tersisa. Masukkan jumlah tersisa, bukan angka selisih. Untuk utuh, jumlah biji harus kelipatan isi kemasan historis. Susut terpisah hanya boleh mengurangi bucket yang dihitung; counter susut kumulatif tidak dapat diedit lewat opname.

Pengajuan menyimpan saldo sistem, jumlah fisik, selisih, revisi snapshot, pengusul dan alasan. Belum ada perubahan stok. Opname biasa dapat menambah atau mengurangi sesuai penghitungan yang disetujui; sistem tidak menyimpulkan semua selisih sebagai barang rusak/susut. Tujuan `waste` memindahkan pengurangan yang disetujui ke counter susut.

Kontrol awal menggunakan pengusul dan peninjau berbeda. Pimpinan mitra meninjau toko organisasi sendiri; owner/admin pusat meninjau lokasi pusatnya. Kepala toko/gudang dapat mengusulkan tetapi tidak menyetujui. Owner yang mengusulkan perlu admin lain; pimpinan yang mengusulkan perlu pimpinan lain. Validasi kontrol ini bersama operasional saat UAT.

Peninjau mengisi catatan lalu menyetujui atau menolak. Pengusul dapat membatalkan pengajuannya sendiri. Tidak ada penghapusan riwayat. Jika hitungan sama dengan sistem, persetujuan dicatat tanpa ledger palsu atau perubahan revisi stok.

## Snapshot berubah

Penerimaan, pengiriman, buka, kukus dan penyesuaian memperbarui `stock_revision`. Form menyertakan revisi batch yang tampil saat halaman dimuat; server menolak pengajuan/proses jika revisinya sudah berubah. Setelah pengajuan dibuat, approval juga mensyaratkan snapshot tetap berlaku.

Jika ada pergerakan, jangan memaksa saldo lama menimpa saldo baru. Muat ulang, pisahkan proses penghitungan secara operasional, hitung dan ajukan kembali; pengajuan lama dapat dibatalkan pengusul atau ditolak peninjau. Dua pengajuan dari snapshot yang sama tidak bisa sama-sama mengubah stok setelah yang pertama diterapkan.

Semua mutasi memakai UUID operasi. Replay identik dengan UUID/aktor/scope sama mengembalikan hasil awal; payload berbeda dengan UUID lama ditolak. Versi pengajuan juga wajib saat approve/reject/cancel. Jangan mengganti UUID hanya karena respons jaringan terlambat.

## Flutter mobile/tablet

Buka tab **Stok → Pengolahan dan opname**. Tombol hanya ditampilkan bagi role stok (owner/admin/partner_lead/store_manager/warehouse); API tetap memeriksa membership dan assignment. Kasir tidak mendapat mutasi stok ini. `FEATURE_INVENTORY=false` mematikan modul stok saat build. Halaman mengambil capability context dari Inventory sendiri, tanpa bergantung pada modul Ordering.

1. Pilih snapshot batch dan periksa saldo/revisi serta isi kemasan historis.
2. Pilih buka kemasan atau kukus untuk batch beku. Isi jumlah fisik dan alasan; hasil kukus harus diisi sendiri, termasuk nol bila seluruh hasil gagal.
3. Untuk opname/susut pilih keadaan yang dihitung dan isi **jumlah fisik tersisa dalam biji**, bukan angka selisih. Nilai utuh harus kelipatan isi kemasan. Karantina dapat dihitung, bukan menjadi sumber pengukusan atau penjualan.
4. Tinjau ringkasan dan konfirmasi pencatatan. Pengajuan opname/susut belum mengubah saldo. Riwayat menampilkan sistem, fisik, selisih, snapshot dan status.
5. Peninjau yang berbeda dapat menyetujui atau menolak dengan catatan. Pengusul hanya dapat membatalkan sendiri. Snapshot stale tidak mempunyai tombol setujui; penolakan/pembatalan tetap dapat mencatat alasan hitung ulang.

Setiap refresh membuang pilihan snapshot. Pilih batch lagi dan periksa/hitung kembali sebelum mengirim; angka fisik lama tidak diterapkan otomatis ke revisi baru. Konflik 409 mengunci tindakan sampai refresh berhasil. Jika refresh gagal, saldo lama tidak digunakan untuk mutasi baru. Toko nonaktif hanya menampilkan riwayat bagi petugas yang tetap berwenang.

Jika respons mutasi hilang, pertahankan halaman dan gunakan **Ulangi operasi yang sama**. Controller menyimpan path, UUID dan body secara utuh di memori; tindakan baru serta navigasi kembali ditahan selama hasil belum diketahui. Retry identik tidak menggandakan proses/pengajuan/review. Bila server sudah mengonfirmasi sukses tetapi refresh gagal, aplikasi menyatakan operasi tersimpan dan meminta muat ulang, tanpa membuat retry mutasi baru.

Ini bukan offline atau penyimpanan antrean yang tahan restart. Jika aplikasi ditutup paksa/perangkat mati/sesi berganti saat hasil belum diketahui, UUID di memori dapat hilang. Login kembali dan rekonsiliasi batch, riwayat proses/pengajuan serta audit melalui petugas berwenang sebelum mencatat ulang; jangan menganggap proses gagal hanya karena struk/hasil tidak terlihat di perangkat. Pemulihan otomatis lintas restart belum tersedia. Kebutuhan secure storage/antrean offline tetap menunggu keputusan terpisah.

Komponen atomic `StockNumberField`, `StockBatchCard` dan `StockCountCard` menangani tampilan; controller mengelola snapshot dan retry. Perhitungan ledger, izin dan validasi final berada di API. Alur diuji sebagai widget mobile/tablet, bukan build Android/iOS atau UAT perangkat fisik.

## Batas

Belum ada pembalikan pengolahan, relokasi SKU, pelepasan karantina melalui workflow mutu, rasio/ambang susut otomatis, masa simpan siap jual, push notifikasi minimum, FEFO otomatis, refund/QRIS, retur/kredit atau offline. Aksi Flutter kini tersedia bagi petugas berwenang. Pengolahan/review tetap online; tidak ada antrean operasi lokal yang persisten. PostgreSQL/concurrency multi-koneksi, backup/restore dan UAT belum diverifikasi.
