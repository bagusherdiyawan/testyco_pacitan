# Keputusan arsitektur

Status: fondasi implementasi, aturan bisnis terbuka mengikuti dokumen produk.

## ADR-001: deployment dan batas aplikasi

Monorepo dengan `apps/api` (Laravel 12 JSON API), `apps/web` (Laravel 12 Blade sebagai frontend/BFF), dan `apps/pos` (Flutter mobile/tablet). Web tidak membaca database API. Setiap aplikasi mempunyai dependency lock dan konfigurasi sendiri. Laravel dipilih tanpa paket modular eksternal agar siklus upgrade sederhana. Registry eksplisit mengaktifkan service provider per modul. Semua modul backend berjalan dalam satu proses/transaksi database; ekstraksi service hanya bila kebutuhan operasional membuktikannya.

## ADR-002: batas domain dan kepemilikan data

| Modul | PRD | Pemilik data |
| --- | --- | --- |
| Identity | 01 | organisasi, toko, pengguna, assignment, izin, audit |
| Catalog | 02 | SKU, kemasan, resep, price list |
| Partners | 03 | onboarding dan perjanjian |
| Ordering | 04 | permintaan, approval, purchase order |
| Inventory | 05 | batch, ledger, konversi, waste, opname |
| Sales | 06 | transaksi POS, line snapshot, refund |
| Shifts | 07 | shift, cash movement, closing |
| Promotions | 08 | voucher B2B/B2C dan redemption |
| Fulfillment | 09 | picking, shipment, actual receipt |
| Billing | 10 | invoice, payment allocation, reconciliation |
| Reporting | 11 | read model, metrik grosir/eceran terpisah |
| Assets | 12 | freezer, penempatan, serah terima |
| Reliability | 13 | outbox, notifikasi, sync, observability |

Modul tidak mengubah tabel milik modul lain. Application service menjalankan use case; Domain berisi invariant tanpa HTTP/UI; Infrastructure menerapkan persistence/integrasi; Presentation menangani route/controller/resource. Gunakan kontrak/event internal yang eksplisit, bukan dependensi model lintas modul. Shared kernel dibatasi ID, money dan clock. Jangan membuat abstraksi repository tanpa kebutuhan nyata.

## ADR-003: dynamic module

Backend/web memakai allowlist provider dari konfigurasi. Dependensi wajib diperiksa saat boot; Identity wajib untuk fitur bisnis. Feature flag bukan otorisasi: policy server tetap wajib. Mematikan modul tidak menghapus data/migrasinya. Perubahan konfigurasi membutuhkan rebuild cache/restart, bukan upload kode oleh pengguna. Flutter memakai registry fitur bertipe, feature flag dan navigasi sesuai ukuran layar. Ini modularitas saat build/configuration, bukan hot download executable; Android dynamic delivery/iOS deferred loading belum diterapkan.

## ADR-004: keamanan sebelum pilot

Default deny pada semua API bisnis sampai autentikasi, tenant membership dan policy selesai. Hanya health serta descriptor modul tersedia pada fondasi ini; descriptor tidak mengandung data usaha. Tenant/store berasal dari assignment terverifikasi, bukan percaya header/ID client. Query wajib scoped; uji akses silang mitra pada setiap endpoint. Peran pusat tidak otomatis mendapat seluruh data mitra: ikuti consent/perjanjian. Web menyimpan kredensial server-side dengan cookie HttpOnly/Secure dan CSRF; mobile memakai secure storage dan token berumur terbatas. Audit append-only tanpa sandi/token/PII berlebihan. Rate limit, backup-restore drill, secrets manager dan TLS menjadi gate pilot.

## ADR-005: transaksi, stok, pembayaran dan offline

Uang berupa integer unit terkecil dengan currency; kuantitas dalam biji integer dan snapshot konversi/harga pada line transaksi. Ledger stok append-only berdasarkan penerimaan aktual. Penyesuaian/refund menghasilkan entry kompensasi. Perpindahan frozen ke ready-to-sell mempertahankan lineage batch. Posting penjualan dan ledger dilakukan atomik dengan row locking. Unique key `(organization_id, device_id, operation_id)` serta hash payload menjaga idempotency; key sama/payload berbeda ditolak. Outbox dicatat dalam transaksi yang sama dan consumer deduplicate. Offline belum aktif: rancangan SQLite durable queue, retry backoff, status pending/synced/conflict, tidak mengklaim pembayaran digital lunas saat offline. Kebijakan stok minus/voucher harus diputuskan sebelum sinkronisasi dibuat.

## ADR-006: atomic design dan responsive layout

Token warna, spacing, typography dan radius disimpan dalam `packages/design-tokens/tokens.json`. Blade dan Flutter memakai token hasil generator. Atoms: button/badge/text; molecules: module tile/status panel; organisms: navigation/module grid; templates: responsive shell; pages: komposisi fitur. Domain/use case tidak ditempatkan dalam komponen UI. Web memakai CSS responsive; Flutter memakai LayoutBuilder, batas tablet 720 logical pixels. Token bisa dibagikan; widget Blade dan Flutter tetap native tiap framework. Accessibility: semantic labels, focus keyboard, target sentuh 48px, contrast dan text scaling wajib diuji pada fitur berikutnya.

## ADR-007: implementasi identitas dan katalog awal

