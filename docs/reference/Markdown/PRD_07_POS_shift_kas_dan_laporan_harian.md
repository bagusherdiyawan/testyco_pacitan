# PRD 07 POS shift kas dan laporan harian

POS dan dashboard toko | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Mencocokkan transaksi dan uang kas setiap shift.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 07-OPEN  Pembukaan shift

Kebutuhan: Kasir membuka shift dengan jumlah uang awal dan identitas perangkat/toko; kebijakan lebih dari satu shift aktif perlu ditetapkan.

Kriteria penerimaan: Penjualan ditautkan ke shift aktif; jika belum ada shift, POS menolak atau meminta pembukaan sesuai aturan yang disetujui.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 07-CASH  Kas masuk dan keluar

Kebutuhan: Catat penambahan dan pengeluaran kas beserta kategori, nilai, alasan dan otorisasi; bukan dianggap penjualan produk.

Kriteria penerimaan: Pengeluaran kas Rp20.000 mengurangi kas yang diharapkan tetapi tidak mengurangi omzet.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 07-CLOSE  Penutupan dan selisih

Kebutuhan: Hitung kas seharusnya dari saldo awal, penjualan tunai, refund tunai serta kas masuk dan keluar; catat hitungan fisik dan selisih.

Kriteria penerimaan: Saldo awal 100.000 + penjualan tunai 200.000 − kas keluar 20.000 = seharusnya 280.000; fisik 275.000 menghasilkan selisih −5.000.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 07-DAY  Rekap harian

Kebutuhan: Tampilkan penjualan kotor, diskon, refund, penjualan bersih dan rincian metode pembayaran berdasarkan rentang waktu dan toko.

Kriteria penerimaan: Total bersih sama dengan penjualan selesai dikurangi diskon dan refund sesuai definisi laporan.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Memakai transaksi PRD 06 dan hak akses PRD 01; aturan pergantian hari dan jam toko perlu diputuskan.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
