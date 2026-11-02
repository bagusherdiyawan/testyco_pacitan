# Bukti implementasi dan pengujian

Validasi terbaru pada lingkungan onboarding, 2 November 2026; catatan irisan sebelumnya berasal dari 28–29 Oktober 2026. Hasil lokal, bukan UAT bisnis atau CI remote.

| Pemeriksaan | Hasil |
| --- | --- |
| API PHPUnit | 188 tes lulus, 1789 assertions |
| Web PHPUnit | 102 tes lulus, 813 assertions |
| Flutter | 190 tes lulus; penelusuran tanggal kemasan/filter batch bersaldo390px (text scale1,5 + keyboard)/1200px; persetujuan ulang dari detail Pesanan/Gudang, review/unknown/keyboard390px (text scale1,5)/1200px; audit organisasi/filter/snapshot 390px (text scale 1,5 + keyboard)/1200px dan delapan modul navigasi, perubahan sandi 390px (text scale 1,5 + keyboard)/1200px, sesi/route reset dan hasil unknown, riwayat struk/filter/pagination 390px (text scale 1,5)/1200px dan toko nonaktif, riwayat ledger/filter/batch 390px (text scale 1,5)/1200px, penerimaan awal gudang/review 390px (text scale 1,5)/1200px dan pending/pop guard, gudang/process/dispatch 390px (text scale 1,5)/1200px, editor ambang 390px (text scale 1,5)/1200px dan keyboard mobile, kas shift/review 390px (text scale 1,5)/1200px dan navigasi overflow mobile, perubahan pengajuan 390px/1200px, composer pengajuan 390px/1200px dan reset sesi, penerimaan/pesanan 390px (text scale 1,5)/1200px, laporan 390px (text scale 1,5)/1200px, kontrol stok 390px/1200px, peringatan stok 390px/1200px, POS 390px/1200px, stok 390px/1024px dan pemulihan pembayaran |
| Total | 480 tes lulus |
| Flutter analyze setelah formatting | Lulus tanpa issue |
| Migrasi kontrol stok, POS, ambang, histori revisi dan versi akses akun pada DB development | Lulus tanpa reset saldo lama |
| Compose API/web dan smoke metadata | Healthy dan lulus |
| Smoke HTTP nyata terisolasi | CSRF/login, supply/kontrol stok, POS tunai, retry draft/pembayaran, reprint, kas keluar, tutup shift dan ambang/peringatan stok rendah, laporan tunai/CSV privat serta capability penerimaan serta preview/create/amend pengajuan native dan metadata/replay kas shift serta detail/konfigurasi minimum native dan inbox/context gudang serta katalog/preview/intake hash-bound serta ledger scoped/filter/snapshot UTC lulus |
| Composer validate --strict | Kedua aplikasi lulus |
| JavaScript stock-control/pos-cart/receipt | Syntax check Node lulus; interaksi dropdown pada browser nyata belum diuji |
| OpenAPI 0.23.0 | 81 operation ID unik, referensi schema dan parameter path konsisten; belum memakai validator OpenAPI eksternal |
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

## Bukti laporan tunai lintas toko

- Total organisasi merekonsiliasi ke baris toko dari query yang sama; toko kosong bernilai nol dan riwayat toko nonaktif tetap berizin. Filter toko mengubah cakupan secara eksplisit.
- Kepala toko hanya melihat assignment pada summary, product breakdown dan CSV. Filter toko lain, mitra lain, kasir, gudang dan owner pusat tanpa membership mitra ditolak. Kepala toko tanpa assignment tidak mendapat data toko apa pun. Organisasi nonaktif ditolak.
- Awal periode inklusif/akhir eksklusif diuji di batas WIB; timestamp tanpa offset, periode terbalik/lebih dari 31 hari, query invalid dan halaman nol ditolak. Header laporan JSON private/no-store diverifikasi.
- Konversi porsi historis tetap 8 biji untuk 2 porsi isi 4 walau master berubah/nonaktif. Nama snapshot lama/baru tetap terpisah, nilai historis tidak dihitung ulang dari harga master. Pagination 51 grup produk diuji.
- CSV memakai nama toko/kasir saat sale, waktu UTC bertimezone dan integer uang. Teks formula-like pada nama diberi apostrof; nama master baru tidak menimpa snapshot export. Header attachment/private/no-store/nosniff dan audit persiapan ekspor diperiksa. Query ekspor menerima 10.000 transaksi dan menolak 10.001 tanpa truncation. Tes ini pemeriksaan batas fungsional, bukan benchmark production.
- Web menguji login/denial, filter tanggal WIB menjadi interval eksplisit, pagination, escaping HTML, byte CSV/BOM tidak diubah dan cookie/kredensial upstream tidak diteruskan. Periode invalid tidak memanggil API; limit error terlihat dan respons JSON tidak salah diunduh sebagai CSV.
- Flutter menguji repository scope/filter/page tanpa mengikuti next_page_url eksternal, rentang akhir bulan WIB, role/organization navigation, filter semua toko berizin, error/retry tanpa nol palsu, dan widget 390px dengan text scale 1,5 serta 1200px.
- Smoke HTTP nyata memakai sale Rp12.000 yang sama dengan alur stok/shift. Kas keluar tidak mengurangi omzet: summary/breakdown/CSV tetap Rp12.000, produk 4 biji. Kasir dan pusat tanpa membership ditolak pada ketiga endpoint. Lead membaca halaman web dan mengunduh CSV via BFF; angka dan nomor sale sama dengan API.

Hasil akhir: 120 API/838 assertions, 48 web/195 assertions, 48 Flutter (216 tes), analyzer bersih; smoke HTTP nyata dan metadata lulus. Kontrak 0.8.0: 65 operation ID unik, local refs/path parameters konsisten, operasi/schema lama tetap sama. Validator OpenAPI eksternal, PostgreSQL/concurrency, load production, UAT dan perangkat fisik belum diuji. Laporan ini hanya completed IDR cash POS; B2B, piutang, margin, refund/non-tunai, snapshot lintas request dan sinkronisasi offline belum diklaim.

## Bukti keputusan dan penerimaan pesanan native

Diverifikasi 29 Oktober 2026. API tambahan diuji untuk metadata recipient_actions berdasarkan role/status, toko nonaktif, supplier-view, cashier/mitra lain yang ditolak dan Fulfillment opsional. Metadata tidak melewati otorisasi mutasi: pengelola tanpa hak approval tetap ditolak meskipun mengirim flag palsu. Invalid baris receipt kedua membatalkan kredit baris pertama, good/damaged pada seluruh shipment line, status/version order, received_at dan claim operasi.

