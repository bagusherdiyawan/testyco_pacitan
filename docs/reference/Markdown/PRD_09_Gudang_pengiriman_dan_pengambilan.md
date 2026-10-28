# PRD 09 Gudang pengiriman dan pengambilan

Dashboard pusat dan penerimaan toko | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Mencatat pemenuhan pesanan secara terukur serta kondisi produk beku.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 09-PICK  Penyiapan

Kebutuhan: Gudang melihat pesanan yang siap dipenuhi dan mencatat SKU, jumlah dan batch yang dipilih; stok tidak boleh keluar dua kali.

Kriteria penerimaan: Pesanan yang hanya dipenuhi sebagian menampilkan jumlah yang disiapkan dan sisa.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 09-SHIP  Antar atau ambil

Kebutuhan: Catat mode pengiriman, tujuan, jadwal, petugas/kurir, waktu keluar, bukti dan status; pengambilan mitra dicatat sebagai serah terima.

Kriteria penerimaan: Pesanan mode ambil tidak membutuhkan alamat kurir; penerima tetap dikonfirmasi.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 09-COLD  Pemeriksaan kondisi

Kebutuhan: Catat kondisi kemasan, suhu dan waktu bila alat dan SOP tersedia, serta kejadian yang perlu ditinjau; batas mutu mengikuti SOP produk yang divalidasi.

Kriteria penerimaan: Petugas dapat menandai kondisi tidak sesuai dan mencatat tindakan; sistem tidak menyatakan produk aman secara otomatis.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 09-PROB  Selisih dan retur

Kebutuhan: Penerima mencatat jumlah aktual, kerusakan, bukti dan alasan; pusat meninjau tindak lanjut pengganti, retur atau kredit.

Kriteria penerimaan: Barang rusak tidak otomatis menjadi stok layak jual; penyelesaian tidak otomatis mengubah tagihan tanpa persetujuan.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Memakai pesanan, stok batch dan pembayaran; kebijakan risiko dan pengembalian belum ditetapkan.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
