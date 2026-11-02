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

## ADR-009: pengolahan stok dan pengajuan hitung fisik

Batch sekarang menyimpan utuh (usable), beku terbuka, siap jual, karantina dan susut kumulatif. Nama usable_pieces lama dipertahankan dengan arti stok di kemasan utuh; available_pieces baru adalah utuh + terbuka + siap, bukan sertifikasi mutu. Migrasi menambah counter nol tanpa mengubah saldo/histori lama. Batch tidak dipecah menjadi SKU baru saat kukus: tahap ini hanya perubahan keadaan biji dari SKU/batch yang sama.

StockTransformation menghitung delta murni: open memindahkan packs × pack_size historis ke opened; cook memindahkan input opened ke output ready dan selisih waste. Output aktual wajib diisi petugas, tidak diperkirakan dari resep. Hasil nol diperbolehkan dengan alasan agar kegagalan proses tidak disembunyikan. Utuh/karantina tidak dapat menjadi input cook. Susut proses langsung menyertai kejadian fisik berwenang; susut terpisah merupakan proposal yang harus direview.

StockLedger.apply menjadi satu jalur tulis saldo/ledger dan mensyaratkan transaksi aktif. Semua jalur credit/dispatch lama juga melewatinya. Revisi batch bertambah setiap pergerakan, termasuk perubahan bucket yang bukan sasaran hitung. Delta sumber tidak boleh membuat saldo negatif, dan utuh harus tetap kelipatan kemasan historis. Ledger proses merekonsiliasi input = output + susut. Rollback migrasi ditolak setelah kontrol stok digunakan untuk mencegah kehilangan saldo/histori; perbaikan maju atau restore perlu ditinjau.

Opname/susut terpisah menyimpan saldo awal, hasil fisik, delta dan snapshot_revision; belum mengubah stok. expected_revision pada submit mengikat snapshot yang dilihat petugas. Approval memerlukan versi proposal serta revisi batch yang masih sama. Pergerakan apa pun membatalkan kelayakan snapshot; tidak ada penimpaan diam-diam atau pembaruan baseline otomatis. Dua proposal dari snapshot sama tidak dapat menerapkan dua penyesuaian berturut-turut setelah saldo berubah.

Kontrol awal memakai dua pelaku berbeda: kepala toko/gudang/pimpinan boleh mengusulkan; pimpinan mitra untuk organisasinya atau owner/admin pusat untuk pusat boleh meninjau proposal orang lain. Pengusul hanya dapat membatalkan proposal sendiri. Reviewer tidak otomatis mendapat akses dari kewenangan onboarding pusat. Pemilihan kontrol ini harus divalidasi dalam UAT, bukan diasumsikan sebagai perjanjian bisnis final.

Stok opname biasa dapat menambah/mengurangi sesuai hitungan disetujui tanpa menyimpulkan penyebab susut. Proposal waste hanya mengurangi bucket dan menambah counter waste. Counter waste tidak dapat dihitung ulang/dihapus melalui opname. Nilai fisik sama dengan sistem menghasilkan catatan review tanpa pergerakan ledger palsu. Seluruh mutasi tetap memakai UUID idempoten dan audit.

Web menyediakan proses/submit/review dan riwayat; Flutter menampilkan counter dan revisi baru. Workflow native Flutter kini tersedia (ADR-012). Pelepasan karantina, pembalikan proses, kebijakan masa simpan siap jual dan ambang susut otomatis belum tersedia. Masa simpan hasil kukus tidak diturunkan dari expiry kemasan. PostgreSQL/concurrency dan pilot UAT tetap belum diverifikasi.

## ADR-010: POS tunai, draft server dan kas shift

Shifts memiliki cash_shifts, active_shift_locks dan cash_movements; Sales memiliki sale_intents, sales, sale_lines dan sale_allocations. Sales meminta RetailPricing untuk snapshot harga retail dan SaleStock untuk inspeksi/debit batch melalui StockLedger. Kas diperbarui melalui ShiftBook. Shifts memakai interface ShiftBlockers; provider Sales memasang implementasi pemeriksa draft aktif agar Shifts tidak membaca tabel Sales langsung.

Lock order: operation receipt → shift → sale intent → batch berurutan ID. Penjualan dan close/movement mengunci shift yang sama. Constraint unik satu sale per intent dan satu active lock per kasir/toko menambah guard idempotensi selain UUID Operations. Draft server tidak mereservasi stok; debit dan harga diperiksa ulang pada pembayaran. Semua penjualan, snapshot, ledger, kas, audit dan hasil operasi berada dalam transaksi DB yang sama. Replay paid intent mengembalikan struk awal dengan nominal sama meskipun UUID baru/shift sudah tutup; nominal berbeda ditolak.