- Controller native menerima 18 baik/2 rusak dari 20 kemasan isi historis 8: 144 usable + 16 karantina, tetap partially_received. Penerimaan zero/zero dengan alasan tidak membuat stok fiktif. Input kosong tidak otomatis dianggap nol.
- Blank/fractional/negatif/berlebih, baik+rusak melebihi dikirim, missing note selisih, missing/foreign shipment line tidak mengirim POST. Kiriman multi-line harus dikonfirmasi seluruhnya dan mempertahankan fakta per batch.
- Respons hilang setelah commit mempertahankan UUID/path/nested body identik. Salinan body yang diubah dari luar tidak memutasi pending request. Refresh melihat shipment sudah received tetapi tidak mengganti pending request; retry hanya satu kredit/commit.
- Konflik versi membuang pilihan dan menahan tindakan sampai refresh. Snapshot shipment lama atau versi lama tidak diganti otomatis dengan observasi baru. POST sukses tetapi refresh gagal tidak menyiapkan mutasi kedua.
- Approval/reject/cancel memakai capability server dan versi yang ditinjau. Alasan kosong untuk reject/cancel ditolak; keputusan tidak menambah stok. Retry keputusan yang sudah committed tetap memakai versi/body asli. Denied order dan Fulfillment disabled tidak memanggil shipments.
- Widget 390px dengan skala teks 1,5 serta tablet 1200px menguji navigasi daftar → detail → input kosong → isi fisik → tinjau → confirm → histori dan refresh daftar saat kembali. Tablet menerima dua batch dengan konversi historis berbeda. Widget lain menguji reject/approve dan pending receipt yang menonaktifkan input/pop sampai retry identik berhasil.
- Smoke HTTP nyata memverifikasi capability pimpinan sebelum approval, seluruh recipient flag false bagi pusat supplier-view, receive true sesudah dispatch, dan histori received_at/18 baik/2 rusak. Stok actual tetap 144/16 setelah replay. Ini bukan eksekusi Flutter pada perangkat fisik.

Hasil terkini: 124 API/886 assertions, 48 web/195 assertions, 61 Flutter (233 tes), analyzer bersih, HTTP smoke serta metadata lulus. Kontrak 0.9.0 tetap 65 operation ID; perubahan additive pada SupplyOrder dan schema RecipientOrderActions, tanpa endpoint/migrasi/dependensi baru. Tidak ada klaim offline, pemulihan operasi lintas restart, build perangkat, UAT atau concurrency PostgreSQL.

## Bukti composer pengajuan baru

Diverifikasi 29 Oktober 2026. Pratinjau memakai snapshot builder server yang sama dengan create; spoofed unit_price/total tidak dipercaya. Preview tidak membuat order/claim/audit atau stok. Body dengan review_hash yang sesuai membuat satu awaiting_approval yang masih tersembunyi dari pemasok pusat; replay committed UUID tetap hasil awal meski harga master kemudian berubah.

- Harga, kuantitas, pasangan SKU lokal dan alamat target berbeda dari tinjauan ditolak sebelum order/claim tersimpan. Target di-lock ulang saat snapshot. Role tanpa hak, mitra lain, pusat tanpa membership target, toko nonaktif dan konversi SKU tidak cocok ditolak. Delivery membutuhkan alamat nyata; malformed hash ditolak. Preview internal transfer memakai SKU identik dan nilai komersial nol.
- Controller native mengharuskan mapping eksplisit, SKU lokal tidak duplikat, satu pusat, quantity packs integer 1–10000, note terbatas dan izin/kind context baru. Inactive/denied/legacy context tidak mengambil katalog komersial. Preview hanya server; create tidak membawa harga/total client.
- Tinjauan mengunci editing; refresh mempertahankan intent ID/kuantitas tetapi membuang quote yang belum dikirim. Konflik definitif meminta review baru. Unknown response membekukan nested body/UUID, tetap identik sesudah refresh/harga berubah. Penolakan izin setelah unknown tidak memutar request; ACK tersimpan mencegah konfirmasi kedua membuat record baru.
- Transfer internal hanya menawarkan local_id yang identik dan memakai nol server, tanpa mengarang harga. Delivery tanpa alamat tidak mengirim preview. Pagination katalog memakai scoped path dan tidak mengikuti URL eksternal.
- Widget 390px text scale 1,5/1200px menjalankan daftar → tambah SKU pusat → pilih SKU lokal → quantity → preview Rp360.000 → konfirmasi → detail awaiting_approval. Skenario mobile kehilangan respons setelah commit, menahan back dan retry identik dengan satu record. Overflow dropdown mobile ditemukan dan diperbaiki sebelum final suite.
- Auth-boundary navigator reset diuji: 401 dari route dengan PopScope(canPop:false) menampilkan login dan membuang route sesi lama; login berikutnya mengambil toko kembali. Ini bukan recovery pending lintas sesi; operator tetap perlu rekonsiliasi setelah sesi berubah.
- Smoke HTTP nyata membuat preview Rp18.000 lalu pengajuan hash-bound/replay dengan satu ID. Target belum bertambah stok dan gudang tetap 160 biji sebelum dispatch; pusat tidak dapat melihat draft, perubahan quantity dengan hash lama ditolak. Alur web lama tanpa hash tetap melalui approval/dispatch/actual receipt yang sama.

Hasil final: 129 API/927 assertions, 48 web/195 assertions, 74 Flutter (251 tes), analyzer bersih dan HTTP/metadata smoke lulus. Kontrak 0.10.0: 66 operation IDs; refs/path parameters diperiksa lokal. Tidak ada migrasi/dependensi baru. Validator OpenAPI eksternal, perangkat fisik, UAT, concurrency PostgreSQL dan persistensi operasi lintas restart belum diklaim.

## Bukti perubahan pengajuan pra-persetujuan native

Diverifikasi 29 Oktober 2026. API preview perubahan memeriksa approver, state dan original version sebelum membaca snapshot. Preview tidak mengganti item/audit/claim. PUT hash-bound mempertahankan ID order, menambah versi satu kali dan kembali awaiting_approval. Replay committed UUID tidak mengambil harga baru atau menambah audit/versi kedua.

- Hash order lain, versi stale dan harga berubah ditolak sebelum line deletion. Item ID/jumlah lama serta operation claims tetap utuh pada konflik. Manager tanpa hak approval, kasir, mitra lain dan supplier-only owner ditolak; order approved tidak mempunyai capability amend dan kedua endpoint menolak perubahan.
- Controller native memuat supplier/local product ID, quantity, mode dan note dari seed berizin; wrong-store/denied seed tidak mengambil katalog komersial. Preview dan PUT selalu membawa original version; create POST tidak dipanggil saat amend.
- Refresh saat versi berubah mempertahankan intent lokal tetapi menahan tindakan. Reset eksplisit mengambil seluruh seed terbaru; composition epoch dan sinkronisasi note membuang field state lama setelah konfirmasi pengguna. Konflik harga pada versi sama meminta tinjauan server baru.
- Unknown PUT mempertahankan immutable nested body/original version/UUID, termasuk setelah status kemudian berubah. Reset tidak dapat membuang pending request. Penolakan setelah unknown tetap mempertahankan request sampai retry terkonfirmasi; satu amend/kenaikan versi.
- Widget 390px text scale 1,5/1200px memulai dari detail, membuka composer prefill, meninjau/simpan perubahan dan kembali membaca detail dengan jumlah baru. Skenario mobile melakukan concurrent update lalu reset yang disetujui pengguna dan memverifikasi quantity form benar-benar berganti.
- HTTP smoke preview/PUT/replay mengubah draft native yang sama dari 1 ke 2 kemasan: ID tetap, version 2, awaiting_approval dan belum menambah stok. Web legacy tanpa hash, approval, dispatch serta actual receipt tetap lulus.

Hasil final: 132 API/972 assertions, 48 web/195 assertions, 82 Flutter (262 tes), analyzer bersih, HTTP dan metadata smoke lulus. Kontrak 0.11.0: 67 unique operation IDs dan local refs/path parameter konsisten. Tidak ada migration/dependency baru. Pengujian belum mencakup PostgreSQL concurrency, perangkat fisik, UAT, dispatch native, postapproval reapproval atau antrean offline tahan restart.

## Bukti kas masuk/keluar native