API memakai Laravel Sanctum: token acak tersimpan sebagai hash, berlaku delapan jam, pencabutan token per logout dan seluruh token pada perubahan password/role/assignment atau penonaktifan akun. Membership menentukan role per organisasi; role owner/admin/partner_lead mencakup toko organisasi, peran lain wajib store assignment. Setiap permintaan memeriksa akun dan organisasi aktif. Toko nonaktif tetap terlihat sebagai metadata tetapi katalog operasional ditolak.

Onboarding metadata diizinkan untuk admin pusat. Akses katalog bisnis mitra memerlukan membership mitra; izin admin pusat tidak menggantikannya. Audit untuk pusat tanpa membership menyaring hanya aksi onboarding/identitas, tidak menampilkan harga produk mitra. API tidak menyediakan endpoint mengubah/menghapus audit. Mutasi dan audit dicatat dalam satu transaksi DB.

Web menggunakan bearer token melalui HTTP server-side; browser hanya memegang cookie session dan CSRF token. Flutter menyimpan token di memori selama proses hidup. Sesi persisten/secure storage belum diterapkan. Token yang tidak dapat direvoke saat jaringan putus tetap berakhir maksimal delapan jam.

SQLite adalah database development irisan identitas/katalog. PostgreSQL tetap target untuk validasi concurrency sebelum ledger/keuangan. Server development container memakai `artisan serve --no-reload` untuk mempertahankan environment override. Runtime web mengatur NO_PROXY/no_proxy untuk API internal agar trafik Docker tidak dialihkan ke proxy egress; tidak digunakan sebagai server production.

## Status implementasi

Tersedia: Identity (login/logout, password change, org/toko/user/role/assignment/status, audit) dan Catalog (CRUD SKU per organisasi, konversi/dua harga, katalog toko aktif tanpa harga grosir). Laravel web dan Flutter tersambung API. Daftar lengkap batas dan fitur tertunda ada pada README. Irisan awal PRD 04/05/09 kini juga tersedia, sebagaimana ADR-008 di bawah. Penerimaan penuh seluruh tahap dan POS operasional belum selesai.

## ADR-008: pesanan, pengiriman dan ledger stok awal

Modul Ordering memiliki pengajuan, baris snapshot dan versi status; Fulfillment memiliki pengiriman dan hasil penerimaan; Inventory memiliki batch serta stock_entries. Fulfillment mengubah status order melalui OrderWorkflow, dan stok melalui StockLedger, bukan menulis tabel domain tersebut langsung. Shared Operations menyimpan hasil per UUID operasi dalam transaksi DB yang sama dengan perubahan domain. Key yang sama dengan aktor/scope/payload berbeda ditolak 409; replay identik mengembalikan hasil awal. Lock order mendahului lock batch dengan urutan ID batch deterministik. Constraint ledger per sumber/bucket menjadi lapisan pencegah duplikasi tambahan.

State: awaiting_approval → approved → processing → dispatched → partially_received/completed. Pimpinan dapat menolak, memperbarui dan mengajukan ulang sebelum approval. Hanya pimpinan mitra yang menyetujui pembelian mitra; toko sendiri memerlukan owner/admin pusat dan dicatat sebagai internal_transfer dengan nilai komersial nol. Draft tidak masuk inbox pusat dan tidak mereservasi stok. Edit sesudah approval ditolak; perubahan/reapproval sesudah persetujuan belum dibuat.

Katalog B2B pusat aktif kini dapat dilihat pemohon berwenang untuk toko aktif. Setiap baris memilih SKU pusat dan SKU lokal milik organisasi penerima secara eksplisit. Isi kemasan dan kondisi harus sama; transfer internal wajib memakai SKU yang sama. Mapping ini per baris order, bukan auto-match berdasarkan nama. SKU, nama, kondisi, harga, konversi, dan alamat tujuan dibekukan. Jika harga/konversi berubah sebelum approval, pengguna harus memperbarui pengajuan dan meninjau ulang.

Pusat memilih gudang sendiri saat menerima order. Petugas gudang hanya melihat/mengirim order dari gudang yang ditugaskan; owner/admin pusat dapat memproses organisasi pusatnya. Batch dipilih manual. Dispatch mengurangi usable_pieces sekali, hanya sampai sisa jumlah order dan stok aktual. Ini guard agar operasi yang belum disetujui tidak menciptakan stok minus; bukan keputusan bahwa seluruh kebijakan stok bisnis sudah final. Intake fisik mandiri hanya untuk gudang pusat, tidak menjadi pintasan approval bagi toko mitra.

Penerimaan wajib mengisi setiap baris kirim sekali, dengan good_packs dan damaged_packs. Selisih/kerusakan membutuhkan catatan. Good masuk bucket usable; damaged masuk quarantined. Tidak ada sertifikasi mutu otomatis. Jumlah pesanan, jumlah kirim dan jumlah diterima berbeda dan dipertahankan. Beberapa kiriman dapat memenuhi sisa order; order baru completed ketika seluruh jumlah diterima baik. Barang hilang/rusak tidak otomatis membuka kuota kirim pengganti atau kredit; kasus tersebut tetap partially_received sampai fitur resolusi yang berizin dibangun.

SQLite digunakan untuk development dan tes atomisitas saat ini. Ledger sudah diimplementasikan untuk intake/dispatch/receipt; PostgreSQL, uji concurrency multi-koneksi, backup-restore, serta UAT masih menjadi gate sebelum pilot. Belum ada POS debit, buka kemasan, pengukusan, opname, retur, kredit, FEFO otomatis atau peringatan stok.
