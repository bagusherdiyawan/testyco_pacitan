# Jurnal sumber ERP/POS dan nilai batch — ADR052

Implementasi development 9 November2026. API Laravel, BFF web, Flutter native mobile/tablet memakai otoritas server yang sama. Ini melanjutkan GL manual ADR051; bukan aktivasi otomatis pada data usaha yang sudah ada.

## Menggunakan

1. Owner/admin pusat, atau pimpinan mitra pada organisasi sendiri, membuka **Pembukuan dan jurnal otomatis**. Buat COA dan periode terbuka; isikan pemetaan setiap fungsi secara eksplisit. Tidak ada nomor akun, tarif, pajak, atau nilai persediaan bawaan.
2. Pengelola lain meninjau dan menyetujui pemetaan. Akun wajib aktif, milik sendiri, sesuai kelompok/saldo normal, berbeda antar fungsi. Pemetaan yang disetujui adalah standing authority untuk sumber baru. Versi baru menggantikan pemetaan aktif; sumber lama tetap memakai snapshot lama. Hentikan tangkapan baru melalui tindakan suspend bila perlu.
3. Rekonsiliasi saldo pembukaan GL dan seluruh persediaan lama sebelum memakai laporan sebagai buku lengkap. Bahan lama memerlukan jurnal saldo awal manual yang ditinjau. Produk jadi tanpa nilai dapat diajukan melalui **Nilai persediaan produk**, lalu pengelola berbeda menyetujui di **Review nilai saldo awal**. Jumlah/revisi batch harus tetap sama. Nilai produk yang sudah diketahui tidak dapat ditimpa. Persetujuan saldo awal menghasilkan Dr persediaan produk/Cr saldo awal, sehingga jangan menduplikasi jurnal pembukaan yang sudah ada. Penilaian ini hanya stok tersisa saat persetujuan, tidak memperbaiki HPP penjualan lampau.
4. Kerjakan transaksi ERP/POS biasa. Sumber dan event akuntansi disimpan dalam transaksi DB yang sama. Scheduler Compose menjalankan `php artisan accounting:post --limit=100` tiap menit; command yang sama dapat dijalankan operator deployment. Penjualan berhasil berarti transaksi usaha tersimpan; status posted dilihat di **Antrean jurnal sumber**.
5. Periksa **Jejak nilai / HPP batch**, antrean dan neraca saldo. Periode tidak dapat ditutup jika masih ada draft manual atau event belum posted/reconciled. Periode tertutup/tidak tersedia tidak digeser ke hari lain: buka periode asal dan antrekan ulang. Nilai/ownership yang belum jelas memerlukan jurnal manual dengan bukti, ditinjau pengelola lain, lalu ditautkan ke satu event pada tanggal sumber yang sama. Jurnal yang sudah ditautkan tidak bisa dipakai lagi. Penautan rekonsiliasi tidak mengubah pool nilai fisik.

## Posting yang tersedia

| Sumber | Debit | Kredit |
| --- | --- | --- |
| Bahan diterima dari PO disetujui | Persediaan bahan termasuk karantina | Utang supplier |
| Saldo awal bahan baru eksplisit | Persediaan bahan | Lawan saldo awal |
| Pemakaian aktual produksi | Barang dalam proses | Persediaan bahan |
| Hasil produksi jadi / adonan | Persediaan produk / bahan | Barang dalam proses + akrual tenaga kerja/overhead eksplisit |
| Produksi nihil hasil baik | Kerugian produksi | Barang dalam proses + akrual biaya eksplisit |
| Pembayaran supplier diverifikasi | Utang supplier | Kas / bank sesuai metode |
| Retur bahan | Pengurangan utang sesuai kredit disetujui + kerugian selisih | Nilai historis bahan dikembalikan |
| Refund supplier diverifikasi | Kas / bank | Utang/refund supplier |
| Penjualan POS biasa | Kas / non-tunai terverifikasi + diskon issuer | Pendapatan bruto |
| HPP POS | HPP penjualan | Persediaan produk batch aktual |
| Refund POS | Pengembalian penjualan | Kas / bank |
| Barang refund kembali karantina | Persediaan produk, biaya alokasi asal | Pembalikan HPP |
| Bahan ditolak, hasil QC ditolak, produk menjadi waste | Kerugian persediaan | Nilai bahan / produk aktual |
| Opname bahan disetujui | Persediaan / selisih opname | Selisih opname / persediaan |

Reklasifikasi bucket produk dan transfer bahan dalam organisasi mempertahankan nilai; kedua akun persediaan mencakup karantina. Biaya tenaga kerja/overhead adalah akrual/penampung biaya yang dipilih, bukan bukti pembayaran bank. Tidak ada jurnal terpisah untuk harga jual yang dipakai sebagai HPP. Nilai gagal saat ada output mengikuti pilihan `all_to_good_output` yang sudah disetujui pada produksi.

## Nilai produk jadi dan retur

Pool nilai per batch mencakup usable/opened/ready/quarantined, terpisah dari waste. Produksi baru memasukkan total biaya proses aktual; transfer antar bucket tidak menciptakan biaya baru. Pengeluaran mengambil proporsi integer dari nilai dan jumlah tersisa, sehingga penjualan bertahap menghabiskan sisa pembulatan tepat. Setiap perubahan menyimpan batch/revisi/source/quantity/value delta; sale source adalah ID alokasi stok, termasuk komponen bundle.

