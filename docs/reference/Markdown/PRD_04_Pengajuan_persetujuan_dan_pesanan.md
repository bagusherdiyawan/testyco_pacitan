# PRD 04 Pengajuan persetujuan dan pesanan

Aplikasi mitra dan dashboard pusat | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Memastikan pembelian toko mitra hanya dikirim kepada pusat oleh pimpinan yang berwenang.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 04-REQ  Pengajuan toko

Kebutuhan: Kepala toko memilih SKU, jumlah kemasan, toko penerima, catatan dan usulan pengiriman; sistem menghitung estimasi harga.

Kriteria penerimaan: Pengajuan yang disimpan belum menjadi pesanan ke pusat dan tidak mengurangi stok pusat.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 04-APR  Persetujuan pimpinan

Kebutuhan: Pimpinan mitra dapat menyetujui, mengubah atau menolak pengajuan; pimpinan juga dapat membuat pesanan langsung.

Kriteria penerimaan: Hanya pimpinan mitra berwenang dapat mengirim pesanan; kasir ditolak; perubahan jumlah tersimpan dalam riwayat.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 04-ORD  Status pesanan

Kebutuhan: Gunakan pengajuan, menunggu persetujuan, dikembalikan, disetujui dan dikirim ke pusat, diterima pusat, diproses, dikirim/siap diambil, diterima sebagian, selesai dan dibatalkan; pembayaran dicatat terpisah.

Kriteria penerimaan: Pesanan diterima sebagian tidak otomatis selesai; sisa menunggu tindak lanjut yang dicatat.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 04-EXC  Perubahan dan pembatalan

Kebutuhan: Pusat memberi tahu kekurangan stok; perubahan setelah persetujuan memerlukan persetujuan yang relevan; tidak membuat penerimaan fiktif.

Kriteria penerimaan: Ketika item diganti setelah persetujuan, perubahan tercatat dan mitra menyetujui kembali sebelum dipenuhi.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 04-OWN  Permintaan toko sendiri

Kebutuhan: Toko milik perusahaan mengajukan permintaan dan gudang memindahkan stok internal, bukan membuat omzet mitra atau piutang.

Kriteria penerimaan: Kirim stok ke toko sendiri menghasilkan transfer stok; tidak menghasilkan penjualan ke mitra.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Terkait PRD 01, 02, 05, 09 dan 10; pengajuan bukan reservasi stok kecuali diputuskan.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