Harga POS diturunkan dari retail_price per biji; pack/portion memakai konversi snapshot, bukan harga grosir B2B. Sumber stok dipilih eksplisit, bukan FEFO otomatis. Biji/porsi hanya opened/ready; pack hanya utuh dengan pack_size historis cocok. Receipt menyimpan identitas dan nilai historis, tidak menghitung ulang dari master. Reprint GET tidak mengubah stok/kas.

Default teknis awal: satu shift per kasir/toko, 20 draft aktif, label perangkat tanpa binding perangkat dan batas rupiah Rp1 triliun. Ini harus direview pada UAT. Pembayaran/cancel/close milik kasir pemilik; manual cash movement dan laporan untuk pengelola scope toko. Close memerlukan versi kas terakhir, tidak ada draft pending, hitungan fisik dan alasan selisih. Tidak ada close otomatis saat ganti tanggal atau penetapan hari bisnis; laporan API interval eksplisit, web memilih tanggal WIB.

Flutter menyimpan UUID/body pembayaran selama proses hidup ketika hasil jaringan belum diketahui. Recovery setelah restart memakai draft/struk server, bukan antrean pembayaran lokal. Layout mobile/tablet berbagi controller dan organism SaleReceipt; web memakai organism Blade sendiri. QRIS, refund, promo, offline dan printer fisik tidak diaktifkan. SQLite tidak membuktikan concurrency production; PostgreSQL dan UAT tetap gate pilot.

## ADR-011: ambang stok dan peringatan saat halaman dimuat

Inventory memiliki inventory_minimums unik per toko/SKU, nullable threshold, version dan metadata pengubah/alasan. Shared Operations menyimpan replay hasil dan audit dalam transaksi yang sama. Urutan lock konfigurasi: operation receipt → store → active product → minimum row; lock store menserialisasi pembuatan awal agar dua version 0 tidak menimpa. Tidak ada penulisan saldo, purchase order atau billing. Query Catalog/Identity hanya proyeksi baca; harga tidak disertakan.

Tidak ada ambang bawaan. Null mematikan tanpa menghapus riwayat; zero adalah threshold aktif. Peringatan strict available < minimum dengan available = total usable + opened + ready seluruh batch toko/SKU; quarantine/waste dikecualikan. SKU aktif tanpa batch bernilai nol. Jumlah ini bukan stok siap saji, pemeriksaan expiry atau sertifikasi mutu. Pengelola organisasi/store yang berwenang mengubah, kasir/gudang assigned hanya membaca; tidak ada onboarding override pusat.

Peringatan adalah read projection saat dimuat, bukan event terpersistensi yang membuktikan penyeberangan ambang. UI memperlihatkan breakdown dan tombol refresh; web mencari/paginasi, Flutter dapat melihat low-only atau semua SKU. Shared atomic molecule menampilkan nilai server. Reliability tetap planned: push/email/inbox/read receipt, auto-reorder dan prediksi belum diimplementasikan. Tiap halaman live dapat berubah antarpagination. PostgreSQL concurrency serta UAT tetap gate tersendiri.

## ADR-012: kontrol stok native dan pemeliharaan snapshot

Inventory mengekspos control-context sendiri agar Flutter tidak bergantung pada Ordering untuk capability. Role UI hanya menentukan navigasi; context dan semua mutasi tetap memakai StockAccess server. Context toko nonaktif menutup seluruh capability mutasi, sementara riwayat tetap berizin. Count response menentukan can_review/can_cancel per aktor; is_stale menutup approval tetapi tetap memungkinkan reject/cancel berizin.

Flutter memisahkan DTO stok/count/process, StockControlRepository, controller dan page/form. Molecules/organisms menerima nilai/callback tampilan. Setiap pemilihan batch menyimpan snapshot utuh beserta revisinya. Refresh membuang pilihan, memaksa pemeriksaan fisik baru; konflik tidak mengganti expected_revision diam-diam. Load gagal menutup mutasi dari data lama.

PendingStockOperation menyimpan path/body/UUID immutable di memori ketika hasil POST tidak diketahui. Tindakan baru tidak mengganti pending request; retry mengirim ulang identik. UI menahan pop selama busy/pending. Sukses POST dan kegagalan refresh dibedakan agar sukses tidak dianggap perlu proses baru. Idempotensi server dan revisi batch tetap guard final, bukan disable tombol saja.

