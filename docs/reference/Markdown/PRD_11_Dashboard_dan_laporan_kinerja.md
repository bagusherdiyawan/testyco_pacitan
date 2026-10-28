# PRD 11 Dashboard dan laporan kinerja

Dashboard pusat mitra dan toko | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Menyajikan angka yang konsisten dengan batas akses dan definisi yang jelas.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 11-CTR  Ringkasan pusat

Kebutuhan: Tampilkan penjualan pusat ke mitra, penjualan toko sendiri, stok, order terbuka, mitra aktif dan piutang sebagai metrik terpisah.

Kriteria penerimaan: Transaksi transfer internal tidak muncul sebagai penjualan pusat kepada mitra.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 11-PART  Ringkasan mitra

Kebutuhan: Pimpinan dapat memfilter toko, periode, produk dan status serta membandingkan toko yang berada dalam organisasi miliknya.

Kriteria penerimaan: Mitra A tidak dapat melihat laporan Mitra B melalui filter maupun tautan langsung.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 11-SELL  Pelaporan POS

Kebutuhan: Penjualan pembeli di toko mitra ditampilkan terpisah dari pendapatan pusat; cakupan data yang terlihat pusat mengikuti perjanjian.

Kriteria penerimaan: Dashboard tidak menjumlahkan penjualan grosir dan eceran menjadi omzet tunggal tanpa label.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 11-KPI  Definisi indikator

Kebutuhan: Nilai bersih = penjualan selesai dikurangi diskon dan refund sesuai periode laporan; stok rendah memakai ambang per SKU/lokasi; mitra aktif berdasarkan definisi yang disepakati.

Kriteria penerimaan: Angka pada rekap dapat ditelusuri ke transaksi sumber; tanggal dan zona waktu filter jelas.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 11-EXP  Ekspor dan kesegaran data

Kebutuhan: Ekspor laporan sesuai izin; tampilkan waktu pembaruan terakhir dan penanda transaksi offline belum sinkron bila tersedia.

Kriteria penerimaan: Data tidak sinkron tidak disajikan sebagai data terkini tanpa penanda; ekspor mengikuti batas organisasi.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Bergantung pada order, POS, stok, tagihan dan kontrak data; target kinerja dan definisi mitra aktif masih terbuka.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
