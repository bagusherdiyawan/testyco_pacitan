# Ekspor CSV riwayat pergerakan stok

Irisan baca PRD 05-MOV/11-EXP. Pada web **Pesanan dan stok toko → Riwayat pergerakan stok**, terapkan filter lalu pilih **Unduh CSV pergerakan dengan filter aktif**. Ekspor mengambil seluruh catatan yang cocok, bukan hanya halaman100 yang sedang dibuka. Nomor halaman dan perubahan form yang belum diterapkan tidak ikut mengubah unduhan.

## Data dan hak akses

Izin mengikuti LedgerHistory/StockAccess.STAFF: membership organisasi aktif dan assignment toko yang sesuai. Kasir tetap ditolak meskipun dapat membaca katalog batch. Owner/admin pusat tanpa membership operasional toko mitra juga ditolak; tidak ada fallback onboarding untuk ledger. Toko nonaktif masih dapat dibaca oleh staf berizin. Memiliki referensi batch/sumber tidak memberi izin membuka toko/objek lain.

Query export dan reader berbagi scope, filter dan mapper yang sama. Kedua sisi join stock_entries/inventory_batches dibatasi ke toko route; batch_id wajib milik toko itu. Filter source_type, bucket, LIKE SKU/nama/kode batch serta interval waktu tetap mengikuti reader. Export API memerlukan from/to dengan timezone, maksimal31 hari, awal inklusif/akhir eksklusif. Web memakai WibPeriod untuk kalender WIB inklusif; tanpa tanggal default hari ini WIB, satu batas saja ditolak pada ekspor. Legacy GET ledger tanpa periode tetap all-history/pagination100.

Catatan diurutkan created_at lalu ID descending. SKU/nama/kode/kondisi/isi/tanggal kemasan berasal snapshot batch, bukan master Catalog terkini. quantity_pieces adalah perubahan bertanda pada bucket dalam biji, bukan saldo saat ini, saldo berjalan atau jumlah kemasan. Filtered rows tidak boleh dijumlahkan sebagai saldo fisik. Tidak ada harga, omzet, invoice, pengganti atau kredit baru dalam dataset ini.

CSV berkolom entry_id, store_id, batch_id, sku, name, batch_code, condition, pack_size, expires_on, occurred_at_utc, actor_id, source_type, source_id, bucket, quantity_pieces, note. Source_id adalah referensi record sumber, bukan UUID operasi dan tidak dijamin ULID. Tidak ada join nama aktor terkini. Timestamp UTC eksplisit; tanggal kemasan tetap tanggal kalender tersimpan.

## Resource dan format

API `stores/{store}/inventory/ledger/export` mengirim JSON bounded untuk BFF, maksimal10.000 baris/8MiB encoded records. Cursor satu SELECT membaca maksimal10.001, lalu menolak overflow dengan422/pesan mempersempit filter sebelum mengirim data parsial. Tidak dipotong diam-diam atau dipaginasikan oleh frontend. Ekspor bisa berbeda dari layar sebelumnya karena request terpisah; tidak menjanjikan snapshot lintas request/DB.

Web memeriksa envelope/toko/batch terpilih, ID unik, tipe integer/snapshot/timestamp dan ukuran respons sebelum streaming CSV. File stock-ledger.csv tetap, UTF8 BOM, delimiter koma/quoting standar, private/no-store/nosniff. Cookie/token/disposition/URL dari API tidak disalin. Tidak ada query DB API dari web atau file permanen di server.

CsvDownload/CsvText dipakai bersama ekspor audit. Integer yang telah divalidasi tetap numerik, sehingga -8 biji diekspor sebagai -8. Teks seperti catatan "-8" atau prefix formula ASCII/Unicode/control diproteksi apostrof. Koma, kutip dan newline tetap data sel. Note null menjadi sel kosong. Ini format pembacaan spreadsheet, bukan backup/import round-trip. Empty success hanya header; denial/error/overflow/401 bukan CSV kosong.401 memakai invalidasi session existing.

API BoundedExport berbagi batas baris/byte dengan audit; web ExportTime berbagi validasi timestamp ISO/kalender sebelum UTC formatting. Refactor ini tidak mengubah format/scope ekspor audit. Tidak ada POST, perubahan saldo/revisi/ledger/audit/operation receipt, rekap saldo otomatis, kebijakan FEFO/expired, mutasi transaksi atau antrean offline.

## Bukti

5 tes API baru: staff/assignment/tenant/cashier/center/inactive scope, ledger asli buka16→-8/+8 dengan master berubah dan no write, exact batch/UTC-WIB/OR search dan101 matching rows melampaui pagination100,10.000/10.001/8MiB limits serta validasi.6 tes web: whitelist/private/header/cookie/token, angka negatif vs teks berformula, quote/newline/null tanpa harga, wrong store/batch/duplicate/type/time/size fail sebelum CSV, invalid input tanpa API, error/limit feedback/network/401, default WIB/inactive empty dan applied filter link. Smoke HTTP mengekspor receipt16 karantina isi8/referensi yang sama, tanpa revisi stok berubah, dan menolak pusat/kasir.

OpenAPI0.23.0 menambah satu operasi JSON (total81); source_id diperjelas opaque sesuai perilaku lama. Tidak ada migrasi/dependensi/role baru. Widget/server/HTTP bukan bukti browser visual, perangkat fisik, load production, PostgreSQL concurrency atau UAT. CSV native, export besar terjadwal, running balance dan restore/import ledger belum dibuat.
