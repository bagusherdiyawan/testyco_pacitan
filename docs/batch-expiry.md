# Penelusuran kedaluwarsa batch

Irisan baca PRD 05-EXP: web menyediakan **Pesanan dan stok toko → Telusuri kedaluwarsa batch**, pada `/app/stores/{store}/inventory/batch-expiry`. Halaman memakai endpoint batch Inventory existing yang kini mendukung filter tanggal dan saldo. Flutter/POS lama tetap memakai respons batch yang sama; tampilan filter native khusus belum ditambahkan.

Pilih batas tanggal kemasan mulai/sampai yang diperlukan. Keduanya opsional dan inklusif; salah satu batas saja juga diterima. Tanpa batas, seluruh katalog batch tetap ditampilkan berdasarkan expires_on lalu ID ascending, maksimal 100 per halaman. Ini tanggal kemasan yang tersimpan, bukan timestamp UTC; tidak digeser zona waktu atau dipaksa maksimal 31 hari seperti periode laporan transaksi. Tidak ada horizon peringatan bawaan yang ditetapkan sistem.

Cari SKU/nama/kode batch historis. Pilihan **Ada saldo utuh/terbuka/siap/karantina** mengembalikan batch yang memiliki nilai positif pada setidaknya satu bucket tersebut. Karantina tetap ditampilkan untuk penelusuran, meskipun available_pieces-nya nol; catatan susut kumulatif sendiri tidak memenuhi filter. Tanpa pilihan ini, batch bersaldo nol tetap tampil. Tidak mengubah definisi available_pieces lama (utuh + terbuka + siap), menambah stok, atau menafsirkan karantina sebagai stok layak jual.

Tanggal, SKU/nama, kondisi dan isi kemasan mengikuti snapshot batch, bukan master produk terbaru. Nilai saldo adalah saldo tersimpan saat request, dibaca terpisah per halaman; bukan snapshot beku lintas pagination. Waktu diamati ditampilkan WIB. Tidak ada harga grosir/eceran di endpoint/kartu ini.

Hak baca batch existing dipertahankan: membership aktif serta toko yang diizinkan, termasuk kasir yang ditugaskan. Pengelola pusat tanpa akses operasional mitra tetap ditolak. Toko nonaktif dapat dibaca bila izin/organisasi masih aktif. Ini berbeda dari ledger yang staff-scoped; akses baca katalog tidak membuka hak ledger atau mutasi. Web tidak mengakses DB API.

API query: `expires_from`, `expires_to` (YYYY-MM-DD inklusif, optional independent), `q` maksimal 100 karakter dengan LIKE snapshot, `with_balance=0|1`, dan page positif. Predicate pencarian dan bucket dikelompokkan di bawah scope toko. Metadata store/observed_at ditambahkan tanpa mengubah field model, default ordering, pagination 100 atau akses lama. GET private/no-store dan tidak menulis ledger/audit/revisi.

BFF memvalidasi dan hanya meneruskan filter tersebut ke path API toko dari route. Envelope/row toko dan pagination diperiksa. Pagination lokal menjaga filter aktif, mengabaikan URL API, token/scope/toko dari query browser serta old input form lain. Bearer/cookie API tetap di server; semua teks Blade escaped. Error/403/401/mismatched scope/503 bukan empty state; 401 menghapus session web.

Fitur ini baru penelusuran data. Belum menetapkan masa peringatan, push/inbox, masa simpan hasil kukus, sertifikasi mutu, pemblokiran penjualan, pemilihan/alokasi FEFO otomatis atau recall. Keputusan D-05 dan SOP operasional masih harus diselesaikan sebelum aktivasi kemampuan tersebut. Tanggal akhir yang dipilih pengguna tidak menjadi aturan baru untuk POS.

Arsitektur: Inventory/BatchCatalogue sebagai read service di API, controller Inventory existing untuk validasi; controller baca Operations web, halaman filter dan molekul batch-expiry. Provider existing dipakai, tanpa migrasi database, dependensi atau mutasi finansial baru. OpenAPI 0.19.0 memperkaya operasi existing, jumlah tetap 72.

Validasi: tiga tes API (40 assertions) untuk batas inklusif/scoped OR, karantina/waste-only, snapshot setelah perubahan master, zero write/no prices, cashier/assignment/tenant/inactive access, legacy 100+1 dan validasi; enam tes web (57 assertions) untuk whitelist/calendar/pagination/no-store/token-cookie boundary, metadata escaping, default tanpa horizon, upper-only/rentang panjang, error/empty/inactive. Smoke HTTP menelusuri batch aktual, memeriksa revision tetap, batas tanpa hasil, dan sesi web kasir tetap dapat membaca. Server/HTML dan regresi native diuji; browser visual/interaksi, perangkat nyata, PostgreSQL/concurrency dan UAT belum diverifikasi.
