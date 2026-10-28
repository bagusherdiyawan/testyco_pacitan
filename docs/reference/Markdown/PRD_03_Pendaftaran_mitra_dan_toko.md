# PRD 03 Pendaftaran mitra dan toko

Dashboard pusat dan mitra | Spesifikasi fitur terpisah untuk tim produk, desain, pengembang dan penguji

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Tujuan dan cakupan

Mengaktifkan kemitraan dan toko tanpa mencampur kepemilikan atau kebijakan.

Cakupan spesifik dokumen ini dirinci pada fitur di bawah. Kebutuhan terkait lainnya mengikuti dokumen PRD yang dirujuk. Prioritas awal dan perilaku yang belum diputuskan harus divalidasi sebelum dibuat.

## 2 Aktor dan batas akses

Pusat mengakses sesuai tugas dan perjanjian; pimpinan mitra hanya organisasi serta toko miliknya; kepala toko dan kasir hanya toko yang ditugaskan. Otorisasi setiap tindakan mengikuti PRD 01, bukan hanya penyembunyian tombol.

## 3 Alur utama

Pengguna berwenang membuka modul, memilih organisasi atau toko yang dapat diakses, memasukkan atau meninjau data, lalu sistem memvalidasi aturan sebelum menyimpan. Sistem menampilkan hasil dan menyimpan riwayat perubahan penting. Alur khusus dijabarkan pada setiap fitur.

## 4 Spesifikasi fitur dan kriteria penerimaan

### 03-MIT  Data mitra

Kebutuhan: Pusat mencatat nama usaha, penanggung jawab, kontak, alamat tagihan, tipe kerja sama dan status; lampiran perjanjian dibatasi aksesnya.

Kriteria penerimaan: Mitra belum aktif tidak dapat mengirim pesanan; pengaktifan meninggalkan audit.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 03-TOK  Data toko

Kebutuhan: Catat nama, alamat, wilayah, jenis kepemilikan, pimpinan, jam operasional dan status; setiap toko mitra terhubung ke satu mitra.

Kriteria penerimaan: Pimpinan mitra hanya melihat toko miliknya; pusat dapat memfilter toko sendiri dan mitra.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 03-KON  Ketentuan kerja sama

Kebutuhan: Catat masa berlaku, penggunaan merek, cakupan data yang dibagikan, skema putus beli atau konsinyasi dan fasilitas sebagai data kontrak. Konsinyasi belum otomatis aktif.

Kriteria penerimaan: Mencatat konsinyasi tidak membuat sistem menghitung bagi hasil; sistem menandai perlu proses khusus.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

### 03-STA  Penutupan toko

Kebutuhan: Toko dapat dinonaktifkan tanpa menghapus penjualan atau stok; penyelesaian stok, tagihan dan aset dilakukan sebelum penutupan operasional.

Kriteria penerimaan: Toko nonaktif tidak menerima pesanan atau transaksi baru; laporan lama tetap tersedia bagi pihak berwenang.

Penanganan umum: jika data wajib tidak lengkap, akses tidak sah, atau status tidak memenuhi prasyarat, sistem menolak tindakan dan menampilkan alasan yang dapat dipahami; data yang sudah tersimpan tidak dihapus diam-diam.

## 5 Data dan peristiwa

Setiap catatan menyimpan ID unik, organisasi dan lokasi terkait, status, waktu dibuat/diubah dan aktor. Data khusus mengikuti kebutuhan fitur di atas. Transaksi yang sudah terjadi mempertahankan nilai historis; perubahan dilakukan melalui catatan lanjutan yang dapat ditelusuri.

## 6 Dependensi dan batasan

Bergantung pada PRD 01; memengaruhi harga, order, dashboard dan freezer.

## 7 Keputusan terbuka dan prioritas

P0 menandai usulan kebutuhan yang diperlukan agar pilot berjalan; P1 menandai penguatan atau integrasi berikutnya. Pengelompokan ini belum merupakan komitmen jadwal. Pemilik bisnis dan tim operasional perlu menyetujui aturan yang dinyatakan belum diputuskan, termasuk cakupan akses, biaya dan kebijakan operasional yang relevan.

## 8 Pengujian dan kesiapan rilis

- Uji setiap kriteria penerimaan pada fitur di atas menggunakan akun dengan hak yang sesuai dan tanpa hak.

- Uji data antar mitra, status tidak valid, operasi berulang, pembatalan dan kegagalan jaringan untuk alur yang relevan.

- Uji dengan contoh toko sendiri dan toko mitra; rekonsiliasi hasil dengan catatan sumber dan operasi fisik.

- Rilis pilot setelah pemilik produk, operasional dan perwakilan pengguna menyetujui hasil uji serta keputusan yang memengaruhi modul ini.
