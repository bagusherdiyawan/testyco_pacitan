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


## ADR-031: penelusuran tanggal kemasan native melalui batch API existing

Inventory menambah BatchExpiryFilter/Repository/Controller/Page di modul existing, tanpa perubahan repository load legacy bagi POS/stok. Filter default kosong, tanggal DATE opsional independen inklusif tanpa timezone/horizon/cap31 hari. Pagination100 pada API, envelope/row scope/metadata diverifikasi, URL next API diabaikan dan draft form terpisah dari applied filters. Assigned cashier tetap boleh baca; tidak ditautkan ke staff ledger atau mutasi. Toko nonaktif dapat dibaca sesuai izin API.

Molekul StockBatchCard reusable ditambah kondisi opsional, memakai snapshot/balance historis yang sudah tersedia tanpa harga/join master. GET hanya membaca; error tidak menjadi empty atau stok aman. Tidak menulis pending POS, stok/audit/revision atau mengaktifkan FEFO/sale block/recall/SOP mutu. API/OpenAPI/schema/dependensi tetap; D-05 terpisah dari fitur pencarian. Lihat docs/native-batch-expiry.md.


## ADR-032: versi akses global dan reaktivasi idempoten

Identity UserAccountStatus mengelola status User global dengan otorisasi organisasi/membership serta owner/self/multi-org guard yang sama pada deaktivasi/reaktivasi. users.access_version default1 naik pada status dan update role/assignment. Membership dan status mengunci User yang sama; active/version/receipt/audit/token revocation commit bersama. GET review scoped hanya menampilkan role/toko route dan membership_count global; perubahan tersendiri pada password/role/assignment tidak menjadi input reaktivasi. Owner pada membership mana pun dilindungi untuk status global; ID self dinormalisasi numerik.

Operations UUID menyimpan hasil reaktivasi historis, sehingga replay lama sesudah deaktivasi berikutnya tidak menghidupkan akun. POST rechecks exact membership/permissions dan versi inactive terbaru. Legacy deactivate tetap kompatibel, menaikkan versi dan mencatat actual before state. Audit user.reactivated masuk allowlist onboarding secara eksplisit dengan before/after active/version/reason, tanpa harga/password/token atau perluasan scope operasional. Migration down ditolak setelah versi/receipt terpakai.

Web menambah review/konfirmasi/retry lewat Backend HTTP, escaped user-access-summary dan session pending immutable. Context/assignment identity, capability/versi sebelum kirim dan ACK parent/user/status/version+1 diperiksa. Unknown ditahan melewati denial,401/login/logout membatasi session; pasca ACK membaca context baru. Tidak ada queue lintas restart atau auto-login token. Lihat docs/account-reactivation.md.


## ADR-033: ekspor audit bounded dari query yang sama

Identity AuditHistory membagi private scope query/entry mapping untuk reader pagination50 dan export bounded. Authorization/allowlist dipasang sebelum filter; global null-org events tetap tersembunyi. Export membutuhkan interval timezone <=31days, cursor10001 mendeteksi row overflow dan encoded-row bytes8MiB sebelum mengirim JSON. Tidak ada offset pagination, silent truncation, master join atau audit/write side effect. AuditQuery berbagi validasi HTTP tanpa mengubah legacy all-history read.

Web AuditSearch membagi kalender WibPeriod/whitelist; export memakai applied filters tanpa page/URL client. API memberikan JSON data scoped, BFF memeriksa organisasi tiap row/ID/type/size kemudian mengubahnya ke CSV privat, fixed filename/BOM/fputcsv/CsvText. Null dan legacy snapshot dipertahankan sebagai data teks, prefix formula dinetralkan; tidak ada restore/import promise atau cookie/header API diteruskan. Tidak menulis file permanen/session bisnis/ledger/audit. Lihat docs/audit-export.md.


## ADR-034: ekspor ledger dan utilitas bounded/CSV bersama

