# Bukti implementasi dan pengujian

Dijalankan pada lingkungan onboarding, 28 Oktober 2026. Hasil lokal, bukan UAT bisnis atau CI remote.

| Pemeriksaan | Hasil |
| --- | --- |
| API PHPUnit | 110 tes lulus, 747 assertions |
| Web PHPUnit | 43 tes lulus, 171 assertions |
| Flutter | 42 tes lulus; kontrol stok 390px/1200px, peringatan stok 390px/1200px, POS 390px/1200px, stok 390px/1024px dan pemulihan pembayaran |
| Total | 195 tes lulus |
| Flutter analyze setelah formatting | Lulus tanpa issue |
| Migrasi kontrol stok, POS dan ambang pada DB development | Lulus tanpa reset saldo lama |
| Compose API/web dan smoke metadata | Healthy dan lulus |
| Smoke HTTP nyata terisolasi | CSRF/login, supply/kontrol stok, POS tunai, retry draft/pembayaran, reprint, kas keluar, tutup shift dan ambang/peringatan stok rendah lulus |
| Composer validate --strict | Kedua aplikasi lulus |
| JavaScript stock-control/pos-cart/receipt | Syntax check Node lulus; interaksi dropdown pada browser nyata belum diuji |
| OpenAPI 0.7.0 | 62 operation ID unik, referensi schema dan parameter path konsisten; belum memakai validator OpenAPI eksternal |
| PostgreSQL/concurrency multi-koneksi | Belum diverifikasi; development memakai SQLite |
| Android/iOS, printer dan Flutter release web | Belum diverifikasi; unduhan Web SDK sebelumnya ditolak proxy storage.googleapis.com |

## Bukti kontrol stok baru

- Buka 1 kemasan isi 10 lalu kukus 6, hasil 6: utuh 0, terbuka 4, siap 6. Bila hasil 5, susut 1 dan tersedia 9. Hasil nol dicatat sebagai susut seluruh input dengan alasan.
- Ledger proses merekonsiliasi perpindahan keadaan; karantina dan susut tidak menjadi sumber cook atau available. Pengiriman lama tetap mengambil kemasan utuh.
- Permintaan berulang dengan UUID sama tidak mengulang pembukaan/pengukusan/review. Aktor/payload berbeda pada key terpakai ditolak.
- Stok tidak cukup, input/output negatif/fractional, hasil melebihi input, batch salah toko atau batch non-beku ditolak; transaksi/claim/ledger tidak tertinggal sebagian.
- Opname minus 3 tidak mengubah stok sebelum approval. Pengusul tidak dapat menyetujui/menolak sendiri; kepala toko bukan reviewer. Pimpinan partner lain dan owner pusat tanpa membership partner ditolak.
- Warehouse pusat dapat mengusulkan, owner pusat berbeda dapat meninjau. Pengusul dapat membatalkan sendiri, peninjau dapat menolak, dan riwayat/audit tetap ada.
- Setiap receipt/dispatch/proses/adjustment memperbarui stock_revision. Snapshot lama ditolak saat submit maupun approve. Dua proposal dari snapshot sama tidak dapat menerapkan dua delta setelah saldo berubah.
- Waste terpisah baru mengurangi saldo dan menambah susut setelah approval. Opname biasa tidak mengasumsikan penyebab susut. Counter susut kumulatif tidak bisa diopname/reset.
- Hitungan tanpa selisih menghasilkan review tanpa ledger palsu atau perubahan revisi. Utuh harus kelipatan isi kemasan historis.
- Rollback skema yang telah digunakan ditolak untuk menjaga histori dan saldo; tes memverifikasi data tetap ada.
- Web menunjukkan saldo/revisi, riwayat dan kontrol review; approval snapshot stale dinonaktifkan dan API tetap menolak bila dipaksa. UI tidak meneruskan angka delta/status/peninjau hasil manipulasi client.
- Flutter menampilkan utuh, terbuka, siap, karantina, susut serta total yang mengecualikan dua bucket terakhir. Payload API lama tanpa counter baru tetap bisa dibaca dengan nol untuk field tambahan.

## Integrasi nyata

Smoke memakai database/container sementara dan kredensial acak, tidak menambah akun ke DB development. Web memakai APP_ENV=local: login tanpa CSRF ditolak 419 dan token/cookie valid berhasil.

Setelah order diterima 18 baik/2 rusak isi 8, saldo 144 utuh + 16 karantina. Membuka satu kemasan menyisakan 136 utuh/8 terbuka. Kukus 6 hasil 5 menghasilkan terbuka 2/siap 5/susut 1. Kepala toko mengajukan susut satu biji; saldo tetap sebelum pimpinan mereview lewat web. Setelah review/replay: terbuka 1, siap 5, susut 2, available 142. Pergerakan selanjutnya menyebabkan proposal baru menjadi stale dan approval ditolak 409.

