# PRD 02 Katalog produk satuan dan harga

Dashboard pusat dan POS | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Menyediakan produk yang konsisten di seluruh kanal dan konversi satuan yang benar.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 02-SKU  Produk dan varian

Kebutuhan: Catat SKU, nama, varian, kondisi beku atau siap jual, status aktif, gambar, jenis pemilik dan satuan dasar biji.

Kriteria penerimaan: Produk tidak aktif tidak dapat dipilih untuk pesanan baru, tetapi riwayat transaksi tetap ada.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 02-PAK  Kemasan dan konversi

Kebutuhan: Setiap SKU kemasan menyimpan isi 8 atau 10 biji sesuai data sebenarnya; 1 porsi dapat berisi jumlah biji yang dikonfigurasi. Jangan mengubah konversi historis.

Kriteria penerimaan: Terima 2 kemasan isi 8 menambah 16 biji; jual satu porsi 4 biji mengurangi 4 biji.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 02-HGA  Harga dan masa berlaku

Kebutuhan: Harga pembelian mitra dan harga jual toko dipisah; pusat mengelola harga mitra, pengaturan harga toko mengikuti kewenangan.

Kriteria penerimaan: Pesanan yang sudah disetujui menyimpan harga pada saat transaksi walaupun daftar harga kemudian berubah.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 02-BND  Paket dan resep penjualan

Kebutuhan: Paket bisa berisi beberapa varian; setiap komponen mengurangi stok sesuai jumlah resep, termasuk satuan masing-masing.

Kriteria penerimaan: Paket 2 ayam dan 1 udang mengurangi stok ayam 2 dan udang 1; stok kurang memunculkan penanganan sesuai kebijakan.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Stok, pesanan, voucher dan POS menggunakan kode produk dan harga yang sama.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