LedgerHistory membagi scope/join/filter/entry mapping antara pagination100 dan bounded export. Guard staff/assignment tidak sama dengan katalog batch kasir dan tidak menerima central onboarding override. Dua sisi join terikat toko; batch exact diperiksa di toko. Export membutuhkan periode timezone<=31days, mengembalikan snapshot metadata dan signed piece deltas, tanpa saldo/price/master join atau write. LedgerQuery/LedgerSearch membagi validasi dan applied query di layer HTTP masing-masing aplikasi.

API BoundedExport kini dipakai audit/ledger untuk10000rows/8MiB limit sebelum respons. Web CsvDownload/CsvText/ExportTime dipakai kedua exporter: semua row scope/type diverifikasi dahulu, integer tervalidasi tetap numerik dan semua text aman dari formula; timestamp ISO nyata diformat UTC. Header/file name konstan, private/no-store/nosniff/BOM; tidak meneruskan cookie/header API, membuat file permanen atau mengaktifkan backup/import. Regression audit lama tetap menjaga format/otorisasi. Lihat docs/ledger-export.md.


## ADR-035: kasus penerimaan dan rencana penyelesaian independen dari eksekusi stok

Fulfillment memiliki kasus unik per kiriman, snapshot actual receipt immutable, catatan append-only dan history rencana. Parent order→shipment→case→plan lock menjaga urutan yang sama dengan pemenuhan. Case version independen dari order; notes juga menaikkan versi sehingga review keputusan lama stale. Nullable unique pending_case_id menegakkan satu rencana aktif. Tidak ada ledger/revision/order/shipment/cash/invoice writes atau kuota pengganti otomatis. State plan_confirmed menyatakan kesepakatan, bukan eksekusi/penutupan kasus fisik. Rollback history terpakai ditolak.

Keputusan chat D-11 menetapkan owner/admin pusat mengusulkan rencana grosir kemudian partner_lead penerima mengonfirmasi/menolak. Internal hanya pencatatan sampai policy approval disepakati. OrderAccess existing mempertahankan tenant/assignment/historical-store guards. Shared notes diaudit kedua organisasi tanpa memperluas central onboarding allowlist. Snapshot mengukur missing dari sent-good-damaged, bukan sisa order; metadata historis tanpa harga/saldo.

Web Operations menggunakan Backend HTTP dan komponen atomic snapshot/plan reusable. Capability/versi fresh diperiksa sebelum POST; UUID/path/body/review per kiriman disimpan sebelum request. Unknown dan malformed ACK tetap immutable melewati later denial, wrong parent diarahkan ke asal, retry mengabaikan browser edits. ACK parent/case/version/action diverifikasi lalu context dimuat ulang;401/login/logout menghapus session lama. Pagination notes50 dan closed plans50 independen, pending plan terpisah. Kontrak0.24.0 menambah tujuh operasi, tanpa dependensi baru. Lihat docs/shipment-discrepancies.md.


## ADR-036: eksekusi pengganti terpisah dan kuota berbasis snapshot selisih

ReplacementWorkflow berada pada Fulfillment dengan tabel replacement_shipments/lines, terikat kasus dan resolution confirmed/replacement. Tidak memakai tabel shipment/order normal agar kuota/state/harga penerimaan asli tidak berubah. Batas tiap original shipment line adalah damaged+missing snapshot dikurangi seluruh replacement sent; original unsent dan secondary damage tidak menambah kuota. Partial dispatch dan actual receipt memberi proyeksi global ready/in_transit/partially_fulfilled/requires_follow_up/fulfilled, independen pagination50. Rencana retur/kredit/internal ditolak.

StockLedger.dispatch kini menerima source type internal opsional (default legacy), replacement_dispatch/receipt dipakai reader/export web/native. Transaksi mengunci parent→original shipment→case→resolution→replacement→batch; stock/revision, case version, shipment lines, audit kedua organisasi dan Operations UUID commit bersama. Receive mengurutkan destination batch identity dan mempertahankan historic SKU/local product/pack/date, bukan master. Tidak ada financial posting, disposisi quarantine awal atau recursive replacement. Rollback history terpakai ditolak.