Irisan PRD 07-CASH/01-ACL/AUD, 29 Oktober 2026; tidak menambah kebijakan refund, shift bersama atau izin kasir.

- GET detail memberi can_record_movement hanya bagi pengelola scoped, toko aktif dan shift open; cashier, warehouse, toko tanpa assignment, mitra lain dan pusat tanpa membership tidak mendapat akses mutasi. Riwayat toko nonaktif tetap berizin; flag false dan POST ditolak. Respons detail private/no-store.
- Kas keluar Rp20.000 dari opening Rp100.000 + sales Rp200.000 menghasilkan expected Rp280.000; sales tetap Rp200.000. Replay UUID/body identik setelah shift ditutup mengembalikan ID catatan awal tanpa kas/audit/ledger ganda. UUID sama dengan nominal berbeda, UUID baru pada shift tertutup serta versi lama ditolak.
- Flutter memisahkan DTO/repository/controller/page serta summary/review reusable. Daftar memuat halaman eksplisit, tanpa mengikuti URL pagination eksternal. Missing capability, closed, wrong-store, read failure serta can_manage tanpa capability tidak mengaktifkan pencatatan.
- Preview dialog tidak menulis. Nominal pecahan/nol/negatif/berlebih, overdraw, kategori/alasan kosong serta kategori terlalu panjang ditolak sebelum POST. Konfirmasi mengirim original shift ID/version, jenis, nominal, kategori, alasan dan UUID.
- Respons hilang setelah commit mempertahankan path/body/UUID, menghalangi refresh/new operation; replay setelah closure memuat GET terkini (bukan versi lama dari replay). Denial sesudah respons tidak pasti tidak menghapus pending. ACK lalu GET gagal tetap menyatakan kas tersimpan dan tidak membuat mutasi baru.
- Konflik versi tidak direbase diam-diam: refresh dan review baru diperlukan. Refresh mengosongkan isian lewat form epoch. Widget menguji daftar, pagination, dialog konfirmasi, perubahan saldo dan bukti tersimpan pada 390px/text scale 1,5 serta 1200px. Menu overflow mobile menguji akses seluruh modul dan selected index.
- Smoke HTTP terisolasi menguji metadata kasir/pengelola, daftar shift, expense, penutupan, replay setelah closure, satu movement tersimpan, omzet tetap Rp12.000 serta ledger tetap. Smoke metadata Compose juga lulus.

Hasil terkini: 135 API/1022 assertions, 48 web/195 assertions, 93 Flutter (276 tes). API/web dari scripts/check lulus; delapan temuan braces analyzer diperbaiki, lalu analyzer dan seluruh tes Flutter lulus. OpenAPI 0.12.0 memiliki 67 operation IDs unik; refs/path parameters dan required duplicates diperiksa lokal, tanpa validator eksternal. Tidak ada migrasi/dependensi baru. PostgreSQL concurrency, build/perangkat Android/iOS, UAT, refund, dispatch native serta antrean tahan restart belum diverifikasi/diimplementasikan sesuai scope masing-masing.

## Bukti pengaturan stok minimum native

Irisan PRD 05-MIN/01-ACL/AUD, 29 Oktober 2026. Pengaturan web tetap kompatibel; tidak ada migrasi/dependensi atau aturan pengadaan baru.

- GET detail ID SKU berbagi proyeksi scoped/tanpa harga dengan daftar. SKU renamed tetap ditemukan berdasarkan ID; query page=2/low_only/q tidak menyembunyikan detail. SKU/toko nonaktif, foreign SKU, lintas tenant, pusat tanpa membership dan kepala toko unassigned ditolak. Kasir/gudang assigned membaca dengan can_manage=false; kepala toko assigned mendapat izin. GET private/no-store dan tidak membuat konfigurasi/operasi/audit/ledger.
- Detail unconfigured memberi version 0/null. POST zero mengaktifkan, null menonaktifkan tanpa menghapus histori. Replay konfigurasi pertama setelah perubahan kedua mengembalikan versi historis, sementara GET tetap versi terbaru. Tidak ada pergerakan stok atau pesanan otomatis.
- Native default-deny bila flag/store/product/version tidak cocok atau metadata hilang. Form tanpa angka default memerlukan threshold integer desimal 0–1 miliar saat enabled serta alasan. Kosong/pecahan/negatif/hex/exponent/overflow ditolak; disable mengirim explicit minimum_pieces=null.
- Preview dialog tidak menulis. Konfirmasi mempertahankan path ID toko/produk, versi asli dan UUID/body immutable. Versi stale memerlukan reload/review baru, bukan rebase. Unknown diikuti denial tetap menyimpan pending; retry setelah perubahan lain membaca detail terkini dan tidak menimpa versi historis dari replay. ACK lalu GET gagal tidak mengirim POST pengganti.
- Widget menguji daftar, pencarian, pagination/filter berizin (next_page_url eksternal tidak diikuti), editor zero/disable, review, serta read-only list/deep-link pada 390px text scale 1,5 dan 1200px. Layar 390×800 dengan inset keyboard 320px tetap dapat digulir tanpa overflow. Regresi refresh callback setState yang mengembalikan Future diperbaiki; tes refresh/filter/error lama tetap dipertahankan dan lulus.
- Smoke HTTP terisolasi menambahkan GET ID/price-free/context, cashier read-only/center denial, zero→disable, replay konfigurasi lama, stale conflict serta ledger tidak berubah. Seluruh alur supply, POS, shift, laporan dan CSV sebelumnya tetap lulus; metadata Compose healthy/lulus.

Hasil final scripts/check: 138 API/1065 assertions, 48 web/195 assertions, analyzer bersih dan 104 Flutter (290 tes). HTTP dan metadata smoke lulus. OpenAPI 0.13.0: 68 operation IDs unik, local refs/path parameters/required lists konsisten; belum memakai validator eksternal. SQLite/widget bukan bukti PostgreSQL concurrency, perangkat/build Android/iOS, UAT, ambang produksi yang disepakati atau antrean offline tahan restart.

## Bukti pemenuhan gudang native

Irisan PRD 09-PICK/SHIP dan 04 process, 30 Oktober 2026. Tidak ada migrasi/dependensi baru atau perubahan aturan dispatch, harga, finansial, pengganti dan penerimaan aktual.

