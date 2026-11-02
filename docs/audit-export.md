# Ekspor CSV audit organisasi

Irisan ekspor berizin PRD 01-AUD/11-EXP. Pada web **Organisasi → Jejak audit**, terapkan periode dan filter, lalu pilih **Unduh CSV audit dengan filter aktif**. Unduhan memakai tanggal/aksi/objek/pelaku yang sudah diterapkan, bukan isian form yang belum dikirim atau nomor halaman. Semua catatan yang cocok pada periode diambil, tidak hanya halaman50 yang sedang dibuka.

## Scope dan batas

API memakai query/otorisasi AuditHistory yang sama dengan pembacaan biasa. Pengelola dengan membership aktif melihat organisasi yang diizinkan. Admin pusat tanpa managing membership mitra hanya mendapat allowlist onboarding existing, termasuk user.reactivated; filter tidak membuka audit operasional mitra. Kasir, kepala toko dan gudang tetap ditolak. Catatan global organization_id=null tidak termasuk. CSV bukan pemilih seluruh organisasi dan tidak menggabungkan data lintas organisasi.

API membutuhkan from/to bertimezone, from inklusif/to eksklusif, maksimal31 hari. Kalender web WIB inklusif dikonversi oleh WibPeriod seperti pembaca audit. Tanpa tanggal pada route web, default hari ini WIB; satu batas saja ditolak untuk ekspor. Filter tambahan exact action/subject_id/actor_id tetap sama; token, scope, organization_id, URL, page dan query lain tidak diteruskan. Pembaca lama tetap mendukung all-history/pagination50 tanpa periode pada API.

Batas teknis ekspor adalah10.000 catatan dan8 MiB jumlah JSON-encoded records (termasuk snapshot/metadata baris). Cursor satu SELECT berurutan ID descending membaca maksimal10.001 dan berhenti pada batas ukuran. Kelebihan menghasilkan422 dengan pesan mempersempit filter sebelum ada file/data parsial; tidak silent truncation, antrean background atau pembagian file. Browser diarahkan kembali dengan pesan batas; hasil melebihi batas bukan CSV kosong. Batas ini pengamanan resource, bukan definisi kebutuhan bisnis maksimum.

Ekspor dapat berbeda dari layar sebelumnya jika ada event baru. Tidak menjanjikan snapshot yang sama lintas request/pagination atau telah diuji pada concurrency PostgreSQL.

## Format unduhan

API GET `organizations/{organization}/audit/export` mengirim JSON bounded dengan organisasi, visibility, observed_at, row_count dan rows; frontend tetap melalui Backend HTTP. Tidak ada query DB API dari web. BFF memeriksa envelope, row organisasi, ID unik, tipe/timestamp/snapshot, jumlah dan ukuran respons sebelum memulai stream CSV. URL pagination serta cookie/token/header nama file dari API tidak disalin.

CSV UTF8 dengan BOM, delimiter koma dan quoting standar. Kolom:

| Kolom | Isi |
| --- | --- |
| audit_id | ID event audit, bukan UUID operasi |
| organization_id | Organisasi yang diizinkan pada route |
| visibility | organization atau onboarding dari API |
| occurred_at_utc | Timestamp event dengan offset UTC eksplisit |
| actor_id | ID aktor tersimpan; kosong jika tidak tersedia |
| action / subject_id | Aksi dan objek historis |
| before_json / after_json | Teks snapshot tersimpan, termasuk legacy; null ditulis literal null |

Tidak mengambil nama/harga terkini dari master atau merekonstruksi nilai saldo. Koma, kutip dan baris baru dipertahankan melalui fputcsv. Teks yang dapat dibaca spreadsheet sebagai formula diberi awalan apostrof, termasuk prefix Unicode/whitespace/control/full-width dan tab/newline. Ini format pembacaan spreadsheet, bukan backup/import round-trip yang menjamin setiap byte identik dengan storage.

File bernama tetap audit.csv, Content-Type text/csv; charset=UTF-8, private/no-store dan nosniff. Tidak ada file permanen di server. Empty success hanya header; denial, validasi, scope mismatch, oversized, koneksi gagal dan401 tidak menjadi CSV kosong.401 mengikuti invalidasi session existing.

## Arsitektur dan bukti

AuditHistory.scope dan entry mapping dipakai oleh reader/export agar authorization/allowlist/filter tidak bercabang. AuditQuery berbagi validasi API; AuditSearch berbagi whitelist/periode web. Formatter CsvText di web menangani teks, bukan policy otorisasi. Link memakai halaman audit/atomic components existing. Export hanya GET baca: tidak menulis event ekspor ke audit itu sendiri, receipt, ledger, saldo atau transaksi.

5 tes API baru: deny role/tenant/global-null, onboarding/full scope dan organisasi nonaktif, exact WIB boundaries dan51 matching rows, snapshot/no-write/no master join, tepat10.000 boleh/10.001 ditolak,8MiB ditolak, empty serta validasi periode/filter.7 tes web baru: private scoped download/query/header/cookie boundary, CSV formula/quotes/newline/null/UTC, default WIB dan applied-link filters, invalid dates/types tanpa API, foreign/duplicate/malformed/oversized response, error/limit message/network/401 dan onboarding empty. HTTP nyata terisolasi mengekspor satu sale.completed Rp12.000 untuk pimpinan mitra dan header-only bagi pusat onboarding, tanpa menambah audit.

OpenAPI0.22.0 menambah satu operasi JSON export (total80) dan memperjelas actor_id nullable yang memang sudah didukung API. Tidak ada migrasi, dependensi atau perluasan role/allowlist baru. Browser interaksi/visual, perangkat nyata, load production, PostgreSQL concurrency dan UAT belum diverifikasi; unduhan CSV native/ekspor besar terjadwal belum dibuat.