Web memakai Backend HTTP, CSRF, server capability dan exact case version; normalized form hanya satu batch per original line per dispatch (API mendukung multi-allocation). Unknown mempertahankan immutable session UUID/path/body/review melalui later denial dan menahan replacement baru. ACK mencocokkan parent/case/plan/source/version serta baris qty dispatch atau receipt actual, lalu reload. Pagination lokal, escaped atomic shipment/plan,401/login/logout cleanup. Kontrak0.25.0 menambah tiga operasi, tanpa dependensi. Lihat docs/replacement-fulfillment.md.


## ADR-037: revision kind dan preview shared untuk sisa pesanan

Advanced revisions memakai tabel/history/unique pending lock yang sama dengan reductions, dengan kind terpisah dan endpoint/reader legacy terfilter. Semua existing line dipertahankan dengan sent floor, zero tombstone untuk penghapusan tanpa menghapus bukti, dan additions sebagai quote lot baru. Supplier tidak mendapatkan seluruh katalog lokal; recipient memetakan SKU baru. Counterparty role decides; actor multi-membership dinilai terhadap pihak yang dibutuhkan. Preview read-only mengikat quote/version/party/reason dan mengabaikan ID baru sementara pada hash. UI web/native membekukan review sebelum submit; harga berubah ditolak. Approval hanya order/history/audit, tidak stok atau invoice. Native memakai repository/controller/review existing dengan advanced mode; legacy behavior tetap diuji. Lihat docs/order-revisions.md.

## ADR-038: katalog efektif, komposisi paket dan file privat

CatalogConfiguration memisahkan otorisasi/domain mutation dari HTTP. EffectivePrice memilih periode DATE WIB inklusif dengan scoped tier dan overlap guard pada product lock; bulk metadata reader menjaga katalog100 tanpa N+1. BundlePricing membekukan resep dan mengembangkan alokasi komponen base pieces; CashCheckout/SaleStock memposting semua debit bersama sale/shift/operation receipt. Parent line tetap SKU paket dan harga paket, recipe_snapshot menyimpan komposisi historis. Tidak ada nested recipe atau pembukaan sealed otomatis. Image raster5MiB/4096px serta PDF kontrak5MiB disimpan content-hash/private, read berizin/no-store/nosniff, PDF attachment; Flutter raster di memori. No dependencies/TLS bypass. Lihat docs/catalog-and-contracts.md.

## ADR-039: kesepakatan kemitraan berversi tanpa efek finansial otomatis

Partnership provider terpisah bergantung Identity, eksplisit di registry API/web. Draft center-only termasuk audit, publish membagi dokumen hanya ke mitra tujuan, accept membutuhkan consent dan nama dengan actor/time/version serta terms/PDF SHA256. Accepted immutable; tidak memberikan POS ACL atau profit sharing konsinyasi. Shared web PendingMutation dipakai konfigurasi/kontrak/bundle untuk freeze request, ACK scoped dan retry unknown through later denial; kandidat revision terpisah dari pending. D-01/D-03/D-08 belum ditutup oleh saran default dalam pertanyaan yang belum dijawab; DL-13/15/16 tetap tertahan kebijakan bisnis.


## ADR-040: retur fisik dan kredit terpisah

Retur agreed `return` hanya kuota barang rusak fisik dari karantina penerima ke karantina sumber asli. StockLedger dan case version/UUID/audit commit bersama; tidak mengubah order/receipt awal atau membuka stok layak jual. Kredit diproses Billing, dibatasi harga historis yang ditagih dan jumlah retur diterima, kemudian pusat→pimpinan mitra mengonfirmasi nominal. Kualitas/release karantina tetap SOP terpisah.

## ADR-041: dokumen keuangan eksplisit dan konsinyasi manual

Billing menjadi provider eksplisit setelah Ordering/Fulfillment/Partnership. Invoice proposal berisi basis/tax/rounding/due/discount/shipping, diterima pimpinan mitra sebelum koleksi. Klaim baris kiriman/laporan unik saat aktif, histori tidak dihapus. Operations dan parent order mengikat lock ke stok/plan/credit. Saldo bertanda memisahkan piutang dan kredit pelanggan. Pembayaran/reversal manual bukan gateway atau laci POS. Kontrak konsinyasi diterima dan laporan sold pieces disetujui pusat mendahului invoice; tidak membaca POS/bagi hasil otomatis. Pilihan dokumen tidak menetapkan D-01 global.

