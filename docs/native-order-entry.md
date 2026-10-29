# Pengajuan pesanan baru di Flutter

Irisan PRD 04-REQ, terkait harga/kemasan PRD 02 dan pemisahan akses PRD 01. Flutter kini membuat pengajuan dengan pilihan SKU pusat, SKU lokal, jumlah kemasan, ambil/antar dan catatan. [Keputusan/penerimaan native](native-ordering.md) tetap alur terpisah. Perubahan sebelum persetujuan kini tersedia di [composer perubahan](native-order-amend.md); dispatch gudang masih melalui web.

## Alur

Buka **Pesanan → Buat pengajuan**. Pilih SKU pusat, pasangkan SKU lokal toko yang aktif dan isi jumlah kemasan 1–10.000. Pasangan harus sama isi kemasan dan kondisi. Satu pengajuan maksimal 100 SKU pusat, satu pemasok pusat, serta SKU lokal tidak berulang. Pemetaan mitra tidak ditebak berdasarkan nama. Untuk transfer internal, SKU lokal harus identik dengan SKU pusat; aplikasi hanya menawarkan identitas yang sama.

Pilih ambil di gudang atau antar ke toko. Antar memerlukan alamat toko yang benar; perbarui alamat melalui web, lalu refresh bila belum ada. Catatan opsional maksimal 2.000 karakter. Tidak ada pengajuan otomatis dari peringatan stok minimum.

Tekan **Tinjau pengajuan di server**. Server memeriksa izin/mapping dan menghitung harga grosir per kemasan, jumlah, konversi, tujuan serta total. Nilai client tidak menjadi sumber harga. Transfer internal bernilai komersial nol dari server. Pratinjau menampilkan waktu WIB dan tidak membuat order, audit, claim operasi atau reservasi/pergerakan stok.

Tinjau SKU pusat → lokal, jumlah kemasan × isi historis dan harga/total, lalu **Konfirmasi pengajuan**. Aplikasi mengirim payload yang ditinjau, fingerprint review_hash dan UUID baru. Server menghitung ulang snapshot: jika fakta yang ditinjau berbeda, respons 409 tanpa menyimpan order. Pengguna refresh dan meninjau lagi. Tidak ada masa berlaku harga yang ditebak, tanda tangan harga, jaminan stok atau janji waktu pasok.

Sesudah sukses, **Lihat detail pengajuan** membuka record tersimpan. Status awal awaiting_approval; pengajuan belum terlihat dalam inbox pemasok pusat sampai disetujui pimpinan yang berwenang. Belum menambah stok tujuan atau membuat tagihan otomatis. Daftar pesanan dimuat ulang saat halaman ditutup. Header/total tinjauan bukan bukti pembayaran atau penerimaan fisik.

## Refresh dan jaringan

Selama tinjauan tampil, pilihan, jumlah dan catatan dikunci. **Ubah pilihan** kembali ke composer dan membatalkan tinjauan tersebut. Refresh katalog mempertahankan niat berupa ID/kuantitas, tetapi membuang tinjauan harga yang belum dikirim. SKU hilang/nonaktif atau pasangan tidak cocok harus diperbaiki eksplisit; tidak diganti ke SKU lain secara diam-diam.

Saat konfirmasi, UUID dan seluruh nested body dibekukan sebagai JSON. Jika hasil jaringan belum diketahui, gunakan **Ulangi pengajuan yang sama**; form dan navigasi kembali ditahan. Refresh atau perubahan harga tidak memutar UUID/mengubah payload pending. Replay server yang sudah committed mengembalikan order awal meski harga sekarang berubah. Respons sukses dicatat sebagai createdId tanpa bergantung pada GET detail berikutnya; klik ulang tidak membuat pengajuan kedua.

Konflik definitif pada percobaan pertama membuang tinjauan dan meminta refresh/review baru. Setelah pernah menerima hasil unknown, respons penolakan berikutnya tidak dianggap otomatis membuktikan operasi awal gagal; nonce/body tetap ditahan. Jika retry tetap ditolak, periksa daftar/riwayat/audit bersama pengelola sebelum menutup halaman dan membuat tindakan pengganti. Tidak ada tombol yang mengganti UUID secara otomatis untuk melewati konflik.

Pending dan cart hanya di memori. Tutup paksa, perangkat mati atau pergantian sesi menghilangkan state lokal. Saat sesi berakhir, navigator aplikasi di-reset ke login sekalipun halaman sedang menahan pop; halaman transaksi/data sesi lama tidak dibawa ke sesi baru. Setelah login kembali, cek pengajuan yang sudah tersimpan sebelum membuat pengajuan baru. Belum ada secure storage atau antrean pengajuan tahan restart/offline, maupun pemulihan otomatis nonce lintas sesi.

## API dan batas akses

GET operations-context menambahkan order_kind dari jenis organisasi. Native membutuhkan active, can_order dan order_kind yang dikenal; response lama tanpa metadata tidak ditebak. Katalog pusat/lokal dipaginasi melalui path API yang tetap dalam scope; next_page_url eksternal tidak diikuti. Kasir, toko tidak assigned, mitra lain dan hak onboarding pusat tidak memberi izin preview/create mitra. Toko nonaktif tidak dapat membuat pengajuan. Server memeriksa ulang seluruh aturan walau UI dimanipulasi.

POST `/api/v1/stores/{store}/orders/preview` memakai delivery_mode, note opsional dan lines berisi supplier_product_id/local_product_id/quantity_packs. Tidak perlu UUID karena read-only. POST `/.../orders` menerima review_hash opsional untuk kompatibilitas web/API lama; native selalu mengirim hash. Review hash adalah fingerprint concurrency, bukan otorisasi/harga yang dipercaya. Harga selalu dihitung server, termasuk bila pemanggil lama tidak mengirim hash.

Store/produk dikunci saat membaca snapshot dalam transaksi; hasil review tidak disimpan sebagai resource baru. Preview dan create memakai snapshot builder yang sama. Operations menyimpan hasil mutasi dan audit dalam transaksi order. Tidak ada migrasi/dependensi baru. Belum ada klaim concurrency PostgreSQL, build perangkat atau UAT; pengujian meliputi API, HTTP nyata dan widget 390px skala teks 1,5/1200px. Refund, offline dan aturan komersial/produk lain tetap mengikuti keputusan terpisah.