Tidak ada persistensi operasi native lintas restart atau antrean offline. Setelah terminasi paksa ketika hasil belum diketahui, rekonsiliasi saldo/riwayat/audit diperlukan sebelum mencatat ulang. Penyimpanan aman perangkat dan aturan offline tidak ditetapkan oleh irisan ini. Ledger, SOP mutu dan otorisasi independent-review tidak berubah. Widget mobile/tablet diverifikasi; build/perangkat fisik dan PostgreSQL concurrency masih belum diverifikasi.

## ADR-013: laporan tunai melalui read model Sales

Reporting memiliki access/period/presentation dan bergantung pada Identity/Sales. CashSalesReadModel milik Sales membaca fakta sale/line tanpa menulis domain stok/shift. Tidak ada materialized view atau migrasi baru. Scope mengikat organization_id dan daftar store yang diizinkan oleh membership/assignment aktif; filter asing ditolak dan onboarding pusat tidak menjadi override. Toko nonaktif tetap dapat dilaporkan; tidak ada agregasi pusat atas POS mitra tanpa membership.

Summary menghitung total dari query grup toko yang sama; nol ditambahkan bagi toko dalam scope yang belum bertransaksi. Hanya completed cash IDR; tidak memasukkan draft, cash movement, B2B, transfer, utang atau refund/non-tunai yang belum tersedia. Produk memakai snapshot ID/SKU/nama/kondisi, jumlah base pieces dan line_total; receipt count per produk tidak aditif. Nama/status kartu toko adalah metadata terkini; export sale memakai nama historis.

Periode immutable menjaga [from,to), offset wajib di boundary, maksimal 31 hari. Web/native menerjemahkan tanggal kalender WIB inklusif akhir ke interval eksplisit. Hari bisnis/rollover shift tidak ditetapkan. Summary/products/export adalah observasi request terpisah, bukan snapshot lintas query. Waktu server ditampilkan tanpa klaim offline backlog kosong.

Ekspor materializes maksimal 10.001 fakta sale dan menolak bila di atas 10.000 sebelum streaming; tidak ada count-then-query race atau truncation diam-diam. CSV BOM/quoting standar, formula-like text diberi apostrof dan uang tetap integer. API/BFF private/no-store; CSV attachment nosniff. BFF menyalin body dan header yang ditentukan sendiri, tidak cookie/kredensial upstream. Audit mencatat export prepared dengan scope/count, bukan konten file atau bukti download selesai.

Atomic organisms CashSalesMetrics pada Blade/Flutter menyajikan data server. Registry Laravel dan FEATURE_REPORTING pada Flutter mengatur aktivasi fitur, sementara izin tetap server. Initial reporting tidak meliputi B2B, piutang, margin, refund, async export atau native file download. PostgreSQL/concurrency, load dan UAT tetap gate tersendiri.

## ADR-014: keputusan dan penerimaan pesanan native

Order detail menambahkan recipient_actions serta fulfillment_enabled tanpa mengubah workflow/migrasi stok. RecipientOrderActions memadukan role membership, access_side, toko aktif, OrderState dan status provider Fulfillment. Metadata lama can_approve tetap kompatibel; native tidak menganggap boolean role lama sebagai izin transaksi. Supplier-view tidak menjadi recipient; status akhir/inactive menutup tindakan sesuai use case. Workflow API tetap guard final dalam transaksi.

Native memisahkan DTO order/line/shipment, OrdersRepository, RecipientOrderController dan page/form. Setiap dialog menangkap versi yang ditinjau. Receipt memilih snapshot shipment yang masih dikenal controller dan mengirim semua shipment_line_id tepat sekali dengan good/damaged packs eksplisit. Tidak ada prefill jumlah diterima dari jumlah kirim atau penulisan delta/ledger dari UI. Catatan kondisi wajib per baris selisih. Fulfillment tetap mencatat fisik baik ke usable dan rusak ke quarantine secara atomik melalui Inventory.

PendingRecipientOperation menyimpan JSON body immutable, UUID dan path di memori; getter body memberi salinan nested sehingga perubahan UI/refresh tidak mengubah retry. Semua aksi baru ditahan sampai hasil operasi diketahui; PopScope menahan navigasi selama busy/pending. Conflict/denial memerlukan refresh dan pemilihan ulang; sukses POST dibedakan dari kegagalan refresh. Setelah kembali dari detail, daftar order diperbarui.

Fulfillment disabled membuat native melewati shipments endpoint dan mematikan receipt. Response lama tetap terbaca tanpa menebak capability. Tidak ada antrean persisten lintas restart: rekonsiliasi status/shipment/audit wajib sebelum mengganti operasi yang tidak diketahui setelah terminasi paksa. Aturan pengganti/kredit, offline atau finansial baru tidak dibuat. Pengajuan baru kini tersedia (ADR-015); amend/dispatch native serta build perangkat/PostgreSQL concurrency tetap pekerjaan berikutnya.