## ADR-042: voucher online, verifikasi non-tunai dan refund historis

Promotions memiliki voucher/redemption issuer-funded eksplisit; quote draft tidak mereservasi kuota. Checkout mengunci shift→intent→produk→voucher→batch; payment reference unik dan semua posting atomik. Noncash memperbarui counter terpisah. Refund mengunci shift→sale→voucher→batch, menggunakan server preview/hash serta nominal diskon historis kumulatif dan alokasi komponen asal. Proportion menghitung pembagian integer tanpa overflow. Stok kembali hanya karantina, uang keluar memakai metode asal/current open shift. Histori sale immutable selain versi pengaman; refund append-only, quota restoration sekali sesuai pilihan voucher. BFF PendingMutation serta native frozen JSON mempertahankan request unknown sampai ACK atau rekonsiliasi; tidak ada durable native offline queue. D-03/D-08 global tetap terbuka.

## ADR-043: SOP organisasi, inbox dan SMTP tanpa keputusan pangan bawaan

Keputusan pengguna: pimpinan tiap organisasi menyetujui SOP, SMTP dahulu, push ditunda. Reliability provider bergantung Identity/Inventory; FoodSafety milik Inventory sehingga SaleStock/StockLedger/StockControl/Fulfillment memakai satu kontrak eligibility/FEFO. Recall tidak mengubah saldo; ready deadline memakai waktu aktual dan menit eksplisit, tidak memperpanjang campuran lama. Saran FEFO terpisah dari pencarian batch historis. Audit dan low-stock episode menulis inbox/outbox pada transaksi yang sama, recheck ACL pada baca/delivery, opt-in dan lease/backoff, email generik at-least-once. Lihat docs/reliability-and-offline.md.

## ADR-044: faktor kedua dan outbox offline kasir terenkripsi

Challenge password→MFA tunggal dua menit/lima percobaan, secret Crypt/APP_KEY, recovery hash sekali pakai dan TOTP replay counter. User lock menyelaraskan login/rotasi sandi/enrollment/revoke; akses versi lama serta challenge dicabut. Reset email job ShouldBeEncrypted, fragment token, HTTPS configured URL, tetap MFA bila aktif. Flutter menyimpan sesi/outbox AES256-GCM/AAD dengan kunci OS secure storage; atomic durable ACK sebelum bukti lokal, fail-closed bila kunci/data/penulisan tidak sah. Izin own-shift cash-only dibekukan server, expiry eksplisit; sync memakai UUID sama, GET status terkini. Sale/stock/shift atomik atau konflik immutable. Review manager dengan kasir asal/current shift/reconciliation, tidak mengganti frozen harga/jumlah atau membuat posting uang fiktif. Pending native lain tetap memory-only. Perangkat fisik/Keystore/PostgreSQL adalah gate terpisah.

## ADR-045: lifecycle aset dan laporan kohor biaya berbukti

Assets provider bergantung Identity/Partnership. Kepemilikan privat, satu assignment aktif/pending, kontrak diterima untuk lintas organisasi, consent penerima dan physical return pemilik mempertahankan histori/lokasi snapshot. Insiden/biaya eksplisit tidak menjadi invoice otomatis. Reporting memisahkan POS sendiri, B2B seller dan purchase buyer, cohort sold_at/accepted_at dengan current cumulative adjustments; bukan cashflow ataupun saldo all-time. HPP/biaya net append-only ber-evidence, null margin tanpa bukti. BoundedExport dan CsvDownload mempertahankan scope/limits/formula safety. DL-01–20 selesai pada cakupan development tetap; gate produksi dan push yang ditunda tidak diubah menjadi paket baru.

## ADR-046: pencetakan lokal tanpa replay penjualan