## Pemeriksaan lama tetap lulus

Identity/token/password/revocation, scope organisasi/store, audit tanpa kredensial, harga grosir tersembunyi dari kasir, draft order tersembunyi dari pusat, snapshot harga/kemasan, dispatch parsial, penerimaan aktual/karantina dan transfer internal bernilai nol tetap diverifikasi. Debit item pertama rollback ketika item kedua kiriman gagal. Pagination Flutter tidak mengirim token ke next_page_url eksternal.

## Runtime dan batas

Compose mempertahankan environment override dengan --no-reload dan memakai NO_PROXY/no_proxy untuk API internal. CA host dipasang read-only; TLS/checksum tidak dimatikan. Lockfile dependensi tetap dipertahankan.

Aksi proses/submit/review kini tersedia melalui web dan Flutter bagi petugas berwenang. POS tunai/shift tersedia pada web/Flutter. Belum ada QRIS/refund, printer fisik, retur/kredit/penggantian, pembalikan proses, aturan masa simpan siap jual, pelepasan karantina bermutu, ambang susut, push notifikasi minimum, FEFO otomatis, billing atau offline. Kontrol dua pelaku masih perlu validasi operasional/UAT. PostgreSQL/concurrency, backup/restore dan perangkat nyata tetap menjadi gate pilot.

## Bukti POS tunai dan shift

- Harga 4 biji × Rp3.000 = Rp12.000; tunai Rp20.000, kembali Rp8.000. Harga porsi/kemasan berasal dari retail per biji, bukan harga grosir. Payload harga/total palsu tidak menjadi sumber perhitungan.
- Draft server tidak mengurangi stok; satu draft hanya menghasilkan satu sale. Replay UUID identik, UUID baru untuk draft lunas dan recovery setelah shift ditutup tetap mengembalikan struk asli. Nominal berbeda ditolak.
- Tunai kurang, QRIS, produk nonaktif, harga/konversi berubah, batch salah toko, karantina, loose dari kemasan utuh dan stok habis setelah draft ditolak. Kegagalan SKU kedua rollback seluruh penjualan, ledger dan kas.
- Snapshot struk tetap sama setelah master berubah; reprint tidak menambah ledger. Debit POS mengubah stock_revision sehingga proposal opname lama ditolak.
- Satu shift aktif per kasir/toko; akses kasir lain, warehouse, mitra lain dan pusat tanpa membership ditolak. Kasir hanya membaca struk sendiri; manager terbatas toko berwenang.
- Shift dengan draft pending tidak dapat ditutup. Versi kas stale, kas keluar berlebih dan selisih tanpa catatan ditolak. Uang awal Rp100.000 + sales Rp200.000 − expense Rp20.000 = expected Rp280.000; physical Rp275.000 menyimpan selisih −Rp5.000.
- Laporan menguji batas interval awal inklusif/akhir eksklusif dan tanggal WIB. Kas masuk/keluar tidak ditambahkan sebagai omzet.
- Flutter menguji respons pembayaran hilang setelah server commit, retry dengan UUID/nominal sama, refresh menjadi struk tanpa POST baru, login ulang/pemulihan draft server, seleksi batch habis yang dibersihkan, serta alur buka–bayar–struk–tutup pada 390px dan 1200px.
- Web menguji whitelist payload, keranjang malformed, recovery paid draft, snapshot struk, pagination, versi tutup shift dan konversi tanggal WIB.

Smoke HTTP nyata memakai web dengan CSRF aktif dan API/SQLite sementara: shift Rp100.000, draft 4 biji Rp12.000, uang Rp20.000/kembali Rp8.000, replay tanpa debit ganda, saldo ready 6 → 2, karantina tetap 16. Kas keluar Rp2.000 menghasilkan expected Rp110.000. Tutup fisik Rp109.000 menyimpan selisih −Rp1.000 dan menghapus active lock. Semua container uji dibersihkan, DB development tidak diisi akun uji.

Hasil di atas bukan bukti concurrency PostgreSQL, UAT kasir, klik interaktif browser nyata, offline atau dukungan printer/perangkat fisik. Tidak ada klaim CI remote lulus pada revisi ini.

## Bukti ambang stok minimum