## ADR-015: composer pengajuan, review fingerprint dan batas sesi

Ordering menyediakan read-only preview dari builder snapshot yang sama dengan create. Preview mengunci target store/produk selama transaksi singkat tanpa menulis order/audit/operasi atau mereservasi stok. review_hash mengikat tujuan, metode, catatan, kind/pemasok dan seluruh snapshot produk/harga/konversi/kuantitas. Native selalu mengirim hash saat create; API lama tetap kompatibel tanpa hash dan selalu memakai harga server. Fingerprint bukan secret, signature, price TTL atau authorization.

Create memeriksa ulang snapshot dalam transaksi Operations sebelum menyimpan. Hash berbeda ditolak tanpa claim/order. Replay committed UUID mengembalikan hasil awal sebelum revalidasi master, sehingga kehilangan respons tidak membuat order kedua atau harga baru. Perubahan alamat/target dilihat dari row store yang dikunci. Tidak ada perubahan state pengajuan/approval/penerimaan atau aturan finansial baru.

DTO/repository/composer controller dan atomic OrderLineEditor/OrderReviewCard terpisah. Pasangan SKU eksplisit dengan kondisi/isi cocok; internal transfer identitas sama dan harga nol dari server. Refresh menyimpan intent ID/kuantitas, membuang quote belum dikirim dan menandai mapping tidak valid, tanpa mengganti SKU. Payload yang telah ditinjau disimpan immutable dan UUID dibuat hanya saat konfirmasi pertama.

Setelah hasil unknown, request nested/UUID dipertahankan walau refresh atau penolakan berikutnya terjadi; tidak diputar untuk melewati konflik. Definitive first-attempt rejection dapat meminta review baru. ACK success disimpan sebagai createdId sebelum navigasi detail. Tidak ada antrean persisten: penutupan paksa/session change memerlukan rekonsiliasi manual daftar/audit sebelum pengganti.

MaterialApp memakai auth-boundary key agar navigator/data halaman lama dibuang ketika sesi berakhir, termasuk ketika PopScope transaksi sedang menahan back. Ini tidak mereplay operasi setelah login dan bukan offline recovery. Widget test membuktikan unauthorized response mengembalikan login serta sesi berikutnya membaca toko kembali tanpa membawa route lama. Perubahan sebelum persetujuan kini tersedia (ADR-016); dispatch native, perangkat fisik, PostgreSQL concurrency dan UAT tetap belum diverifikasi.

## ADR-016: composer amendment dengan versi asal

Preview amendment memakai approver/state/version guard yang sama dengan PUT, dengan lock order → target → produk, lalu builder snapshot yang sama dengan create. Fingerprint mengikat original order_id/version serta seluruh snapshot review. Hash diverifikasi sebelum line deletion, sehingga konflik tidak meninggalkan partial replacement atau operation claim. No-hash PUT lama tetap kompatibel; server tetap harga/izin/state otoritatif. Tidak ada perubahan setelah approval/reapproval baru.

RecipientOrderActions menambah amend; native tidak menebaknya dari can_create atau legacy can_approve. Composer dibagi create/amend dengan repository memilih endpoint preview dan POST/PUT. Editable seed berasal dari ID product yang tersimpan. Original version dipin selama sesi composer; refresh yang menemukan versi berbeda menutup mutasi tanpa rebase otomatis. Reset eksplisit membuang draft lalu mengambil seluruh seed baru, menaikkan composition epoch untuk mengganti state field dan menyelaraskan catatan.

Unknown PUT tetap memakai immutable nested payload/version/UUID; reset tidak boleh membuangnya. ACK hanya membuka ID sama lalu membaca status terbaru. Audit before/after mempertahankan riwayat draft, tanpa mutasi stok/pembayaran. Fitur diuji pada SQLite/widget/HTTP; tidak ada migration/dependency baru atau klaim PostgreSQL, UAT perangkat, dispatch native maupun offline durable.

## ADR-017: kas manual native memakai domain Shifts yang sama

Flutter menambahkan modul Shifts berflag eksplisit, daftar berhalaman, detail kas/riwayat dan review kas masuk/keluar. GET detail menyediakan can_record_movement berdasarkan role scoped, toko aktif dan shift terbuka; can_manage tetap informasi pengelolaan umum dan bukan izin mutasi. Backend tetap memeriksa ulang assignment, status, versi, nominal dan audit melalui ShiftBook/Operations, tanpa migrasi atau aturan omzet baru.