ESC/POS encoder pure Dart, transport TCP terpisah dan UI printer memakai snapshot struk selesai. DeviceState menyimpan pekerjaan/frozen bytes/profil sebelum I/O; sending/unknown/sent_unconfirmed memerlukan pemeriksaan fisik, tidak auto-retry. Cetak ulang adalah salinan/pekerjaan baru, tidak POST sale lagi. Arsip terenkripsi mempertahankan bukti. Browser memakai dialog sistem dan CSS58/80mm, bukan koneksi server ke printer privat. Byte terkirim bukan kertas tercetak; drawer command tidak dikirim dan nama/referensi dibersihkan dari byte kontrol. Model/USB/Bluetooth native/perangkat fisik tetap perlu konfirmasi/uji.

## ADR-047: persetujuan finansial tetap dan jurnal koreksi otomatis

Commercial provider eksplisit setelah Billing/Promotions, HTTP BFF terpisah. Addendum kontrak accepted, proposer/counterparty roles, periode dan angka/evidence eksplisit mengaktifkan konsinyasi atau sponsor; tidak ada pembukaan POS mitra secara umum. Aktivasi konsinyasi memakai order→destination batch→agreement locks, checkout batch→agreement dan worker order→agreement; pending proposals tidak memblokir penjualan tetapi activation/fresh-sale guard tidak boleh melewati batch yang sudah terjual. Manual invoice/report dan automatic active_order_id saling eksklusif.

Asal alokasi diverifikasi dari positive original/replacement receipt ledger as-of sale; kredit setelah struk tidak mengubah atribusi historis. Scope original receipt store, campuran owner tidak diklaim otomatis; perpindahan komersial lanjutan ditahan. Revenue/bundle/refund/HPP/loss/tax/rounding disepakati eksplisit. Worker memproyeksikan kumulatif dan immutable deltas, termasuk late offline/refund, dengan rotasi/batch limits/error status. Saldo ini settlement, bukan invoice/faktur pajak atau uang otomatis.

Sponsor budget diperiksa di voucher quote dan re-lock saat redeem, dalam transaksi sale/stock/shift. Frozen funding splits, budget restoration policy dan cumulative historical discount reversals mencegah kuota/budget/reversal ganda. Pembayaran manual signed collection/payout hanya pemilik saldo source/sponsor, evidence/UUID/version/reference, tidak menulis POS drawer. Scheduler Compose aktif tiap menit; queue reset email tetap proses terpisah.

## ADR-048: koordinasi recall berdasarkan ledger dan otoritas penerima

RecallCoordinator menelusuri actual receipt edges asli/pengganti/retur dengan visited set,10000batch/100hop dan transaksi atomik. Root hold mengikuti recall sumber; descendants notified, menerima inbox dan linked late receipts. Penerima hanya meninjau target sendiri dan memutuskan hold/release dengan evidence/count/revision; FoodSafety menyatukan semua hold/recall/SOP. Source hanya mendapat trace metadata minimum. Close campaign menghentikan propagasi baru, tidak melepas hold. Tanpa keputusan pengguna yang memberi wewenang blok otomatis lintas tenant, implementasi mempertahankan keputusan pimpinan penerima. Tidak ada mutasi jumlah stok atau pelepasan mutu otomatis.


## ADR049 — Administrasi native dan transport Bluetooth Android (5 November 2026)

Koreksi pengguna mengganti batas web-only untuk lima administrasi dan meminta koneksi printer Bluetooth; auto hold recall lintas organisasi dan auto payout dilewati. Flutter AdministrationModule memakai registry/flag eksplisit, API HTTP yang sama, membership komersial nyata, field/review/card/pagination bersama dan schema tindakan per domain. Tidak menyalin domain stock/finance ke Flutter atau membuka WebView. AdminMutation mempertahankan frozen UUID/path/body/actor/version selama halaman hidup melalui unknown dan penolakan berikutnya, memeriksa ACK lalu membaca ulang konteks; auth boundary membuang state. Pending ini tidak dijanjikan durable setelah proses mati. PDF private dibatasi5MiB, tanpa redirect/URL bertoken dan dipratinjau native.