Retur memakai snapshot biaya alokasi penjualan dan offset unit historis. Refund tanpa stok tidak membalik HPP. Retur parsial setelah refund tanpa stok tetap mengambil bagian biaya unit yang benar, bukan menghitung ulang memakai harga atau biaya batch saat ini. Stok retur masuk karantina. Pool yang tercampur tambahan masuk tanpa bukti biaya menjadi unknown; sistem tidak mempertahankan angka yang menyesatkan. Pool kosong memiliki nilai nol, tetapi biaya pengeluaran historis unknown tetap unknown.

POS offline menghasilkan event hanya setelah sync/review berhasil membukukan transaksi server. Tanggal penjualan tetap tanggal WIB captured_at, bukan tanggal sync. Tidak ada posting lokal, asumsi urutan FIFO offline, reservasi biaya, atau perbaikan histori ketika saldo awal baru dinilai.

## Batas yang tetap eksplisit

- Ini mencakup siklus bahan → produksi/adonan → produk → POS serta pembayaran/retur supplier dan refund POS. Penjualan/POS non-tunai tetap diverifikasi manual; tidak ada gateway atau pembayaran bank otomatis.
- GL keuangan wholesale invoice/credit/payment kini tersedia secara opt-in pada [ADR053](wholesale-accounting.md). Perpindahan nilai produk lintas batch/organisasi, HPP distribusi, konsinyasi dan pendanaan promo sponsor masih memerlukan rekonsiliasi GL. Pergerakan distribusi produk dan sumber POS yang ditandai konsinyasi/promo sponsor ditahan sebagai kasus rekonsiliasi. Pembukuan komersial yang sudah ada tetap berjalan di modulnya; jangan menggandakan settlement sebagai penjualan sendiri. Jangan memberi nilai opening barang konsinyasi seolah-olah aset milik mitra.
- Intake produk lama/warehouse tanpa biaya tidak diperkirakan dari harga wholesale/retail. Ajukan penilaian eksplisit stok tersisa; HPP lama tetap perlu rekonsiliasi. Koreksi jumlah produk tanpa biaya dan penerimaan distribusi masuk review. Opname bahan tetap mempunyai nilai koreksi eksplisit dari workflow sebelumnya.
- Tidak ada import seluruh histori, posting pajak otomatis, neraca/laba-rugi formal dengan jurnal penutupan otomatis, supplier payment reversal, atau perubahan paket2–6. Neraca saldo hanya menggambarkan jurnal posted, termasuk rekonsiliasi yang telah ditinjau.
- Akun jurnal sumber tidak dapat dibalik melalui reversal manual tanpa mengubah sumber. Gunakan refund/retur/koreksi usaha serta jurnal koreksi terpisah yang ditinjau dengan bukti. Histori posted tidak dihapus.
- Pusat tidak memperoleh akses buku mitra tanpa membership nyata. Pimpinan mitra hanya mendapat bagian akuntansi, tidak manufacturing/QC/grant operator. Warehouse/kasir/operator tidak mendapat akses pembukuan.
- Pengujian memakai SQLite development. PostgreSQL concurrency, penutupan cutover/rekonsiliasi saldo bisnis nyata, akuntan/UAT dan build/perangkat rilis belum diverifikasi. Tidak ada data usaha normal, akun contoh, pemetaan aktif atau transaksi finansial nyata dibuat oleh pengujian.

## Kontrak dan verifikasi

Migrasi27 menambah tabel kebijakan, event outbox, pool nilai, cost entries dan proposal nilai. Rollback menolak penghapusan histori terpakai. API memakai `/organizations/{organization}/erp/context?section=posting|posting_events|valuations|valuation_stock|cost_ledger` dan action `posting_policy|posting_decide|posting_suspend|posting_retry|posting_reconcile|value_propose|value_decide`. Semua mutasi memakai UUID/actor/body/version dan audit ERP. Web memakai Backend HTTP, Flutter memakai repository/forms native bersama; pending unknown tetap membekukan request persis. ACK diikuti GET baru.

`AutomaticAccountingTest` memeriksa siklus penuh, pembulatan3/3/4, refund parsial dengan/sesudah no-stock, replay, rollback, saldo awal dan reviewer berbeda, periode/retry, supplier receipt/payment/return/refund, own-tenant/partner finance, rekonsiliasi manual dan larangan reversal sumber. `AutomaticAccountingWebTest` memeriksa review/unknown/403/retry identik dan penolakan scope. `automatic_accounting_test.dart` memeriksa390/1200px, menu finance dan scope. `scripts/smoke-identity.py` menambah skenario HTTP terisolasi termasuk real worker dan BFF.

Lanjutan reversal supplier: `payment_reverse` memerlukan purchase ID/version, payment_id, verified dan note; pengelola lain dari pembuat pembayaran baru wajib memeriksa. Histori pembayaran dipertahankan dengan reversed_at/by/note; saldo paid/refunded dikurangi sekali dan inverse event memakai policy asal. Reversal pembayaran keluar ditahan bila refund supplier masih tercatat; balik/rekonsiliasi refund dahulu. Data lama tanpa actor_id ditolak untuk reversal otomatis. Form tersedia pada pembelian di web/native. Verifikasi terarah41tes ERP/accounting dengan1200assertions lulus, termasuk independent reviewer, replay dan duplicate reversal denial. Ini tidak menyelesaikan sisa distribusi/komersial/closing/UAT.
