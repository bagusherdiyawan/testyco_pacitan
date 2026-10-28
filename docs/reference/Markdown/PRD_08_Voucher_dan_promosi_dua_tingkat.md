# PRD 08 Voucher dan promosi dua tingkat

Dashboard pusat mitra dan POS | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Mengelola promosi pembelian mitra dan promosi pembeli akhir secara terpisah.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 08-B2B  Voucher pusat ke mitra

Kebutuhan: Pusat mengatur kode, nominal/persen, syarat minimal, mitra sasaran, SKU, kuota, tanggal, kombinasi dan batas diskon.

Kriteria penerimaan: Voucher yang kedaluwarsa atau tidak mencapai minimum ditolak dengan alasan; pesanan menyimpan rincian diskon.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 08-B2C  Voucher toko ke pembeli

Kebutuhan: Mitra atau toko berwenang mengatur voucher POS untuk toko dan produk yang diizinkan; aturan berlaku dan masa aktif dapat ditentukan.

Kriteria penerimaan: Voucher toko A tidak dapat dipakai di toko B jika cakupannya hanya toko A.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 08-FUND  Pendanaan dan klaim

Kebutuhan: Catat pihak penanggung pusat, mitra atau pembagian; cara penggantian dana dan otorisasi klaim menunggu keputusan bisnis.

Kriteria penerimaan: Promo yang didanai pusat tercatat sebagai kewajiban promosi terpisah; tidak otomatis mengurangi tagihan tanpa aturan yang disetujui.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 08-LIFE  Pemakaian dan pembatalan

Kebutuhan: Simpan kuota dan riwayat pemakaian unik; penanganan penggunaan kembali setelah batal harus mengikuti aturan yang ditetapkan.

Kriteria penerimaan: Dua perangkat mencoba memakai kuota terakhir: maksimal satu pemakaian berhasil setelah validasi pusat; untuk offline gunakan kebijakan yang disepakati.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Tergantung pesanan, POS dan identitas; kebijakan kuota saat offline serta promosi gabungan terbuka.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
