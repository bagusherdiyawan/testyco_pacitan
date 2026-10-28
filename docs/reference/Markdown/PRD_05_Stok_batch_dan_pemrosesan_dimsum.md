# PRD 05 Stok batch dan pemrosesan dimsum

Gudang pusat dan toko | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Mengelola kuantitas beku, siap jual, kerusakan dan jejak batch.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 05-RCV  Penerimaan

Kebutuhan: Penerima mencatat jumlah aktual per SKU, batch, tanggal kedaluwarsa dan kondisi; stok bertambah hanya atas penerimaan yang dikonfirmasi.

Kriteria penerimaan: Pesan 20 bungkus, terima 18: stok hanya bertambah 18; selisih 2 ditandai.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 05-MOV  Buku pergerakan

Kebutuhan: Setiap penerimaan, transfer, penjualan, pengukusan, retur dan penyesuaian menghasilkan pergerakan dengan sumber, tujuan, pelaku dan waktu.

Kriteria penerimaan: Pengulangan konfirmasi dengan ID yang sama tidak menggandakan stok.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 05-COOK  Buka dan kukus

Kebutuhan: Petugas mencatat konversi kemasan utuh ke biji terbuka dan pemindahan beku ke siap jual, dengan batch bila tersedia; hasil aktual dan susut dicatat.

Kriteria penerimaan: Buka 1 bungkus isi 10 dan kukus 6: stok utuh turun 1 bungkus, terbuka bertambah 4 biji dan siap jual bertambah 6.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 05-COUNT  Stok opname dan susut

Kebutuhan: Petugas menghitung fisik; selisih ditinjau dan disetujui oleh pihak berwenang dengan alasan sebelum penyesuaian.

Kriteria penerimaan: Selisih minus 3 belum mengubah stok sebelum disetujui; setelah disetujui tercatat minus 3 beserta alasan.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 05-EXP  Peringatan dan penarikan batch

Kebutuhan: Sistem menampilkan batch kedaluwarsa terdekat dan membantu pelacakan lokasi penerimaan; masa peringatan ditentukan pemilik.

Kriteria penerimaan: Batch yang ditandai bermasalah ditampilkan di daftar lokasi terkait; pemblokiran penjualan hanya setelah aturan disetujui.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 05-MIN  Stok minimum

Kebutuhan: Ambang per SKU dan toko memicu notifikasi; saran berdasarkan penjualan dan waktu pasok hanya bila data memadai.

Kriteria penerimaan: Stok turun melewati ambang menghasilkan notifikasi tanpa otomatis memesan atau meminta pembayaran.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Bergantung pada konversi PRD 02; aturan minus stok dan alokasi batch perlu keputusan.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