ReceiptPrinter memilih transport dari profil beku. Profil legacy tanpa transport tetap TCP; profil bluetooth menyimpan alamat paired Classic/SPP dan port sentinel0, tidak dipakai sebagai TCP. Native Android MethodChannel meminta CONNECT padaAndroid12+, memakai bonded devices/RFCOMM SPP pada worker, timeout dan socket close tanpa retry. Satu pengiriman aktif, ACK transport berbeda dari pemeriksaan hasil fisik. Protokol BLE/vendor/iOS tidak disamakan dengan Classic. Model printer belum diketahui dan build perangkat belum diuji. Implementasi ini tidak memperluas pembayaran/recall yang dilewati pengguna.


## ADR050 — ERP produksi dengan ledger berdimensi dan QC wajib (9 November2026)

Permintaan ERP pengguna berbeda dari resep paket POS dan pengukusan batch SKU yang sama. Pisahkan Materials (fixed-point mg/µl/pcs, lot/nilai), Procurement (supplier/PO/receipt/verified settlement), Manufacturing (versi resep/rencana/pemakaian/hasil), Quality (pemeriksaan owner/admin wajib) dan Costing (biaya proses/HPP). Koordinator provider ERP memakai membership owner/admin pusat nyata, bukan override onboarding. Tidak memperluas gudang menjadi pengelola keuangan otomatis.

Pertahankan StockLedger.apply sebagai gerbang semua stok dalam transaksi: dispatch MaterialLot ke writer material unit-aware, InventoryBatch ke writer biji lama. Jangan memasukkan bahan mentah sebagai produk POS atau mengganti arti quantity_pieces. Org lock menyerialisasi command ERP; UUID/actor/body/version/revision dan audit/result disimpan atomik. Snapshot ingredient/output/cost dan provenance immutable. Semua output masuk karantina, termasuk adonan; QC produksi memindahkan jumlah hasil yang tepat tanpa melepas karantina lain. Hasil setengah jadi bernilai biaya penuh dan menjadi input proses berikutnya.

Web dan Flutter merender schema field/aksi whitelist dari API, dengan review sebelum kirim dan ACK UUID/org/ID/version. Path tetap dibangun lokal; schema adalah data, bukan kode/izin. Web session frozen retry dan native page-memory retry mempertahankan body melalui unknown/later denial, auth boundary mereset akun. Planning/reports/trace dibatasi, tidak ada auto order/default bisnis/GAAP/antrean offline produksi yang disimpulkan dari flag. User mewajibkan pemeriksaan owner/admin dan tetap melewati auto hold recall lintas organisasi serta auto payout.


## ADR051 — Izin operator dan buku besar manual (9 November2026)

User melanjutkan ERP setelah ADR050. Owner/admin dapat memberi grant eksplisit kepada warehouse/store_manager yang sudah mempunyai assignment gudang; grant bukan perubahan Identity role/assignment. Read/command operator dibatasi gudang aktif dan produksi/resep/lot; QC, supplier finance, konfigurasi dan buku besar tetap pengelola. Pencabutan juga tersedia untuk grant historis yang targetnya nonaktif/unassigned, dengan receipt/version/audit dan guard ulang saat retry. UI memakai provider/registry dan schema whitelist, tidak menyimpulkan izin dari flag/label.

Accounting dipisah dari costing/stock: bagan akun/saldo normal/periode eksplisit, draft debit=kredit integer IDR, post/reject oleh pengelola lain dan reversal exact snapshot berbeda pembuat asli. Posted journals tidak diedit/dihapus; original dan reversal keduanya ikut laporan menurut tanggalnya. Periode versi aktivitas menolak review close lama, pending draft menahan close. Reversal ke periode baru tidak mengubah saldo periode tertutup lama. Trial balance membawa opening + mutations = closing, summary mutasi akun/kategori dari jurnal posted dengan batas read. Saldo awal/import/mapping/pajak/autopost ERP/POS dan tutup laba otomatis tidak disimpulkan. Org transaction/UUID/scope/version/audit existing tetap dipakai; tidak ada stock/cash drawer writes dari jurnal.

## ADR052 — Event akuntansi ERP/POS dan nilai persediaan batch (9 November2026)

