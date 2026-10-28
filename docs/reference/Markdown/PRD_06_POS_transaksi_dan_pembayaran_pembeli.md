# PRD 06 POS transaksi dan pembayaran pembeli

POS toko | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Mencatat transaksi pembeli secara cepat dan mengurangi stok sesuai produk.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 06-CART  Keranjang

Kebutuhan: Kasir mencari produk atau memilih tombol, menambah jumlah, memilih per biji, porsi atau kemasan serta paket; total dihitung dari harga yang berlaku.

Kriteria penerimaan: Tambah 4 biji harga Rp3.000: subtotal Rp12.000; pengubahan jumlah memperbarui subtotal.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 06-PAY  Penyelesaian pembayaran

Kebutuhan: Catat tunai dan kembalian; QRIS atau transfer dicatat sebagai metode setelah konfirmasi kasir; integrasi otomatis ditangani terpisah.

Kriteria penerimaan: Transaksi tidak dianggap dibayar hanya karena QRIS dipilih; sebelum konfirmasi kasir status belum lunas.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 06-SAVE  Transaksi dan stok

Kebutuhan: Ketika pembayaran terkonfirmasi, simpan nomor transaksi unik, item, diskon, metode, kasir, waktu, toko dan pergerakan stok sekali.

Kriteria penerimaan: Ketuk tombol bayar dua kali tidak menciptakan dua transaksi atau mengurangi stok dua kali.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 06-VOID  Pembatalan dan refund

Kebutuhan: Hanya pengguna berwenang dapat membatalkan atau refund disertai alasan; koreksi stok dicatat sebagai pergerakan baru, bukan menghapus transaksi awal.

Kriteria penerimaan: Kasir tanpa izin ditolak ketika refund; pengelola dapat melakukan refund dan riwayat awal tetap terlihat.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 06-RCPT  Struk

Kebutuhan: Sediakan nomor transaksi, toko, waktu, item, diskon, total dan metode; cetak mengikuti kompatibilitas perangkat.

Kriteria penerimaan: Total struk sama dengan total transaksi yang tersimpan; kegagalan printer tidak menggandakan transaksi.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Bergantung pada katalog, harga, stok, peran dan voucher; integrasi QRIS memerlukan penyedia terpisah.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