- GET inbox/context scoped source center, role owner/admin/assigned warehouse, pemasok yang benar dan order approved_at. Draft belum disetujui, pusat/mitra lain, kasir/kepala toko/lead partner, gudang unassigned atau source mismatch ditolak/tidak muncul. Owner/admin dapat menetapkan sumber order approved; warehouse menunggu assignment. Metadata Identity organization_type hanya untuk navigasi berflag, tidak memberi izin mutasi.
- Context mengembalikan ID/version asli, source, can_process/can_dispatch, sent/remaining dan riwayat, private/no-store. Pembacaan tidak menambah claim/audit/ledger/shipment. Kandidat batch hanya sumber/product/pack sesuai dan usable positif; foreign source/wrong pack dikecualikan. Toko/organisasi tujuan atau sumber nonaktif menonaktifkan tindakan dan mengosongkan kandidat.
- Multi-batch 5+3 kemasan isi 8 menghasilkan satu shipment dengan dua line, stok batch sumber 160→120 dan 80→56, sent 8/sisa 12; tujuan tetap tanpa stok sampai actual receipt. Replay UUID sama tidak membuat shipment/line tambahan. Transfer internal tetap bernilai nol dan tidak boleh source=target.
- Setelah 20 terkirim, receipt baik 8/rusak 2 pada kiriman kedua tidak membuka kuota lagi: partially_received, sent 20/remaining 0, can_dispatch false. Replay kiriman pertama mengembalikan ID awal tanpa mengurangi stok lagi. Tidak ada replacement quota otomatis.
- Controller/UI tidak mengisi batch/kemasan/petugas otomatis. Original line/batch/source IDs dan version dibekukan pada operasi/review. Jumlah invalid, kelebihan stok/sisa, handler kosong dan tidak ada alokasi ditolak. Perubahan stok tanpa perubahan order version tetap ditolak server dan mengharuskan pemeriksaan ulang.
- Unknown process/dispatch mempertahankan immutable nested JSON/path/UUID/review, menahan edit/refresh/pop dan tetap mempertahankan operasi setelah denial. Replay setelah status/versi lanjut membaca GET terkini, bukan respons historis. ACK lalu GET gagal tidak menjadi POST pengganti. Refresh membuang jumlah/petugas/catatan dan field epoch; widget membuktikan input batch lama kosong kembali sesudah posting.
- Widget 390px text scale 1,5 dan 1200px melewati inbox/pagination→pemilihan sumber→review→pengiriman parsial. Native lama/missing organization_type atau capability default-deny. Path pagination scoped mengabaikan next_page_url eksternal.
- Smoke HTTP nyata menguji inbox/context, sumber terpilih/replay, batch snapshot, dispatch/replay, penerimaan aktual 18 baik/2 rusak dan remaining 0; semua supply, POS, kas, minimum, laporan/CSV lama tetap lulus. Compose metadata healthy dan lulus.

Hasil final scripts/check: 144 API/1168 assertions, 48 web/195 assertions, 114 Flutter (306 tes), analyzer bersih. OpenAPI 0.14.0: 70 operation IDs unik; refs/path parameters/required lists diperiksa lokal tanpa validator eksternal. PostgreSQL concurrency, build/perangkat Android/iOS, UAT, jadwal/foto/suhu terstruktur, FEFO/expiry enforcement, reapproval/retur/kredit/pengganti dan recovery offline tetap belum diverifikasi/diimplementasikan sesuai scope masing-masing.

## Bukti penerimaan awal gudang native

Irisan PRD 05-RCV/MOV dan 01/02, 30 Oktober 2026. Inventory mengekstrak service penerimaan yang sama untuk legacy POST dan preview native, tanpa migrasi/dependensi baru atau perubahan izin pusat/mitra.

- Katalog berhalaman 100 SKU aktif/toko pusat tanpa kolom harga, dengan source/can_intake eksplisit. Preview read-only memberi konversi server 2 kemasan isi 8 → 16 biji, hash dan waktu observasi, tanpa batch, ledger, operation receipt atau audit. GET/preview private/no-store.
- Partner lead/manager/cashier, organisasi lain, warehouse unassigned dan center store_manager ditolak. Pusat tanpa membership partner tidak bisa intake di toko partner. SKU foreign/nonaktif dan toko nonaktif ditolak; SKU inactive tidak masuk katalog. Pagination 101 SKU serta validasi input/tanggal diuji.
- Perubahan batch/tanggal/jumlah/note atau pack_size sesudah preview ditolak sebelum seluruh writes. Preview baru mengikuti pack_size terkini; pack_size/quantity_pieces palsu pada POST diabaikan dan stok memakai server. Expiry conflict pada identitas batch yang sama rollback tanpa claim/ledger/audit tambahan.
- Replay committed UUID setelah produk berubah/nonaktif tetap mengembalikan batch asli (isi 8, 16 biji) sekali; tidak mengonversi ulang menjadi isi 10. Legacy web/API no-hash tetap lulus seluruh alur lama.
- Native tidak mengisi jumlah/tanggal/batch/note otomatis. Review hanya server; preview belum mengubah stok. Invalid SKU/tanggal nyata/fractional/negatif/zero/overflow/hex/blank reason ditolak, leap day valid diterima. Edit quote membuang review unsent; salinan body/review tidak dapat mengubah request asli.
- Unknown response mempertahankan immutable UUID/path/body/review, termasuk denial sesudah unknown. Refresh/edit/pop ditahan. Malformed ACK tetap uncertain sampai replay mengonfirmasi batch. Setelah ACK, confirm/load ulang pada page tidak membuat intake baru, dan UI menampilkan delta review serta ID, bukan saldo replay historis.
- Widget 390px text scale 1,5 dan 1200px menguji navigasi Stok→intake→preview→confirm→stok terkini, penambahan 16 biji dan reload batch. Widget unknown membuktikan pop/refresh lock, retry identik dan satu write. Domain pembukaan/pengukusan/opname dan penerimaan mitra tetap lulus.
- Smoke HTTP nyata menguji katalog price-free, preview tanpa batch, hash-bound record/replay, altered quantity conflict, partner denial serta replay intake awal sesudah seluruh stok sumber dikirim: stok sumber tetap 0, tanpa kredit kedua. Semua supply/POS/kas/minimum/report/CSV sebelumnya serta metadata Compose tetap lulus.

Hasil final scripts/check: 149 API/1248 assertions, 48 web/195 assertions, 124 Flutter (321 tes), analyzer bersih. OpenAPI 0.15.0 memiliki 72 operation IDs unik; local refs/path parameters/required lists valid, tanpa validator eksternal. PostgreSQL concurrency, Android/iOS/release devices, UAT, expiry/quality enforcement, reapproval/retur/refund serta pemulihan offline tetap belum diverifikasi/diimplementasikan sesuai scope masing-masing.

## Bukti riwayat ledger stok native

Irisan PRD 05-MOV/01-ACL, 30 Oktober 2026. Tidak ada migrasi/dependensi atau mutasi stok/audit dari fitur riwayat.

- Endpoint ledger lama mempertahankan field entry, created_at dan pagination 100; enrichment SKU/nama/pack/batch berasal snapshot InventoryBatch. Rename/pack change/nonaktif master tidak mengubah label/konversi historis. quantity_pieces tetap delta bertanda (-8, +8, +16 pada fixture buka kemasan/penerimaan), bukan running/current balance.
- Query scoped pada entry dan batch store yang sama. Cashier, kepala toko unassigned, partner lain serta pusat tanpa membership ditolak. Kepala toko/warehouse assigned membaca. Toko nonaktif tetap berizin dalam organisasi aktif; organisasi nonaktif ditolak. Foreign batch ID ditolak 404, bukan hasil nol yang menutupi scope salah.
- Filter gabungan batch/source/bucket/q, SKU/nama/batch historis, pagination 101 entry tanpa duplikasi pada dataset tetap dan input invalid diuji. Pasangan waktu ISO timezone [from,to) memetakan tanggal WIB: 29 Okt 16:59:59 UTC dikecualikan dari 30 Okt WIB, 17:00 disertakan, 30 Okt 17:00 dikecualikan. Lebih dari 31 hari/tanpa zona/pasangan tidak lengkap ditolak.
- occurred_at menambahkan UTC eksplisit, tanpa mengubah created_at legacy. Native mengartikan raw UTC legacy dengan zona eksplisit, bukan zona lokal perangkat; assertion DateTime.isUtc dan WIB midnight diuji. Store metadata/observed_at/filter scope serta private/no-store tersedia; harga tidak ditambahkan.
- GET berulang tidak menambah entry/claim/audit atau mengubah saldo/revisi batch. Source ID dan entry ID dapat disalin, tanpa menafsirkan source sebagai request UUID atau melewati izin objek sumber.
- Native DTO/filter-domain/repository/controller/page/card dipisahkan. Filter exception tidak bergantung transport HTTP. Page baru menghapus data lama saat loading/error, retry memakai filter diterapkan dan wrong-store response ditolak. Pagination hanya path lokal dan mempertahankan periode/batch/query. Menghapus batch filter mengembalikan page 1 dengan filter lain tetap.
- Widget 390px text scale 1,5/1200px menguji Stock→Riwayat batch, signed quantity, actor #ID, UTC→WIB, filter/pagination/all-batches. Kasir tanpa hint tidak melihat entry; forged deep-link tetap menerima denial API. Error tidak dipresentasikan sebagai riwayat kosong; retry kembali menampilkan entry. Seluruh request native pada tes ledger adalah GET.
- Smoke HTTP terisolasi memfilter shipment_receipt/quarantined/batch aktual menjadi satu entry +16 biji isi historis 8, UTC eksplisit dan tanpa harga. Owner pusat tanpa membership partner serta kasir ditolak. Seluruh alur supply/intake/POS/kas/minimum/report/CSV dan Compose metadata lama tetap lulus.

