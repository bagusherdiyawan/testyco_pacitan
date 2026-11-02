# Penelusuran kedaluwarsa batch Flutter

Irisan baca PRD 05-EXP melengkapi [halaman web](batch-expiry.md). Dari modul **Stok**, buka **Telusuri kedaluwarsa batch**. Modul Inventory/FEATURE_INVENTORY existing tetap digunakan. Akses API mengikuti toko dan membership, termasuk kasir yang ditugaskan; tidak disamakan dengan akses staff ledger atau izin pengolahan stok.

Filter default tanpa tanggal/pencarian dan termasuk batch bersaldo nol. Batas tanggal kemasan awal/akhir boleh diisi masing-masing, keduanya inklusif. Format YYYY-MM-DD divalidasi sebagai tanggal kalender nyata (termasuk kabisat), bukan waktu transaksi WIB/UTC. Tidak ada cap31 hari atau tanggal horizon otomatis. Tanggal sama diterima; akhir sebelum awal ditolak. Cari SKU/nama/kode batch maksimal100 karakter memakai query API existing; karakter LIKE mempertahankan semantik backend.

Pilihan **Ada saldo utuh / terbuka / siap / karantina** diteruskan sebagai with_balance=1; default0. API menyeleksi batch dengan saldo positif pada salah satu empat bucket tersebut, bukan susut kumulatif saja. Karantina tetap ditampilkan meskipun available=0, tanpa menganggapnya layak jual. Kartu reusable StockBatchCard menampilkan snapshot SKU/nama/kode/tanggal, isi kemasan, kondisi, lima bucket dalam biji dan revisi. Tidak ada harga atau join master terkini; tanggal kemasan tidak ditafsirkan sebagai umur simpan hasil kukus.

## Alur baca dan batas state

BatchExpiryRepository melakukan GET endpoint batch existing per halaman, tidak mengambil seluruh katalog untuk memfilter lokal. Envelope store, tiap row store_id, ID unik, tanggal/saldo snapshot, metadata pagination100 dan observed_at bertimezone diperiksa. URL next_page_url dari API diabaikan; pagination dibangun lokal pada path toko yang sama. Urutan tanggal kemasan/ID dari API dipertahankan. Waktu request diamati ditampilkan WIB; saldo dibaca terpisah per halaman, tanpa klaim snapshot atomik lintas pagination.

BatchExpiryController memisahkan filter aktif dan isian yang belum diterapkan. Apply mengembalikan ke halaman1; Next/Previous/Refresh memakai filter aktif, bukan draft form. Isian invalid tidak mengirim request atau mengganti filter/hasil aktif. Saat membaca, hasil sebelumnya dibersihkan dan kontrol baca dinonaktifkan.403/503/network/malformed/scope mismatch menjadi error dengan retry, bukan empty atau stok aman.401 menggunakan callback session/route reset aplikasi existing. Empty hanya ditampilkan setelah respons sukses. Toko nonaktif tetap dapat dibaca bila API mengizinkan; label nonaktif ditampilkan tanpa query katalog operasional lain.

Tidak ada POST, mutasi stok/audit/revision, slot operasi checkout, UUID baru, alokasi FEFO, sale block, recall, push notification atau keputusan mutu/horizon baru. Halaman tidak menautkan kasir ke ledger/mutasi dengan menganggap izin batch sama. D-05 dan UAT SOP tetap terbuka.

## Verifikasi

11 tes baru: default tanpa horizon, kalender kabisat/invalid/reversed/rentang panjang/one-sided, exact query dan historical quarantine/available0/pack/revision, immutable rows, scoped pagination/refresh/filter reset tanpa external URL, foreign envelope/row/pagination denial,403/503/empty/inactive dan401. Widget390px text scale1,5 serta1200px membuka dari Stok dengan canControl=false, memakai inset keyboard280, mengisi filter, menampilkan karantina/tanggal/historical pack dan WIB, mengubah draft tanpa apply lalu pagination memakai filter lama. Widget tambahan memeriksa invalid form tanpa request serta denied read yang tidak menampilkan data lama atau empty success.

API/OpenAPI0.20.0 (77 operasi), policy otorisasi, schema/migrasi dan dependensi tetap. Pengujian widget/server bukan perangkat Android/iOS/browser visual, UAT bisnis atau concurrency PostgreSQL. Fitur ini penelusuran data, bukan sertifikasi keamanan pangan atau enforcement kedaluwarsa.