Controller menyimpan UUID/path/body JSON immutable pada respons tidak pasti, menahan refresh/pop/new operation, dan tidak membuang pending setelah denial berikutnya. Setelah ack, GET detail terkini menggantikan saldo dari replay historis; kegagalan GET tidak mengirim ulang mutasi yang sudah diakui. Refresh eksplisit mengosongkan isian untuk review baru. Pending belum persisten lintas restart/sesi. NavigationBar mobile membatasi lima tujuan dengan overflow selector agar modul tetap terjangkau; rail tablet tetap eksplisit. Lihat docs/native-shift-cash.md dan bukti pengujian.

## ADR-018: konfigurasi minimum native dengan lookup ID tunggal

Inventory menambahkan GET detail stok minimum dari proyeksi scoped/tanpa harga yang sama dengan daftar; lookup memakai exact product_id pada toko aktif dan produk aktif, tidak bergantung nama SKU atau filter/pagination daftar. GET tidak menulis konfigurasi/audit/operasi/stok. Response membawa store, can_manage, version dan observed_at; native default-deny bila metadata hilang/tidak cocok. POST lama tetap final guard dan idempotent/versioned, tanpa migrasi atau kebijakan ambang baru.

Native memisahkan DTO/repository/controller/editor serta atomic review, mempertahankan path/body JSON/UUID immutable setelah hasil unknown, termasuk denial berikutnya. Konfirmasi memakai versi yang ditinjau, konflik tidak direbase. Refresh eksplisit mengganti isian dari konfigurasi server dan mengosongkan alasan. Setelah ACK, GET terkini diperlukan karena replay dapat mengembalikan konfigurasi historis sebelum perubahan berikutnya; refresh gagal tidak menjadi POST pengganti. Zero tetap enabled, disable mengirim null; tidak ada angka default atau auto-order. Pending masih memory-only, bukan offline queue. Daftar memakai pencarian dan page eksplisit agar metadata/waktu observasi sesuai halaman. Lihat docs/native-stock-minimum.md.

## ADR-019: read model gudang scoped dan dispatch native

Fulfillment menyediakan inbox/context terikat toko sumber, tanpa membuka draft penerima ke pemasok. Owner/admin pusat dapat memilih toko pusat aktif berbeda dari tujuan untuk order approved; warehouse hanya order yang source-nya assigned. Context memakai supplier scope, original order/version, source yang cocok, target/source aktif, status dan sisa sent untuk can_process/can_dispatch. Candidate batch hanya stok utuh sumber dengan product/pack snapshot sesuai. Context read-only mengunci order selama observasi singkat; bukan reservasi atau stock_revision dispatch guard baru. Mutasi tetap workflow/Operations/StockLedger lama.

Identity GET stores menambahkan organization_type dari organisasi toko yang sudah berizin, tanpa dependensi Fulfillment. Native registry menggabungkan metadata tipe/role dengan FEATURE_FULFILLMENT sebagai petunjuk navigasi; API selalu final guard. Legacy metadata tidak menampilkan modul. DTO/repository/controller/inbox/detail terpisah dan molecules batch/review hanya render. Penetapan sumber berasal pilihan toko utama dengan dialog konfirmasi, bukan auto-pilih gudang.

Alokasi manual dapat multi-batch dan parsial. Remaining berdasarkan sent, bukan good receipts; rusak/hilang tidak membuka replacement quota. Review menyimpan original IDs/version dan immutable JSON/UUID/path/ringkasan; unknown menahan edit/refresh/pop, denial berikutnya tidak memutar operasi. ACK memuat GET terkini; GET gagal tidak menjadi POST pengganti. Refresh eksplisit membuang jumlah fisik dan petugas/catatan via epoch. Pending belum persisten lintas restart. Tidak ada FEFO, expiry enforcement, retur/kredit, reapproval, reservasi atau aturan finansial baru. Lihat docs/native-warehouse.md serta bukti pengujian.

## ADR-020: penerimaan awal native dengan snapshot konversi server

Inventory mengekstrak intake ke WarehouseIntake, memakai guard sumber center owner/admin/assigned warehouse serta StockLedger/Operations/Audit yang sama dengan legacy. Katalog penerimaan aktif price-free berhalaman dan membawa exact store/can_intake; tidak bergantung Ordering/Fulfillment. Native memakai metadata toko sebagai navigation hint, API tetap final authorization. Preview read-only serta record berbagi snapshot gudang/produk/konversi/batch/expiry/kemasan/note. Hash opsional pada POST untuk legacy, wajib dari native, diperiksa sebelum stock/batch/audit writes; bukan signature/izin/TTL.

