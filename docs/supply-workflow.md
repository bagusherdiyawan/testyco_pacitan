# Panduan pesanan dan penerimaan stok

Cakupan awal PRD 04, 05-RCV/MOV dan 09. Web memiliki seluruh aksi supply awal. Flutter menampilkan pesanan/pengiriman/stok serta mendukung keputusan pesanan berizin dan penerimaan aktual melalui API yang sama. POS tunai dan shift tersedia terpisah; lihat [panduan POS](cash-pos.md).

## Persiapan

1. Jalankan `scripts/setup`; migrasi baru tidak mereset data. Jalankan `scripts/bootstrap` jika pusat belum ada, kemudian `docker compose up -d --wait`.
2. Owner pusat membuat toko gudang pada organisasi pusat dan SKU pusat pada katalog pusat. Harga grosir adalah rupiah per kemasan; isi kemasan harus sesuai produk aktual.
3. Pada workspace, buka **Pesanan dan stok** gudang. Catat penerimaan fisik dengan SKU, kode batch, kedaluwarsa, kemasan yang diterima baik dan referensi/catatan pemeriksaan.
4. Pusat mendaftarkan mitra, toko beralamat, dan pimpinan. Pimpinan menyiapkan SKU lokal toko dengan kondisi serta isi kemasan yang sama dengan SKU pusat. Harga eceran tetap milik organisasi toko.

## Pengajuan dan persetujuan

Kepala toko yang ditugaskan atau pimpinan membuka **Pesanan dan stok → Buat pengajuan**. Pilih SKU pusat, pasangan SKU lokal, jumlah kemasan, metode antar/ambil dan catatan. Tambah item bila diperlukan. Pengajuan belum terlihat di inbox pusat dan tidak mengubah stok.

Pimpinan mitra meninjau detail dan menyetujui, menolak dengan alasan, atau mengubah jumlah/item sebelum approval. Harga/konversi yang berubah sebelum approval menimbulkan 409 agar pengajuan diperbarui dan ditinjau lagi. Persetujuan tidak mengurangi stok.

Toko sendiri memakai SKU pusat yang sama, persetujuan owner/admin pusat, dan tipe transfer internal; tidak menciptakan penjualan mitra atau invoice. Edit setelah approval belum tersedia dan ditolak. Pembatalan beralasan dapat dilakukan pimpinan sebelum kiriman pertama.

## Pemrosesan pusat

Owner/admin membuka **Pesanan pusat**, memilih order yang sudah disetujui, lalu memilih gudang sumber yang aktif. Petugas warehouse yang ditugaskan pada gudang tersebut dapat membuka order setelah sumber ditentukan.

Pilih batch dan jumlah kemasan per item secara manual. Jumlah nol tidak dikirim. Periksa tanggal/kondisi sesuai SOP; sistem belum memilih FEFO atau memblokir kedaluwarsa otomatis. Kiriman parsial diperbolehkan. Konfirmasi mengurangi stok baik gudang dan menulis ledger dalam satu transaksi. Kegagalan satu item membatalkan seluruh pengiriman.

## Penerimaan toko

Penerima berwenang membuka detail order dan mengisi jumlah yang benar-benar diterima baik/rusak per baris kiriman. Tidak ada nilai baik yang otomatis diisi sama dengan jumlah kirim. Catatan wajib bila ada selisih atau kerusakan.

Contoh: dikirim 20 kemasan isi 8; baik 18, rusak 2 → 144 biji usable dan 16 biji quarantined. Order tetap partially_received. Jika hanya 18 yang dikirim dari pesanan 20 dan semuanya baik, sisa 2 dapat dikirim berikutnya; setelah seluruh 20 diterima baik, order menjadi completed.

Jika 20 sudah dikirim tetapi 2 hilang/rusak, kuota pengganti tidak bertambah otomatis. Resolusi retur/kredit/pengganti belum tersedia; catatan dan selisih dipertahankan untuk tindak lanjut. Sistem tidak membuat penerimaan fiktif atau perubahan invoice otomatis.

## Pengulangan dan status berubah

Setiap formulir mutasi mempunyai UUID operasi. Gunakan UUID yang sama untuk mengulang request identik setelah koneksi terputus. UUID sama dengan isi/tindakan/aktor berbeda ditolak. Tidak perlu membuat transaksi baru hanya untuk mencetak ulang atau mencoba request lagi. Jika versi order berubah, muat ulang dan tinjau status terkini. Jangan mengubah payload lama lalu memakai ulang UUID-nya.

## API dan Flutter

Kontrak OpenAPI 0.10.0 mencakup supply-catalog, orders, approve/reject/cancel/process, shipments, receive, inventory/batches dan inventory/ledger. Semua endpoint bisnis tetap memakai bearer token dan scope organisasi/toko.

Flutter mempunyai modul Pesanan untuk role pemohon yang sesuai dan modul Stok yang menampilkan jumlah baik/karantina. `FEATURE_ORDERING=false` dan `FEATURE_INVENTORY=false` dapat menonaktifkan modul presentasi saat build. Persetujuan/penolakan/pembatalan berizin dan penerimaan aktual kini tersedia pada Flutter; lihat [panduan native](native-ordering.md). [Pengajuan baru Flutter](native-order-entry.md) memakai tinjauan harga server dan UUID yang tetap. Perubahan item pesanan tersimpan dan pengiriman gudang masih melalui web.

## Batas berikutnya

Belum ada reservasi stok, perubahan setelah approval dengan reapproval, resolusi barang rusak/hilang, pembuktian foto/suhu terstruktur, kontrak harga khusus mitra, pengiriman notifikasi stok minimum, refund POS/QRIS, billing atau offline. PostgreSQL/concurrency dan UAT belum diverifikasi. Gunakan untuk development dan pengujian alur ini dahulu.

Pembukaan kemasan, pengukusan dan opname/susut berapproval kini tersedia pada [kontrol stok](stock-control.md). Pengiriman tetap hanya mengambil stok utuh; proses tidak membuat kuota pengganti atau perubahan tagihan.
