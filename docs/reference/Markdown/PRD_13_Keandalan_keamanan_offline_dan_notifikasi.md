# PRD 13 Keandalan keamanan offline dan notifikasi

Seluruh sistem | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Menjaga keamanan, ketersediaan operasional dan komunikasi perubahan status.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 13-SEC  Pemisahan dan keamanan

Kebutuhan: Periksa hak akses di server untuk setiap permintaan; gunakan koneksi terenkripsi, kebijakan sandi dan pencadangan teruji. Rincian keamanan ditetapkan tim teknis.

Kriteria penerimaan: Pengguna tanpa hak ditolak walau mengetahui ID objek; pemulihan cadangan diuji dan dicatat.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 13-OFF  Operasi offline POS

Kebutuhan: Jika diprioritaskan, simpan transaksi secara aman di perangkat dengan ID unik, status antrean dan sinkronisasi ulang; daftar fungsi offline dan batas stok diputuskan sebelum implementasi.

Kriteria penerimaan: Transaksi offline tidak hilang saat aplikasi ditutup; setelah sinkronisasi berulang hanya satu transaksi tercatat di pusat.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 13-CON  Konflik dan pengecualian

Kebutuhan: Ketika data offline bertentangan dengan server, tandai untuk peninjauan; jangan diam-diam menimpa transaksi, stok atau pemakaian voucher.

Kriteria penerimaan: Dua perangkat memakai kuota terakhir saat offline: sistem menandai konflik untuk penyelesaian sesuai kebijakan, bukan mengklaim keduanya valid otomatis.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 13-NOT  Notifikasi

Kebutuhan: Kirim notifikasi dalam aplikasi untuk stok rendah, persetujuan, pesanan, pengiriman dan tagihan; kanal lain bergantung integrasi dan persetujuan.

Kriteria penerimaan: Pengajuan muncul kepada pimpinan yang relevan; pengguna mitra lain tidak menerima isi notifikasi.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 13-NFR  Kualitas layanan

Kebutuhan: Tetapkan target waktu respons, ketersediaan, retensi data, backup, pemulihan dan jenis perangkat setelah uji lapangan; catat waktu sinkron terakhir di POS.

Kriteria penerimaan: Uji penerimaan mencakup pemulihan backup, gangguan jaringan, isolasi mitra dan sinkronisasi berulang; target angka belum disepakati.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Lintas semua PRD; fitur offline dan integrasi eksternal bukan asumsi sudah tersedia.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
