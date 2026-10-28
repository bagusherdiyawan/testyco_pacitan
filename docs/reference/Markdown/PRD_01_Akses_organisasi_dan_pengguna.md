# PRD 01 Akses organisasi dan pengguna

Layanan bersama | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Menjamin pemisahan data antara pusat, mitra, toko dan pengguna.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 01-ORG  Organisasi dan toko

Kebutuhan: Pusat membuat mitra dan toko; setiap toko memiliki pemilik berupa perusahaan sendiri atau satu mitra; status aktif/nonaktif serta alamat dicatat.

Kriteria penerimaan: Diberikan Mitra A memiliki toko A1 dan A2, saat A masuk maka hanya A1 dan A2 terlihat; Mitra B ditolak saat mencoba membuka data A1.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 01-USR  Akun dan penugasan

Kebutuhan: Setiap orang memiliki akun unik, dapat ditugaskan pada beberapa toko yang diizinkan, dan dapat dinonaktifkan tanpa menghapus transaksi.

Kriteria penerimaan: Saat akun dinonaktifkan sesi baru ditolak; riwayat transaksi lama tetap menampilkan pelaku.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 01-ACL  Peran dan kewenangan

Kebutuhan: Pisahkan pemilik pusat, admin pusat, gudang, pimpinan mitra, kepala toko, kasir dan manajer toko sendiri; izin perubahan harga, stok, refund dan persetujuan diatur khusus.

Kriteria penerimaan: Kasir mencoba menyetujui pembelian mitra: akses ditolak; pimpinan mitra berwenang dapat menyetujuinya.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 01-AUD  Jejak audit

Kebutuhan: Catat aktor, organisasi, waktu, tindakan, objek, nilai sebelum dan sesudah untuk perubahan penting; informasi sensitif tidak disimpan berlebihan.

Kriteria penerimaan: Perubahan peran dicatat dan dapat ditelusuri; pengguna tanpa izin tidak dapat mengubah catatan audit.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Pesanan, POS, stok, voucher dan semua dashboard bergantung pada identitas dan izin.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
