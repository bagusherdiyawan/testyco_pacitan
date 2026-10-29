# Pengaturan stok minimum di Flutter

Irisan PRD 05-MIN serta 01-ACL/AUD. Pengaturan yang sebelumnya melalui web kini tersedia di Flutter mobile/tablet, memakai domain Inventory, aturan izin, satuan dan API mutasi yang sama. Tidak ada kebijakan pengadaan atau stok baru.

## Penggunaan

Buka **Stok → Stok minimum dan peringatan**. Default hanya stok rendah. Matikan filter untuk menemukan SKU tanpa konfigurasi, atau cari SKU/nama dan kirim pencarian lewat keyboard. Daftar memuat 50 SKU per halaman; sebelumnya/berikutnya mempertahankan filter. Waktu observasi server ditampilkan per halaman. Halaman-halaman dan detail adalah pembacaan terpisah, bukan satu snapshot stok yang dibekukan.

Owner/admin organisasi, pimpinan mitra serta kepala toko assigned dapat membuka **Atur ambang**. Kasir/gudang berizin hanya membaca. Metadata `can_manage`, ID toko, ID produk dan versi harus tersedia; respons lama tanpa metadata tersebut tetap dapat dibaca tanpa tombol edit. Hak pusat untuk onboarding tidak membuka pengaturan operasional mitra. API tetap memeriksa ulang izin setiap POST.

Editor mengambil detail berdasarkan product_id, bukan nama/SKU atau posisi baris pencarian. Perubahan nama SKU tidak memindahkan konfigurasi ke produk lain. Toko/produk nonaktif ditolak; SKU organisasi lain ditolak. Detail tidak memuat harga grosir/eceran, membuat konfigurasi default, mengklaim UUID, atau menulis audit/pergerakan stok.

1. Tinjau SKU, saldo tersedia, keadaan stok, ambang dan versi saat ini. Version 0 berarti belum ada konfigurasi; null berarti belum dipantau/dinonaktifkan.
2. Aktifkan **Pantau SKU ini** dan isi ambang **biji/satuan dasar** bulat 0–1.000.000.000. Tidak ada konversi kemasan atau angka bawaan. **0 tetap aktif**; matikan pemantauan untuk mengirim null dan menonaktifkan tanpa menghapus riwayat.
3. Isi alasan, lalu pilih **Tinjau perubahan ambang**. Dialog memperlihatkan SKU, sebelum/sesudah, versi dan alasan. Dialog belum menyimpan; konfirmasi mengirim original version, minimum_pieces, reason dan UUID.
4. Konflik versi meminta muat ulang serta review baru. Aplikasi tidak mengganti versi draft lama otomatis. Refresh eksplisit membuang isian lokal, mengambil konfigurasi terbaru dan mengosongkan alasan.

Stok rendah tetap `available < minimum`; sama dengan ambang tidak rendah. Available menjumlahkan utuh + terbuka + siap dari batch toko/SKU, tanpa karantina/susut. Ini bukan bukti mutu, FEFO, jumlah siap saji, atau reservasi. Mengubah ambang tidak mengubah stok, membuat order, tagihan, push notification atau pesan kepada orang lain.

## Respons jaringan dan pemulihan

Controller mempertahankan path/UUID/body JSON immutable saat timeout, koneksi putus atau hasil server belum pasti. Pending menahan edit, refresh dan pop halaman. Gunakan **Ulangi perubahan yang sama**. Penolakan setelah percobaan sebelumnya tidak pasti tidak membuktikan percobaan pertama gagal commit, sehingga pending tidak dibuang/dirotasi otomatis.

Setelah ACK, GET detail membaca versi terkini; hasil replay dapat memuat konfigurasi lama yang sudah diubah pengelola lain. Aplikasi tidak menampilkan hasil replay sebagai konfigurasi terbaru. Bila POST berhasil tetapi GET gagal, UI menyatakan ambang tersimpan dan menahan perubahan baru sampai refresh berhasil, tanpa POST pengganti.

Pending hanya dalam memori halaman. Setelah restart, perangkat mati atau pergantian sesi, login kembali dan periksa konfigurasi serta audit sebelum membuat pengganti. Belum ada antrean tahan restart/offline, secure storage atau sinkronisasi konflik otomatis.

## Kontrak dan struktur

GET `/api/v1/stores/{store}/inventory/minimums/{product}` mengembalikan `data` (StockMinimumRow), `store`, `can_manage`, `observed_at`, private/no-store. Query daftar (`q`, `page`, `low_only`) tidak memengaruhi lookup ID tunggal. Proyeksi/read scope dibagi dengan laporan daftar, tanpa kolom harga. POST pada path sama tetap memakai StockMinimums.configure/Operations yang sudah tersedia.

DTO, repository, controller dan halaman terpisah. StockMinimumCard menerima optional action dari page; MinimumChangeReview merender nilai, tanpa aturan domain. Modul mengikuti registry Inventory/FEATURE_INVENTORY yang sudah ada. Feature flag tidak menggantikan otorisasi backend. Tidak ada migrasi/dependensi baru.

Verifikasi mencakup izin negatif/lintas tenant/assignment, GET tanpa penulisan, rename SKU, null/zero, replay setelah perubahan berikutnya, konflik versi, unknown/denial, ACK/failed refresh serta widget 390px text scale 1,5 dan tablet 1200px. Ini belum UAT, build/perangkat Android/iOS, PostgreSQL concurrency atau validasi angka ambang bisnis produksi.
