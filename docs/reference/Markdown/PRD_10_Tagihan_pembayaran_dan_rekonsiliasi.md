# PRD 10 Tagihan pembayaran dan rekonsiliasi

Dashboard pusat dan mitra | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Memisahkan status tagihan mitra dari status pesanan dan pembayaran pembeli POS.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 10-INV  Tagihan mitra

Kebutuhan: Catat pihak yang ditagih, referensi pesanan, item, diskon, biaya kirim, total dan jatuh tempo bila disepakati.

Kriteria penerimaan: Pimpinan hanya melihat tagihan mitranya; transfer ke toko sendiri tidak membuat tagihan mitra.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 10-COL  Pencatatan pembayaran

Kebutuhan: Admin mencatat metode, jumlah, tanggal dan bukti; pembayaran sebagian mengurangi saldo terutang setelah diverifikasi.

Kriteria penerimaan: Tagihan 1.000.000 dibayar 400.000 menghasilkan sisa 600.000; bukti belum diverifikasi tidak dianggap lunas.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 10-CRED  Kredit retur dan promosi

Kebutuhan: Penyesuaian tagihan atau nota kredit harus merujuk alasan dan disetujui; tidak menghapus riwayat asal.

Kriteria penerimaan: Kredit Rp50.000 yang disetujui mengurangi saldo sesuai aturan dan dapat ditelusuri.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 10-DUE  Tunggakan

Kebutuhan: Tampilkan saldo terbuka dan jatuh tempo serta notifikasi sesuai izin; penangguhan pesanan atau denda hanya bila diputuskan.

Kriteria penerimaan: Tagihan melewati tanggal jatuh tempo muncul dalam daftar; sistem tidak menambahkan denda otomatis.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Bergantung pesanan, penerimaan dan voucher; momen penagihan, tempo dan perhitungan pajak perlu keputusan.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