Bukti checks: scripts/check lulus 154 API/1315 assertions, 48 web/195 assertions dan 132 Flutter (334 tes), analyzer bersih. Setelah finalisasi exception filter dan assertion UTC, analyzer serta 8 tes riwayat diulang dan lulus. OpenAPI 0.17.0 tetap 72 operation IDs unik; local refs/path parameters/required lists valid tanpa validator eksternal. PostgreSQL concurrency, Android/iOS/release devices dan UAT belum diverifikasi. Tidak ada ekspor ledger, running balance, pemulihan UUID/offline, reapproval/retur/refund atau kebijakan mutu baru.


## Riwayat struk native — 30 Oktober 2026

- Tiga tes API tambahan (66 assertions) mencakup scope kasir/pengelola, assignment/tenant/pusat, toko nonaktif/organisasi nonaktif, interval WIB awal inklusif/akhir eksklusif, pencarian nomor, 51 struk (50+1), input invalid dan snapshot master historis. GET tidak mengubah jumlah sale/line/ledger/operation/audit, revisi/saldo batch maupun kas shift.
- Delapan tes Flutter tambahan: kalender valid dan batas 31 hari, pagination scoped tanpa URL eksternal, detail baca tanpa pembayaran, error/retry/wrong-store, 390px text scale 1,5 dan 1200px, filter belum diterapkan, scope own/store, unknown shift opening yang mengunci navigasi, serta akses riwayat toko nonaktif tanpa request katalog operasional.
- `scripts/check` menyelesaikan API 157/1381 assertions dan web 48/195 assertions serta analyzer bersih, lalu percobaan suite Flutter terhenti karena disk Docker penuh. Suite Flutter final dijalankan terpisah menggunakan SDK Flutter 3.35.7 yang direkonstruksi dari image digest terkunci yang sama: 140 tes lulus, analyzer tanpa issue dan formatter lulus. Total final 345 tes; kegagalan kapasitas awal tidak dicatat sebagai tes lulus.
- Docker driver VFS membuat salinan image besar saat membuat container. Cache image Flutter yang tidak aktif dihapus melalui API Docker resmi; API/web kembali healthy dan metadata smoke ulang lulus. Pembersihan menyeluruh dan perubahan manual direktori internal Docker tidak dilakukan. SDK host berasal tujuh layer OCI yang seluruhnya diperiksa SHA-256 terhadap manifest digest terkunci; TLS tetap aktif. Helper lingkungan tersimpan di `/workspace/shared/testyco-native`, SDK sementara di `/tmp/testyco-flutter-sdk`, cache pub dipulihkan lalu `pub get --offline --enforce-lockfile` lulus. Analyzer host memerlukan cache normal `.dartServer` pada direktori pengguna.
- HTTP smoke terisolasi lulus dengan query periode+nomor struk, scope own/store dan penolakan pemilik pusat lintas organisasi; seluruh alur supply, ledger, kas dan laporan sebelumnya tetap lulus. Tidak ada fixture ditambahkan pada DB development.
- OpenAPI 0.17.0 tetap 72 operation ID unik; referensi lokal, required fields dan parameter path diperiksa. Tidak ada validator eksternal, migrasi atau dependensi baru. Android/iOS/perangkat/printer, PostgreSQL/concurrency dan UAT tetap belum diverifikasi.


## Ganti sandi Flutter — 30 Oktober 2026

- Sepuluh tes native baru lulus: body persis tanpa trim, validasi lokal tanpa request, 422/403/429 tanpa echo input server, network/malformed/500 tanpa auto-retry, busy/double-submit/401, entry dari picker tanpa assignment dan shell toko, tampilan 390px dengan text scale 1,5 + keyboard/1200px, password toggle, route stack kosong setelah logout, serta PopScope busy yang tidak menahan reset autentikasi.
- Seluruh suite final: API 157 tes/1381 assertions, web 48/195, Flutter 150, total 355. Analyzer bersih dan formatter lulus. Menggunakan pemeriksaan ekuivalen `scripts/check` melalui SDK host terverifikasi yang telah didokumentasikan pada fase sebelumnya, sehingga tidak membuat container Flutter besar pada Docker VFS.
- HTTP smoke terisolasi lulus dengan dua token untuk kasir yang sama: ACK perubahan sandi membuat kedua token ditolak, login sandi lama ditolak, login sandi baru berhasil, dan ledger tidak berubah. Sandi pengujian dihasilkan acak dan tidak dicetak. Database/container dibuang setelah smoke; tidak mengubah akun development.
- Endpoint/server policy, OpenAPI 0.17.0 dengan 72 operasi, migrasi dan dependensi tidak berubah. Tes API sebelumnya tetap memverifikasi audit user.password_changed dengan before/after null. UI memakai pesan tetap tanpa password/echo server dan tidak menyimpan payload untuk retry.
- Gagal jaringan hanya membuktikan hasil belum pasti dan logout lokal, bukan bukti sesi remote telah dicabut. Sandi tidak dipersistenkan oleh aplikasi; secure storage, reset email, MFA, perangkat Android/iOS dan UAT belum diverifikasi.


## Jejak audit organisasi Flutter — 30 Oktober 2026

- Tiga tes API tambahan (43 assertions) lulus: pengelola mitra/tenant lain, deny kasir/kepala toko/gudang meski ada assignment, central onboarding allowlist sebelum semua filter, explicit membership pengelola, organisasi nonaktif, event global/null yang tetap tersembunyi, filter exact gabungan, awal inklusif/akhir eksklusif WIB, timestamp UTC, snapshot/actor ID, GET tanpa mutasi audit, legacy all-history 51 baris (50+1) dan validasi input/rentang.
- Tujuh tes native tambahan lulus: hint pengelola organisasi, kalender/filter/ID, pagination scoped tanpa URL eksternal, snapshot JSON/UTC, stale/error/retry/wrong-organization, navigasi delapan modul dengan overflow mobile dan rail tablet, 390px text scale 1,5 dengan keyboard dan 1200px, applied versus unsent filters, before/after, denial bukan empty serta label onboarding.
- Final checks ekuivalen scripts/check menggunakan SDK host yang sama: 160 API/1424 assertions, 48 web/195, 157 Flutter, total 365 tes lulus. Analyzer tanpa issue dan formatter lulus. Pengujian HTTP terisolasi lulus: sale.completed exact period/subject/actor menunjukkan snapshot Rp12.000 kepada pimpinan mitra, onboarding pusat mengembalikan nol untuk aksi operasional, kasir/kepala toko ditolak dan GET tidak menambah audit. Alur/password/ledger/report sebelumnya tetap lulus; tidak ada fixture pada DB development.
- OpenAPI 0.18.0 tetap 72 operasi unik, referensi lokal/path parameters/required fields diperiksa tanpa validator eksternal. Tidak ada migrasi/dependensi atau perubahan allowlist/role server. Modul native memakai FEATURE_AUDIT, bukan otorisasi client. Ekspor audit, global security-event viewer, inbox/push, PostgreSQL/concurrency, perangkat nyata dan UAT belum diverifikasi/diimplementasikan sebagaimana cakupan.