Committed UUID replay kembali sebelum revalidasi produk sehingga master yang berubah/nonaktif tidak membuat intake kedua. Native menahan path/body/review/UUID immutable setelah unknown, termasuk denial berikutnya. ACK menampilkan batch ID dan delta penambahan dari review, bukan saldo replay historis; kembali ke stok memuat data terbaru dan kegagalan GET tidak menjadi POST pengganti. Refresh/edit membuang quote belum dikirim; konflik membutuhkan pemeriksaan fisik/review baru. Tidak ada defaults fisik, auto-expiry block, penerimaan mitra di luar order, retur atau kebijakan finansial baru. Pending belum persisten lintas restart/sesi. Lihat docs/native-warehouse-intake.md.

## ADR-021: ledger read model scoped dengan waktu eksplisit

Inventory memperkaya endpoint ledger lama lewat LedgerHistory, tanpa mengubah entry/izin atau menulis saldo/audit. Query entry serta join batch sama-sama scoped ke toko; metadata produk berasal snapshot batch, bukan Catalog terbaru. Original fields/created_at dan pagination 100 dipertahankan; occurred_at UTC eksplisit mencegah interpretasi raw timestamp sebagai zona lokal perangkat. Source ID tetap referensi asal ledger, bukan operation UUID atau izin membuka objek modul lain.

Filter batch milik toko, sumber, bucket, q dan pasangan interval ISO bertimezone bersifat opsional. Legacy tanpa interval tetap seluruh riwayat paginated. Native memakai kalender WIB inklusif akhir dan batas 31 hari, diterjemahkan ke [from,to). Sorted created_at/id deterministik pada dataset tetap; halaman live bukan snapshot konsisten lintas request. Tidak ada running balance atau saldo dari penjumlahan subset.

Native repository/controller/filter DTO/page dan card terpisah, memakai registry Inventory yang sama. Navigasi staff merupakan hint, API tetap final guard termasuk deny cashier/tenant override dan izin baca riwayat toko nonaktif. Loading/error tidak menampilkan data lama sebagai hasil filter baru atau kegagalan sebagai nol. Isian filter belum diterapkan tidak mengubah query/pagination/refresh; batch link memakai exact ID. Tidak ada migrasi/dependensi, ekspor, mutasi atau pemulihan UUID/offline. Lihat docs/native-stock-ledger.md.


## ADR-022: riwayat struk native terpisah dari checkout

Sales memperkaya GET riwayat lama lewat ReceiptHistory: query scoped organisasi/toko dan pemilik kasir kecuali pengelola berwenang, filter nomor dan pasangan interval timezone maksimal 31 hari. Pagination 50 dan urutan ID lama dipertahankan; tanpa interval tetap all-history untuk kompatibilitas web. Envelope store/scope tambahan menjadi metadata presentasi, bukan sumber otorisasi. Nilai historis berasal Sales tanpa join master, tidak ada mutasi atau agregasi omzet.

Flutter memakai core WibDateRange, repository POS dan controller baca tersendiri; molecule ReceiptHistoryCard dan organism SaleReceipt dipakai bersama. Membaca struk tidak menulis state checkout atau memutar UUID. Entry dari header POS tersedia tanpa shift, tetapi dinonaktifkan ketika sibuk/operasi belum pasti. Filter belum diterapkan tidak memengaruhi pagination, data lama dibersihkan saat load/error, retry detail menjaga exact ID, dan URL pagination eksternal diabaikan. Baca pada toko nonaktif tetap diizinkan bila membership/assignment dan organisasi aktif. Halaman live bukan snapshot konsisten lintas request. Lihat docs/native-receipt-history.md.


## ADR-023: perubahan sandi native melalui batas autentikasi

Identity native memakai endpoint auth/password yang sudah ada tanpa menambah module registry bisnis, migrasi, dependensi atau aturan password server. PasswordRepository mengirim body persis; controller hanya menyimpan busy/error, bukan password/request queue. ACK menghapus sesi lokal setelah backend mencabut semua token. Transport/parse/server failure dianggap belum pasti, menghapus sesi lokal dan meminta reautentikasi tanpa auto-retry; tidak mengklaim pencabutan remote pada hasil unknown. Penolakan 4xx yang diketahui tetap di form, sedangkan 401 mereset autentikasi.