Permintaan paket1 pengguna mengotorisasi pengecualian terhadap larangan auto-post ADR050/051: pemetaan akun eksplisit dan reviewer berbeda mengaktifkan tangkapan prospektif, tanpa default/import histori. Atomic source events menyimpan policy/date/terms; worker org-serialized membuat jurnal sekali melalui source unique key dan journal link. Missing period/cost/ownership menjadi review_required; retry hanya memperbaiki ketersediaan periode asal. Rekonsiliasi menautkan satu jurnal manual posted/independent-reviewed pada tanggal sumber yang sama. Period close menolak outstanding; reversal manual jurnal sumber dilarang.

InventoryValuation berjalan di StockLedger sebelum saldo fisik berubah: pool batch integer/null, proportional remaining-cost depletion, immutable cost movement/revision. Produksi memberi nilai aktual, POS membekukan alokasi biaya, refund memulihkan offset biaya asal ke karantina. Tidak menebak nilai intake/distribusi dari harga. Semua source/outbox/value/cash/stock berada dalam transaksi yang sama; UUID replay tidak menggandakan. Finance ACL sendiri mengizinkan actual partner_lead own-org tanpa manufacturing atau onboarding override. UI generik ERP web/native membawa policy/valuation/queue/cost ledger dengan pending request tetap immutable. Batas wholesale/komersial/rekonsiliasi cutover dan pengujian ada di [panduan ADR052](../automatic-accounting.md).

## ADR053 — Posting keuangan invoice wholesale dengan pemetaan organisasi masing-masing (9 November2026)

Lanjutan paket1 pengguna memperluas ADR052 hanya untuk keuangan Billing buyout. WholesalePolicy adalah opt-in snapshot tambahan ke base policy ID/versi, bukan default/mutasi chart lama. Reviewer berbeda dan baseline masih aktif wajib. Tax recoverable/clearing serta freight expense/clearing dipilih eksplisit. BillingPosting menangkap accepted invoice/credit dan verified payment/reversal secara atomik untuk organisasi yang mengaktifkannya; sumber/worker/period/replay tetap ADR052. Koreksi invoice mempertahankan policy asal termasuk sesudah suspend/supersede; pergantian dasar tidak boleh diam-diam menghilangkan integrasi wholesale.

Buyer invoice masuk purchase clearing, bukan menimpa biaya batch yang mungkin sudah terjual. HPP distribusi/perpindahan nilai fisik/consignment/sponsor GL tetap rekonsiliasi. Missing origin/manual reconciled/consignment tidak diasumsikan buyout biasa. Buku pihak lain tidak masuk context actor. Detail jurnal, cutover dan kontrak ada di [panduan wholesale](../wholesale-accounting.md).

## ADR054 — Lapisan koreksi biaya invoice tanpa menimpa histori (10November2026)

Keputusan pengguna mengenai receipt ownership, provisional agreed-order cost dan split variance kini dijalankan untuk dedicated full-good buyout receipts. InvoiceValuation menyimpan queue server-authoritative, menyiapkan seluruh rencana sebelum mutasi, lalu atomik dengan accepted invoice: StockLedger money-only write/revision/cost entry serta lapisan koreksi per sale allocation. Source cost entries dan invoice/struk lama tetap immutable. Source invoice/receipt policy menentukan tax/freight capitalization; original journal/date tidak diganti. Multiple receipt dates dan later sales membentuk correction/depletion event sesuai tanggal masing-masing, bukan HPP sebelum penjualan.

Refund berikutnya memakai corrected historical allocation cost. Closed/missing periods, incompatible source journals/policies dan unsupported mixed/return/waste/transfer lineages menjadi durable review; no-op costs tidak memaksa perubahan lama. Retry own-finance hanya ID/version/note/UUID; worker memakai sumber asli, bukan nilai client. Closing memblokir outstanding sumber/revaluations hingga akhir periode termasuk prior queues. Audit menandai proses otomatis dan actor retry tetap tercatat. Batch evidence dibaca bulk dengan100baris/10kentries/8MiB/1000policies limits. Batas serta aktivasi dan bukti uji ada di [panduan](../invoice-valuation.md).