## Audit web berfilter dan snapshot — 30 Oktober 2026

- Enam tes web tambahan (66 assertions) lulus: session wajib/403/401, exact whitelist tanpa organization/scope/token/URL browser, periode inklusif WIB ke interval ISO dan default tepat tengah malam, pagination lokal berfilter, bearer hanya pada HTTP server, header/cookie boundary, HTML escaping nama/snapshot, JSON string/array/legacy/null, old-input isolation, query invalid tanpa backend call, onboarding empty terpisah dari wrong-organization/503/koneksi gagal.
- Seluruh final checks ekuivalen scripts/check: API 160 tes/1424 assertions, web 54/261, Flutter 157, total 371. Analyzer Flutter bersih dan Blade view:cache lulus. Native regression menggunakan SDK host terverifikasi yang sama, tanpa container Flutter besar pada VFS.
- Smoke HTTP nyata terisolasi lulus dengan login web pimpinan mitra menampilkan audit sale.completed Rp12.000, serta login admin pusat pada cookie jar lain menunjukkan hanya onboarding dan tidak menampilkan audit operasional mitra. GET API/web tidak menambah audit. Seluruh alur CSRF/supply/ledger/POS/kas/report/password sebelumnya tetap lulus; akun/database development tidak dimutasi.
- API/OpenAPI 0.18.0 (72 operasi), migrasi, dependensi dan izin backend tidak berubah. Ini pemeriksaan HTML/server, bukan pengujian interaksi/visual browser nyata. Browser mobile/tablet, PostgreSQL/concurrency dan UAT tetap belum diverifikasi.


## Riwayat ledger stok web — 31 Oktober 2026

- Tujuh tes web tambahan (84 assertions) lulus: session/403/401, whitelist query, kalender WIB inklusif ke interval ISO dan default tengah malam, batch/source/bucket/search/pagination lokal, old-input isolation/no-store/token-cookie boundary, snapshot historis isi 8/signed -8 dan +16, waktu UTC ke WIB, source ID/pelaku, HTML escaping/tanpa harga, filter invalid tanpa HTTP, mismatch store/row/batch serta 404, inactive empty versus connection failure, dan hint navigasi staff/kasir.
- Kalender audit, laporan tunai dan ledger kini memakai Shared/Application/WibPeriod; 18 tes target audit/report/ledger lulus (174 assertions) sebelum suite penuh. Tidak ada perubahan definisi periode/metrik/izin. Final checks ekuivalen scripts/check: API 160 tes/1424 assertions, web 61/345, Flutter 157, total 378; analyzer bersih, Blade view:cache lulus. Native regresi memakai SDK host terverifikasi tanpa container Flutter besar pada VFS.
- Smoke HTTP terisolasi lulus: halaman ledger berfilter exact batch/source/quarantined/search menampilkan +16 biji dan pack_size historis 8; tautan per batch tersedia bagi pimpinan, dan sesi web kasir ditolak 403. Semua alur audit/report/password/supply/ledger/POS/kas terdahulu tetap lulus. Pengujian hanya menggunakan DB/container sementara, tidak menambah akun atau mutasi pada development DB.
- API/OpenAPI 0.18.0 (72 operasi), schema, dependensi dan policy backend tidak berubah. Tidak ada perhitungan running balance, ekspor ledger, mutasi stok/kas, pemulihan UUID/offline atau aturan mutu baru. HTML/server diuji; interaksi/visual browser mobile/tablet, perangkat nyata, PostgreSQL/concurrency dan UAT belum diverifikasi.


## Pencarian riwayat struk web — 1 November 2026

- Tujuh tes web baru (71 assertions) lulus: auth/403/401, all-history default dan kedua tanggal kosong, pencarian nomor tanpa tanggal, paired WIB inclusive range/local pagination, whitelist tanpa cashier/store/scope/token/URL browser, no-store/token-cookie boundary, old-input isolation, snapshot/timezone/HTML escaping dan receipt link lokal tanpa pembayaran, mismatch store/row/unknown scope/503, input invalid/unpaired tanpa HTTP, inactive/empty/connection failure. Fixture tes riwayat lama dilengkapi envelope API saat ini; assertion page/URL/nomor lama tetap berlaku.
- Final equivalent scripts/check: API 160 tes/1424 assertions, web 68/416, Flutter 157, total 385 tes lulus. Analyzer bersih dan Blade view:cache lulus. Native regresi memakai SDK host terverifikasi yang sama tanpa container Flutter besar pada VFS.
- Smoke HTTP nyata terisolasi lulus: riwayat web kasir berfilter nomor/periode dengan private/no-store menampilkan struk Rp12.000, pencarian tanpa tanggal tetap all-history, pimpinan berwenang membaca struk kasir toko, dan sesi web admin pusat tanpa membership mitra ditolak 403. Pemeriksaan saldo stok/kas serta audit/report/password/supply/ledger/POS sebelumnya tetap lulus; DB dan fixture sementara tidak menyentuh development DB.
- Tidak ada perubahan API/OpenAPI 0.18.0 (72 operasi), policy pembayaran/izin, schema atau dependensi. Controller baca dipisahkan dari checkout; tidak ada mutasi, refund, ekspor, antrean offline atau cetak perangkat baru. HTML/server diuji, bukan interaksi/visual browser nyata; mobile/tablet browser, perangkat/printer, PostgreSQL/concurrency dan UAT belum diverifikasi.


## Penelusuran kedaluwarsa batch — 1 November 2026

- Tiga tes API baru (40 assertions) dan enam tes web baru (57 assertions) lulus. Cakupan: inclusive independent bounds, AND-scoped search/balance OR, quarantined-only termasuk dengan available0 dan waste-only dikecualikan, historical snapshot setelah rename/pack/status master berubah, no prices/no DB writes/revision/audit/claim changes, cashier assignment tetap diizinkan/unassigned/tenant/central denied, inactive-store read/inactive-org denied, legacy100+1 order, invalid query; BFF whitelist/private/cache/token-cookie boundary, no horizon/31-day cap, local pagination/old-input isolation, escaped historical metadata, wrong-store/empty/error/connection/inactive states.
- Full equivalent checks: API163/1464 assertions, web74/473, Flutter157, total394 tes lulus, analyzer bersih dan Blade cache compiled. Flutter/POS/batch-read callers existing tetap kompatibel dengan envelope tambahan. Native SDK host terverifikasi digunakan sesuai workflow VFS.
- Real isolated HTTP smoke lulus: batas expiry tepat tanggal batch menemukan satu batch historis isi8 tanpa perubahan revision; batas sebelumnya menemukan0; web menampilkan tanggal/saldo termasuk16 karantina dan link navigasi; sesi kasir web yang sudah ada tetap dapat membaca. Seluruh workflow stok/kas/penjualan/audit/report/password sebelumnya tetap lulus dan normal development DB tidak dimutasi.
- OpenAPI0.19.0 tetap72 operasi unik; referensi lokal/path parameters/required lists diperiksa. Tidak ada migrasi database/dependensi, default horizon, push/recall/food-quality certification, sale block atau FEFO baru. Server/HTML diperiksa, bukan browser/perangkat nyata/UAT/PostgreSQL concurrency.