Molekul PasswordField digunakan pada login dan form perubahan. Teks input dibersihkan/dispose pada unmount; error dan pesan login menggunakan teks tetap tanpa respons server mentah. Session membawa notice non-sensitif yang dihapus setelah login berikutnya. MaterialApp ValueKey autentikasi membuang route bertumpuk, termasuk PopScope busy, sehingga pengguna tidak kembali ke form sensitif setelah token berakhir. Entry tersedia pada picker tanpa toko dan shell toko. Form menjelaskan dampak keluar semua sesi/pending memori sebelum submit. Lihat docs/native-password-change.md.


## ADR-024: audit organisasi native dengan scope lama yang dipertahankan

Identity AuditHistory memperkaya endpoint audit existing dengan filter exact action/actor/subject dan pasangan interval timezone maksimal 31 hari. Access.manage tetap menjadi guard, role.manages menentukan full organization versus allowlist onboarding pusat. Semua filter ditambahkan sebagai AND setelah scope; tidak ada query OR yang memperluas data. Null organization events tetap tidak masuk endpoint ini. Field/raw timestamp/JSON dan urutan ID menurun/pagination 50 lama dipertahankan; occurred_at UTC dan envelope scope ditambahkan. Tidak ada join master atau audit-of-audit read.

Flutter AuditModule didaftarkan eksplisit, flag FEATURE_AUDIT dan hint role pengelola hanya menyaring navigasi. DTO/repository/controller/page terpisah dan molecule AuditRecordCard menampilkan snapshot sebagai teks, bukan instruksi. Kalender WIB memakai core WibDateRange. Repository memeriksa envelope dan row organization, membangun pagination sendiri; controller membersihkan stale rows pada load/error serta mempertahankan applied filter/page saat retry. UI menegaskan bahwa scope seluruh organisasi, bukan toko terpilih. Tidak ada migrasi, dependensi, ekspor, mutasi, perluasan izin atau klaim snapshot konsisten lintas halaman. Lihat docs/native-audit.md.


## ADR-025: BFF audit web tanpa perluasan akses

Identity web menambah GET audit organisasi dengan validasi kalender WIB serta filter exact dan page. Backend HTTP menjadi satu-satunya sumber data; query whitelist mencegah scope/token/URL browser diteruskan. BFF memeriksa envelope/row organisasi dan pagination, membangun link lokal dari filter aktif, serta mengabaikan next_page_url API. Guard Access.manage/central onboarding tetap sepenuhnya pada API. Tidak ada schema/provider bisnis/dependensi atau endpoint API baru.

Molekul json-snapshot menormalisasi string JSON/array/legacy/null dan Blade meng-escape semua hasil; organisme audit-record memakai details/summary tanpa JavaScript. Perubahan hanya presentasi historis, bukan kalkulasi saldo atau mutasi audit. Waktu eksplisit ditampilkan WIB dan hasil private/no-store. 401 menghapus session web, error/denial tidak menjadi empty state. Old input form lain tidak menimpa filter aktif. Ringkasan organisasi lama tetap tersedia dan memberi link ke halaman baru. Lihat docs/web-audit.md.


## ADR-026: ledger web scoped dan periode kalender bersama

Operations web menambah GET ledger toko dengan filter batch/search/source/bucket dan kalender WIB. API LedgerHistory yang sudah ada tetap menjadi satu-satunya sumber dan otoritas izin. BFF memeriksa envelope/row toko dan exact batch bila dipilih, menyaring query, membuat pagination lokal, serta tidak menyalin cookie/token/URL API. Molekul stock-movement hanya memformat signed pieces dan snapshot, tanpa domain mutation atau saldo/omzet turunan. Semua teks Blade escaped, waktu occurred_at eksplisit ditampilkan WIB, respons private/no-store dan error tidak menjadi empty state.

Shared/Application/WibPeriod memakai CarbonImmutable untuk awal/akhir kalender inklusif menjadi interval ISO [from,to), default hari ini Asia/Jakarta dan batas 31 hari. Input tetap divalidasi pada HTTP controller. Audit dan Reporting memakai helper yang sama; definisi periode/metrik/izin tidak berubah. Provider Operations existing digunakan, tanpa migrasi, dependensi, API baru atau perubahan OpenAPI. Link batch merupakan hint navigasi; cashier/cross-tenant denial tetap pada server. Lihat docs/web-stock-ledger.md.


## ADR-027: pencarian riwayat struk web terpisah dari checkout

Provider Pos mengarahkan URL riwayat existing ke ReceiptHistoryController khusus baca. Tanpa pasangan tanggal tetap all-history untuk kompatibilitas; pasangan tanggal divalidasi lalu WibPeriod mengubah kalender inklusif menjadi interval ISO. q/page ditambahkan lewat whitelist; browser cashier/store/org/scope/token/URL tidak diteruskan. API ReceiptHistory tetap membatasi owner kasir/pengelola toko dan receipt API memeriksa izin objek kembali.