## ADR055 — Snapshot closing independen dan laporan buku posted (10November2026)

FinancialBooks menjadi kalkulator bounded untuk laba rugi/posisi keuangan dan basis closing. Proposal mengikat periode/version/hash seluruh buku posted dan COA, akun retained earnings eksplisit, serta baris closing immutable; persetujuan pengelola berbeda memvalidasi ulang sumber/draft/periode sebelumnya sebelum jurnal+period lock atomik. Laporan income mengecualikan transfer close/reopen, balance tetap memasukkannya. Reopen membalik hanya closing transfer pada tanggal asal, mempertahankan snapshot dan dependency urutan periode. Tidak ada penghapusan jurnal, saldo awal/COA/pajak bawaan. Detail: [financial closing](../financial-closing.md).

## ADR056 — Outbox hak kontraktual komersial per organisasi (10November2026)

CommercialPolicy memperluas baseline aktif secara prospektif dengan independent approval,10akun terpisah dan tax treatment eksplisit; extension wholesale lama dipertahankan dan tidak boleh hilang diam-diam. CommercialJournal/payments menulis event hak share/sponsor signed secara atomik; own position mengikat original policy untuk koreksi. Source sale/refund/payment dates tetap, cutover/mixed dates/policies direview. POS owned-stock bersponsor dapat membukukan discount/HPP biasa dan recovery terpisah; konsinyasi physical COGS tidak ditebak dari formula share. Detail dan batas: [commercial accounting](../commercial-accounting.md).


## ADR057 — Pool batch dengan bukti harga per penerimaan dan koreksi incremental (10November2026)

Invoice final dapat mengoreksi full-good buyout receipts yang berbagi batch. Migration34 menyimpan immutable receipt value layers; ReceiptValueHistory membaca current prices dan bukti legacy dedicated tanpa migrasi histori. InvoiceCostReplay memvalidasi biaya asli + existing sale/refund/loss layers, incoming provenance/policy dan monetary variance proofs. Final price diterapkan hanya pada receipt invoice bersangkutan; semua delta terkonservasi dan satu batch hanya mendapat satu StockLedger monetary write per invoice.

Receipt dengan tanggal efektif berbeda dalam satu batch menghasilkan komponen incremental per hari, dengan expense depletion pada tanggal sumber. Empty-net GL groups dilewati. Seluruh planning/bounds/locks/revision/audit/source outbox tetap atomik; failure atau bukti rusak tidak membuat partial correction. Reader own-org memperlihatkan receipt evidence yang sama pada web/native. Scope dan angka contoh ada di [panduan](../invoice-valuation.md); actual partial/damage/missing/replacement/transfer dan physical consignor costing tidak otomatis diaktifkan oleh generalisasi pool ini.

## ADR058 — Bukti biaya penerimaan aktual dan karantina (10November2026)

DistributionReceiptPosting kini menilai buyout berdasarkan good+quarantined actual receipt, bukan memaksa full-good. Bukti immutable accounting_receipt_costs mengikat sumber/policy/date/actor/kuantitas/nilai, termasuk known0 tanpa jurnal nominal palsu; perubahan stok/bukti/outbox atomik. Seller COGS hanya bagian received dari immutable dispatch cost, residual missing tetap review sumber. Sumber manual-reconciled/cutover tidak diakui ulang.

InvoiceValuation menerima maksimal dua entri bucket per line dan membagi nilai final dengan konservasi integer, tetap satu monetary StockLedger write per batch/invoice. Invoice quantity harus cocok actual owned receipt; zero invoice lines tidak boleh menutupi karantina bernilai. ReceiptValueHistory memverifikasi facts dan price layers; known0 facts juga menghalangi konversi owned receipt ke konsinyasi. Ini memperluas pembatasan partial/damage di ADR053/054/057 hanya untuk bukti penerimaan aktual tersebut. Missing settlement, replacement/distribution return, partial internal transfer dan physical consignor cost tetap review. Lihat [panduan](../actual-receipt-costing.md).