## Pengurangan pascapersetujuan API/web — 2 November 2026

- Delapan tes API baru/123 assertions: original quote tidak berubah saat pending, hold proses/dispatch/cancel, frozen harga/pack sesudah master berubah, penerapan pada ID baris sama, kuota dispatch berkurang, nol stock/revision/shipment sebelum dispatch, replay proposal/keputusan termasuk setelah keputusan lain, UUID collision/stale, deny role/lintas tenant/wrong parent, reject/withdraw, semua baris/min1/no increases, target nonaktif, internal total0 dan used migration rollback guard. PUT amendment lama tetap menolak approved.
- Tujuh tes web baru/74 assertions: capability sender/recipient, escaped snapshot, body/ACK exact, request unknown menahan replacement dan tetap immutable melewati denial, GET pending tanpa API, retry mengabaikan edit browser, malformed ACK, definitive422, 401 invalidation, login account switch dan required decision reason.
- HTTP terisolasi nyata: supplier mengusulkan20→18, dispatch ditolak409 saat pending, penerima menolak lewat form CSRF sehingga original20/Rp360.000 tetap; order terpisah2→1 melalui form supplier dan persetujuan web penerima menjadi Rp18.000 pada ID baris sama, tanpa ledger baru. Workflow stok/actual receipt/POS/kas/audit/report/password sebelumnya tetap lulus. Tidak ada fixture pada DB development.
- Full checks ekuivalen scripts/check: API171/1587 assertions, web81/547, Flutter157, total409 lulus dan analyzer bersih. Native memakai SDK host terverifikasi sesuai fallback VFS existing. Compose healthy, metadata smoke dan Blade cache lulus. Migrasi tambahan diaplikasikan tanpa reset database development. OpenAPI0.20.0:77 operation IDs unik, refs/path parameters/required lists diperiksa lokal, bukan validator eksternal.
- Pengujian server/HTML dan native regresi bukan browser/device/UAT atau PostgreSQL concurrency. Fitur hanya pengurangan semua baris existing min1 sebelum kirim; substitusi/penambahan/penghapusan baris, after-shipment/return/credit, composer reapproval native dan antrean tahan restart tetap backlog.


## Persetujuan ulang Flutter mobile/tablet — 2 November 2026

- 22 tes native baru lulus. Controller/repository: explicit server capability, scope parent/row, frozen snapshot ID/SKU/harga/isi, jumlah/all-lines/alasan, review belum menulis, deep immutable nested payload/review, candidate stale setelah refresh, original version/change path, approve/reject/withdraw, internal0 dan pagination lokal tanpa external URL.
- Unknown setelah commit→403→ACK retry mempertahankan UUID/path/body persis dan satu write. Refresh/pagination/replacement diblokir; ACK parent/version/status/change yang salah menahan request. Initial403/409/422 membebaskan pending tetapi tidak memberi fresh context;429 menahan. ACK dengan reload gagal tetap saved dan tidak mengizinkan write baru.401 memicu callback sesi existing tanpa mengaktifkan aksi lain di halaman lama.
- Widget supplier masuk dari detail Gudang, mengisi20→12, meninjau Rp360.000→Rp216.000 tanpa POST sebelum konfirmasi; unknown menahan pop/refresh dan retry identik. Widget penerima masuk dari detail Pesanan, meninjau alasan/snapshot dan menerima, serta rejection dengan required reason mempertahankan original20.390px text scale1,5/inset keyboard280 dan1200px lulus tanpa overflow. Masalah posisi gulir hasil pada mobile diperbaiki agar notice unknown dan context baru muncul dari atas; pengujian lazy history memakai scroll sampai tombol dapat disentuh.
- Final checks ekuivalen scripts/check dengan native SDK host fallback terverifikasi: API171/1587 assertions, web81/547, Flutter179, total431 lulus; analyzer bersih dan formatter lulus. Compose healthy, metadata smoke dan HTTP terisolasi nyata tetap lulus untuk workflow supply/POS/kas/audit/report/password serta proposal/hold/reject/accept backend/web sebelumnya. Ini bukan eksekusi Flutter pada perangkat fisik.
- Kontrak OpenAPI0.20.0 tetap77 operasi; refs/path parameters/required diperiksa lokal. Tidak ada perubahan endpoint/policy/schema/dependensi/migrasi server. Native memakai registry/FEATURE_ORDERING existing; capability tetap authority API. Pending hanya memori halaman, bukan durable offline/restart queue. UAT, browser/Android/iOS/perangkat/printer dan PostgreSQL concurrency belum diverifikasi. Substitusi/penambahan/penghapusan baris, revisi sesudah kirim dan retur/kredit tetap backlog.


## Penelusuran kedaluwarsa batch Flutter — 2 November 2026

- 11 tes native baru lulus: default tanpa horizon, batas kalender independen inklusif/rentang panjang tanpa cap31 hari, kabisat/invalid/reversed/search length, exact query dan snapshot historical pack/quarantine16/available0/revision, immutable rows, pagination/refresh dengan applied filters/resetpage1 tanpa external URL, foreign envelope/row/pagination denial,403/503/empty/inactive dan401 callback.
- Widget390px text scale1,5 dan1200px masuk dari Stok dengan canControl=false, tanpa menawarkan ledger/pengolahan. Inset keyboard280, upper-only/query/with_balance, historical condition/pack/date/quarantine dan observed_at WIB ditampilkan. Edit draft tanpa apply tidak mengganti query pagination. Form invalid tidak mengirim request; read403 tidak menampilkan stale batch atau empty success. Semua request GET ke path toko yang sama.
- Final checks ekuivalen scripts/check: API171/1587 assertions, web81/547, Flutter190, total442 tes lulus. Analyzer tanpa issue; format136files tidak berubah. Native memakai SDK host fallback terverifikasi, tanpa container Flutter besar pada VFS. Compose healthy, metadata smoke dan full HTTP terisolasi nyata lulus termasuk penelusuran expiry backend/web serta workflow reapproval/supply/POS/stock/cash/audit/report/password sebelumnya; tidak menambah fixture pada development DB.
- Kontrak OpenAPI0.20.0 tetap77 operasi, local refs/path parameters/required lists valid. Tidak ada perubahan API/schema/migrasi/dependensi/otorisasi atau kebijakan mutu. Ini widget/server, bukan verifikasi perangkat Android/iOS/browser visual, PostgreSQL concurrency atau UAT. Penelusuran tidak mengaktifkan default warning horizon, FEFO, sale block, recall atau notifikasi baru.


## Reaktivasi akun API/web — 2 November 2026