Envelope/store row/scope/pagination diperiksa sebelum view; pagination dan link receipt lokal tanpa following API URL. Molekul receipt-summary memformat nama/kasir/toko/total/waktu snapshot Sales dengan escaped Blade. Form tidak memakai unrelated old input. Berhasil private/no-store, 401 membuang session, denial/failure tidak menjadi empty state. Tidak ada mutasi checkout/stok/kas, agregasi omzet, API/schema/dependensi atau aturan pembayaran baru. Lihat docs/web-receipt-history.md.


## ADR-028: penelusuran tanggal kemasan tanpa keputusan SOP baru

Inventory BatchCatalogue memperkaya GET batches existing dengan batas expires_from/to inklusif opsional, LIKE metadata snapshot dan with_balance. Access.store tetap menjaga membership/assignment (termasuk cashier) dan baca toko nonaktif; tidak memakai guard staff ledger. OR metadata/bucket dikelompokkan sebagai AND di bawah scope toko. Positive usable/opened/ready/quarantined memenuhi filter; waste-only tidak, sementara available_pieces lama tidak berubah. Legacy ordering expires_on/id, model fields dan pagination100 dipertahankan; store/observed_at ditambahkan dan respons privat.

Operations web menambah halaman baca melalui Backend HTTP dengan whitelist/envelope/row/pagination checks, link lokal dan escaped molecule. Tidak ada join Catalog master, query DB API dari web atau mutasi ledger/audit/revision. Tanggal kemasan adalah date inklusif, bukan waktu transaksi: tidak memakai WibPeriod atau cap31 hari. Horizon notifikasi, FEFO, recall, ready-food shelf life dan penjualan expired tetap keputusan terpisah D-05; tidak diaktifkan oleh filter. Tidak ada migrasi/dependensi. Lihat docs/batch-expiry.md.


## ADR-029: usulan pengurangan pascapersetujuan terpisah dari amendment

Ordering OrderChangeWorkflow memiliki proposal/history/decision, dengan parent lock yang sama seperti workflow dan Fulfillment. Nullable unique pending_order_id menegakkan satu pending; original approved quote tidak diubah sebelum recipient menerima snapshot pengurangan. ID baris/harga/kemasan tetap; jumlah minimal1, semua baris dan zero shipments. Pending merupakan hold terpisah tanpa enum status baru: OrderAccess.held membatasi process/dispatch/cancel dan capability native existing. Tidak ada ledger/kas/invoice write atau kuota pengganti baru. Approver wholesale/internal tetap sesuai OrderAccess; audit pada kedua organisasi dan versi induk naik sekali tiap operasi. Histori append/decision tidak dihapus dan rollback setelah penggunaan ditolak.

Web menggunakan Backend HTTP, capability server dan komponen reusable. UUID/path/body disimpan sebelum request, ACK divalidasi, unknown ditahan dan retry hanya salinan server. Penolakan berikutnya tidak memutar operasi unknown; 401/logout/login membatasi session akun. Ini bukan recovery lintas session/offline. PUT amendment/native composer lama tidak diperluas; dedicated reapproval native tersedia terpisah pada ADR-030. Lihat docs/order-reapproval.md.


## ADR-030: reapproval native dengan review dan request immutable terpisah

Ordering menambah repository/context/controller/page untuk API changes existing; composer amendment lama tidak berubah. Entry pada detail Pesanan dan Gudang mengikuti registry/FEATURE_ORDERING. Capability, parent/row ID, pagination dan pasangan snapshot diverifikasi; tidak mengikuti API pagination URL. Client hanya membantu validasi angka/alasan dan tampilan quote historis, server tetap otoritas harga/state/tenant. Tidak ada endpoint, schema, migrasi atau dependensi baru.

Candidate review immutable terpisah dari pending, terikat context/version dan hanya candidate aktif boleh dikonfirmasi. Busy/unknown menahan refresh/pop/new actions; retry salinan persis UUID/path/body, termasuk sesudah denial berikutnya. ACK memeriksa parent/change/status/version+1; reload terkini memisahkan hasil replay historis dari state sekarang. ApiClient401 mengikuti global MaterialApp route reset existing, tanpa meneruskan pending ke akun baru. Memori halaman bukan antrean lintas restart/offline. Molekul OrderChangeLine dan organisme Card/Review membagi tampilan perbandingan di history/dialog/pending. Lihat docs/native-order-reapproval.md.
