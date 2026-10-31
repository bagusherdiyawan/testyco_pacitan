# Pencarian riwayat struk web

Irisan PRD 06-SAVE/PRN menggunakan endpoint Sales yang sama dengan [Flutter](native-receipt-history.md). Buka **POS → Riwayat struk** pada toko. URL existing `/app/stores/{store}/pos/sales` tetap digunakan, kini ditangani controller baca tersendiri dalam provider Pos web.

Cari nomor struk dan, bila diperlukan, isi tanggal awal/akhir WIB. Kedua tanggal wajib diisi bersama; akhir kalender termasuk, maksimal 31 hari. Konversi memakai Shared/Application/WibPeriod menjadi interval ISO `[from,to)`. **Tanpa tanggal atau dengan kedua tanggal kosong, seluruh riwayat berhalaman tetap tersedia seperti sebelumnya.** Pencarian nomor dapat meliputi seluruh riwayat; mengikuti LIKE API existing, bukan pencarian produk/ID kasir.

**Terapkan filter** memulai halaman 1. Pagination maksimal 50 struk menjaga filter aktif yang dinormalisasi; isian browser yang belum disubmit dan old input form lain tidak mengubah link pagination. Default all-history web dipertahankan; default hari ini pada Flutter tidak diubah.

API menentukan scope: **struk saya** untuk kasir, **seluruh kasir toko yang diizinkan** untuk pengelola. Gudang, pengguna tidak ditugaskan dan organisasi lain ditolak sesuai aturan POS existing. Admin pusat tanpa membership operasional mitra tidak mendapat akses struk mitra. Toko nonaktif tetap dapat dibaca sesuai akses dalam organisasi aktif; daftar ini tidak memerlukan shift terbuka atau katalog operasional.

Kartu memperlihatkan nomor, waktu WIB, nama kasir/toko pada saat transaksi dan total integer rupiah dari snapshot Sales. Tidak membaca harga/nama master terbaru, menghitung omzet dari subset atau mencampur transaksi B2B/kas masuk-keluar. **Buka struk tersimpan** menuju route receipt lokal yang sudah ada; API memeriksa izin objek lagi. GET riwayat maupun reprint tidak membuat pembayaran, debit stok/kas atau refund. Rincian/cetak browser yang sudah ada tetap digunakan; printer perangkat belum ditambahkan.

BFF hanya mengirim interval, q dan page ke path API toko dari route. Query store/organization/cashier/scope/token/URL browser tidak diteruskan. Envelope, scope, row store_id serta pagination diperiksa sebelum render. Link pagination dan receipt dibangun lokal; next_page_url/URL eksternal API diabaikan. Bearer tetap pada session server, header/cookie API tidak disalin ke browser dan respons berhasil private/no-store. Semua nama/nomor dari API di-escape oleh Blade.

403, wrong scope, 503 dan koneksi gagal tidak ditampilkan sebagai riwayat kosong. 401 menghapus session web dan menuju login. Error bukan bukti struk hilang atau pembayaran belum terjadi. Halaman dibaca live secara terpisah, bukan snapshot beku lintas halaman. Tidak ada mutasi, payload retry, ekspor atau antrean offline dari fitur ini.

Atomic design: molekul receipt-summary menyajikan snapshot tersimpan; halaman menyusun scope, filter dan pagination dengan komponen existing. Controller riwayat dipisah dari controller checkout. Tidak ada perubahan endpoint/izin backend, API/OpenAPI 0.18.0, schema, dependensi atau kebijakan pembayaran.

Validasi mencakup all-history default/blank dan pencarian tanpa tanggal, pasangan kalender WIB/maksimal 31 hari, pagination lokal, whitelist/no-store/token-cookie boundary, snapshot/timezone/escaping/link receipt, session/403/401/mismatch/error/empty/inactive, serta validasi tanpa request backend. Test riwayat lama tetap memeriksa URL/page lama dengan fixture envelope API lengkap. Smoke HTTP terisolasi membaca riwayat sebagai kasir dan pimpinan serta menolak akses pusat; saldo stok dan kas lama tetap sesuai setelah GET. Ini pengujian server/HTML; browser mobile/tablet, printer, perangkat nyata, PostgreSQL/concurrency dan UAT belum diverifikasi.