- SKU aktif tanpa stok mempunyai available 0; tanpa ambang tidak dipantau. Ambang 20 pada saldo nol memberi kekurangan 20 tanpa membuat order, stock entry atau harga dalam respons.
- Agregasi mencakup semua batch toko/SKU, memisahkan utuh/terbuka/siap dan mengecualikan stok toko lain, karantina serta susut. Sama dengan ambang tidak rendah; debit menampilkan peringatan, restock menghilangkannya pada pembacaan baru.
- UUID identik mencatat konfigurasi/audit sekali. Konflik payload maupun versi lama ditolak; null mematikan tanpa menghapus row/riwayat, nol tetap threshold aktif.
- Kasir/gudang assigned dapat membaca tetapi tidak mengatur. Kepala toko terbatas toko assigned. Mitra lain, pengguna tidak assigned dan owner pusat tanpa membership mitra ditolak. SKU nonaktif disembunyikan, SKU organisasi lain dan toko nonaktif ditolak.
- Input negatif/fractional/berlebih, versi salah, alasan kosong, parameter query invalid ditolak. Query nama/SKU dan pagination 52 SKU diverifikasi.
- Web menguji autentikasi, denial API, peringatan/breakdown, form sesuai izin, whitelist payload, null disable, pagination, escaping nama SKU serta penolakan validation/conflict. Nilai dan UUID dari form gagal tidak disalin ke formulir SKU lain.
- Flutter menguji peringatan di 390px/1200px, refresh setelah saldo pulih, filter SKU belum dipantau, error/retry tanpa menyatakan stok sehat dan pagination scoped tanpa mengikuti URL eksternal.
- Smoke HTTP nyata: pengelola mengatur ambang 140 melalui form web ber-CSRF; replay tetap version 1. Saldo 142 tidak rendah, penjualan 4 membuat 138 dengan shortfall 2. Kasir melihat warning di web/API tetapi konfigurasi ditolak; karantina 16 tidak masuk total.

Suite akhir: 108 API/734 assertions, 43 web/171 assertions dan 29 Flutter; analyzer bersih. Tes ini memverifikasi peringatan saat halaman dimuat, bukan event/inbox/push notification, concurrency PostgreSQL atau UAT ambang bisnis. Kontrak 0.6.0 diperiksa 61 operation ID unik, local refs/path parameters konsisten; operasi/schema lama tetap sama. Validator OpenAPI eksternal belum dijalankan.

## Bukti kontrol stok native

- API control-context memberi capability proses/pengajuan kepada petugas stok dan eligibility review sesuai organisasi; semua capability mutasi false saat toko nonaktif. Kasir, pengguna tanpa assignment, mitra lain dan owner pusat tanpa membership mitra ditolak. Respons tidak mengandung harga atau detail akun.
- Controller Flutter diuji membuka 1 kemasan isi 10, kukus dengan hasil aktual termasuk nol/seluruh hasil gagal, revisi snapshot dan pemisahan stok utuh/terbuka/siap/susut. Invalid/fractional/negatif/berlebih, bucket waste, hitungan utuh bukan kelipatan, susut tanpa pengurangan, alasan kosong dan batch non-beku tidak mengirim mutasi.
- Setelah response hilang sesudah commit, UUID/path/body tetap identik pada retry. Tindakan baru ditahan; refresh tidak mengganti pending request; satu commit menghasilkan satu perubahan. Widget menampilkan retry dan menahan pop selama hasil belum diketahui.
- Snapshot 409 membuang pilihan dan menonaktifkan mutasi sampai reload berhasil. Reload tidak memajukan expected_revision dari isian lama secara diam-diam. POST sukses tetapi refresh gagal menyatakan operasi sudah tersimpan, tanpa meninggalkan retry atau memakai saldo stale.
- Opname dikirim sebagai fisik tersisa, bukan delta/status. Self review dan stale approval ditolak; reject/cancel memakai versi asli. Dialog review wajib mengirim catatan, tanpa amount/count yang dapat menimpa proposal.
- Widget 390px/1200px menjalankan pilih batch → buka 1 kemasan → kukus 6 hasil 5 → ajukan hitung fisik. Proposal belum mengubah stok. Widget lain memverifikasi approval berizin, rejection stale, histori status dan retry identik setelah hasil jaringan tidak diketahui.
- Smoke HTTP nyata memverifikasi context pengelola vs peninjau dan denial pusat/kasir, bersama alur supply/proses/count/penjualan yang sudah ada. Ini bukan eksekusi aplikasi Flutter pada perangkat Android/iOS.

Suite akhir irisan native: 110 API/747 assertions, 43 web/171 assertions, 42 Flutter; analyzer bersih, HTTP smoke dan metadata lulus. OpenAPI 0.7.0 mempunyai 62 operation ID; schema/path refs diperiksa secara lokal, bukan validator eksternal. Operasi native masih online dengan pending request dalam memori halaman; terminasi paksa memerlukan rekonsiliasi manual sebelum mencatat ulang. Offline/persistensi operasi lintas restart, concurrency PostgreSQL, backup/restore dan UAT perangkat tetap belum terverifikasi.