- 7 tes API baru/96 assertions: exact membership/role/tenant guards, inactive/state/version/alasan/UUID, global owner/self termasuk ID nol depan, multi-org central-only, inactive review org, snapshot penugasan dan password tidak berubah, token dihapus, membership update membatalkan review, once-only replay historis sesudah deaktivasi berikutnya tidak menghidupkan akun lagi, collision/stale rollback, actual audit sebelum/sesudah, allowlist onboarding baru tanpa akses audit penjualan dan migration down guard. Token nyata lama tetap401 sesudah reaktivasi; login baru berhasil.
- 8 tes web baru/90 assertions: entry akun nonaktif dari organisasi, protected owner/self tanpa link, escaped metadata dan scoped role/stores, foreign envelope/assignment503, capability/versi di-recheck sebelum POST, validated payload tanpa role/URL/active browser, immutable session/path/body/review pada unknown→403→ACK dan penggantian ditahan termasuk route organisasi lain/ID nol depan, mismatch ACK/401, initial validation/stale/422,429 ditahan serta successful login membersihkan pending akun lama. Fake HTTP factory direset antar fase agar respons baru tidak tertutup wildcard lama.
- HTTP terisolasi nyata lulus: staff sementara login, dinonaktifkan (token lama401/login422), review CSRF web versi2 lalu aktif versi3, replay UUID menghasilkan satu audit, token lama tetap401, login baru dan assignment toko semula berhasil. Ledger tidak bertambah. Alur supply/reapproval/kontrol stok/POS/kas/audit/report/password sebelumnya tetap lulus. Akun/database development tidak diberi fixture; migrasi access_version diaplikasikan tanpa reset.
- Full checks ekuivalen scripts/check: API178/1683 assertions, web89/637, Flutter190, total457 tes lulus; analyzer bersih dengan native SDK host fallback terverifikasi. Compose healthy, metadata smoke, Blade cache dan Composer validate strict lulus. Pint pada file PHP baru lulus setelah perapian. OpenAPI0.21.0:79 operasi unik, refs/path parameters/required lists diperiksa lokal; bukan validator eksternal.
- Status tetap global, bukan membership activation. Perlindungan owner diperketat ke seluruh membership pada deaktivasi/reaktivasi; owner/self/multi-org bukan capability yang boleh diinferensi frontend. Reactivation tidak mengubah kredensial/role/assignment, memberi token otomatis, memulihkan session lama, membuka audit operasional mitra, atau menambah mutasi stok/kas. Legacy deactivate tetap204 dan kini menaikkan revision dengan actual before state.
- Browser interaksi/visual, perangkat fisik, PostgreSQL concurrency, deployment dan UAT belum diverifikasi. Session pending bukan recovery lintas restart; reset email/MFA, owner recovery dan penggabungan membership lintas organisasi tetap backlog.


## Ekspor CSV audit web — 2 November 2026

- 5 tes API baru/51 assertions: scope/deny role/tenant/global-null, shared onboarding allowlist termasuk reaktivasi tanpa product snapshot, inactive organization, exact filters/UTC-WIB boundaries,51 matching records melewati pagination50 tanpa page filter, snapshot historis/no master join/no audit/receipt/ledger writes, tepat10.000 diterima dan10.001 ditolak tanpa data parsial,8MiB ditolak serta required period/filter validation.
- 7 tes web baru/98 assertions: authenticated scoped query whitelist tanpa page/scope/URL/token browser, fixed CSV headers/private/no-store/nosniff dan API cookie/disposition tidak disalin, UTF8 BOM/quoting/comma/newline/JSON/null/actor, prefix formula ASCII/Unicode/full-width/control, explicit UTC, default WIB/applied-link filters, invalid calendar/range/type tanpa API, wrong organization/row/count/duplicate/snapshot/oversized response/timestamp (termasuk tanggal mustahil/relative time), error/limit feedback/connection/401 dan onboarding empty header.
- Live HTTP terisolasi nyata lulus: pimpinan mitra mengekspor satu sale.completed yang sama dengan reader, after snapshot Rp12.000, scope organization/privateCSV; admin pusat pada cookie jar lain dengan filter sama mendapat header-only onboarding, tanpa kebocoran event penjualan. GET/export tidak menambah audit atau ledger dan semua flow supply/reapproval/accountreactivation/stock/POS/cash/report/password sebelumnya tetap lulus. Tidak ada fixture di DB development.
- Final equivalent checks scripts/check: API183/1734 assertions, web96/735, Flutter190, total469 lulus; analyzer bersih melalui SDK host fallback terverifikasi. Composer strict validate, Pint file PHP baru/shared AuditHistory, Blade cache, Compose healthy dan metadata smoke lulus. OpenAPI0.22.0:80 operasi unik/refs/path/required valid lokal, actor_id nullable diperjelas sesuai response lama; bukan validator eksternal.
- API export adalah bounded JSON untuk BFF; CSV dibuat di frontend HTTP terpisah tanpa DB access. Query scope/allowlist/entry mapper/validasi digunakan bersama reader; reader all-history pagination50 lama tetap lulus. Tidak ada migrasi/dependensi, perluasan role/allowlist, mutasi audit/keuangan atau file permanen baru. Timestamp dan sel CSV adalah data, bukan executable formula atau snapshot restore.
- Browser interaksi/visual, perangkat nyata, UAT, load production dan PostgreSQL concurrency belum diverifikasi. Ekspor terjadwal/besar dan unduhan file native belum dibuat.


## Ekspor CSV ledger stok — 2 November 2026

- 5 tes API baru/55 assertions: staff/assignment/tenant guards (termasuk kasir assigned dan pusat tanpa membership ditolak), inactive store/organization behavior, actual apply fixture16→-8/+8 dengan master SKU/pack berubah tetap snapshot8, no batch/revision/ledger/audit/receipt writes saat export, exact batch/time/source/bucket dan q OR tetap scoped,101 matching rows tanpa pagination,10.000 allowed/10.001 dan8MiB fail422 tanpa truncate, invalid period/filter.
- 6 tes web baru/78 assertions: scoped whitelist tanpa page/store/URL/token browser, private/no-store/nosniff/fixed filename dan cookie boundary, -8 numeric berbeda dari note "-8" terlindungi, quotes/newline/null/Unicode formula, extra price data tidak disalin, wrong envelope/row/batch/duplicate/number/time/payload fail sebelum CSV, invalid filters tanpa request,403/404/422/message feedback/503/network/401 serta default WIB/inactive empty/applied-link filters.
- Shared BoundedExport API dan CsvDownload/ExportTime web diekstrak lalu seluruh audit export/reader lama tetap lulus. Tidak mengubah byte/row bounds atau isi/format CSV audit; actor text tetap melalui CsvText, ledger integer dipercaya hanya setelah validasi tipe. Ledger query/mapper lama dibagi dengan export tanpa mengubah pagination100/all-history pada read API.
- Full equivalent scripts/check lulus: API188/1789 assertions, web102/813, Flutter190, total480; analyzer bersih via SDK host fallback, Pint file PHP terkait lulus. Compose healthy, metadata smoke dan HTTP terisolasi nyata lulus: receipt16 karantina/pack8/source sama dalam CSV, revision tidak berubah, pusat/kasir ditolak, audit CSV dan seluruh supply/reapproval/reactivation/POS/stock/cash/report/password sebelumnya tetap lulus. Tidak ada fixture pada DB development. Blade cache/Composer strict dan local OpenAPI refs/path/required diperiksa.
- OpenAPI0.23.0:81 operasi; source_id opaque diperjelas, tanpa migrasi/dependensi/role baru. CSV bukan saldo akhir, running balance, UUID recovery atau restore/import. Browser visual/interaksi, perangkat fisik, PostgreSQL concurrency/load production dan UAT belum diverifikasi; export file native/terjadwal belum dibuat.
