# Testyco Pacitan

Monorepo Laravel API, Laravel web terpisah dan Flutter mobile/tablet untuk jaringan mitra dan POS dimsum.

**DL-01–DL-20 telah diimplementasikan dalam cakupan development**: operasional stok/pesanan, POS/shift, retur/tagihan/voucher, kontrak/aset, inbox/SMTP/SOP, MFA dan offline tunai dengan review konflik. Lihat [status tiap delivery/platform](docs/product/delivery-status.md) dan [panduan DL-18–DL-20](docs/reliability-and-offline.md). PostgreSQL/concurrency, perangkat nyata dan UAT tetap gate produksi. Delivery tambahan [printer, bagi hasil otomatis, sponsor promo dan recall terkoordinasi](docs/printing-commercial-and-recalls.md) kini tersedia pada cakupan yang dijelaskan di panduan tersebut.

| Direktori | Isi |
| --- | --- |
| `apps/api` | Laravel 12 + Sanctum, provider Identity, Catalog, Partnership, Ordering, Inventory, Fulfillment, Shifts, Promotions, Sales, Billing, Reporting, Assets dan Reliability, persistence SQLite untuk development |
| `apps/web` | Laravel 12 + Blade, frontend/BFF melalui HTTP; token di session server, CSRF pada form |
| `apps/pos` | Flutter 3.35.7, login, toko, katalog, stok, pesanan dan POS tunai/shift mobile/tablet |
| `packages/design-tokens` | Token visual bersama; komponen atomic design native tiap framework |
| `contracts/openapi.yaml` | Kontrak API 0.33.0 (JSON valid YAML, OpenAPI 3.1) |
| `docs/product` | Kebutuhan, keputusan terbuka dan roadmap |
| `docs/reference/Markdown` | Dokumen sumber dari lampiran ZIP |

[ERP bahan baku dan produksi](docs/manufacturing-erp.md) kini menghubungkan penerimaan bahan/pembelian supplier, resep berversi termasuk adonan setengah jadi, pemakaian aktual, biaya/HPP, hasil karantina dan pemeriksaan owner/admin pusat sebelum masuk stok distribusi/POS. Web dan Flutter native tersedia melalui administrasi pusat. Lihat batas cakupan dan skenario uji pada panduan; status DL-01–DL-20 sebelumnya bukan klaim ERP manufaktur lengkap atau siap produksi.

[Pembukuan otomatis ERP/POS](docs/automatic-accounting.md) dan [keuangan wholesale](docs/wholesale-accounting.md) tersedia setelah pemetaan akun disetujui pengelola berbeda pada organisasi masing-masing. Nilai/HPP distribusi dan komersial mengikuti batas rekonsiliasi yang dijelaskan di panduan.

## Setup dan akun pertama

Prasyarat Linux x86_64, Docker Engine + Compose v2, Python 3. Runtime PHP 8.5.12/Composer dan Flutter memakai image dengan digest terkunci. Jika UID/GID host bukan 1000, ekspor `TESTYCO_UID=$(id -u)` dan `TESTYCO_GID=$(id -g)` sebelum menjalankan Compose; CI sudah melakukannya. Container Composer untuk development, bukan deployment production.

```bash
scripts/setup
scripts/bootstrap
docker compose up -d --wait
python3 scripts/smoke.py
```

`scripts/setup` memasang dependensi dari lock, membuat `.env` hanya bila belum ada, menghasilkan token visual dan menjalankan migrasi API tanpa reset data. SQLite berada pada `apps/api/database/database.sqlite` dan tidak masuk Git. Session/cache web menggunakan file. Compose mempertahankan environment override melalui `--no-reload` dan mengatur NO_PROXY untuk API internal. `.env` yang dibuat sendiri harus memiliki `APP_KEY` valid.

Jalankan `scripts/bootstrap` di terminal interaktif. Masukkan nama, email dan kata sandi pemilik pusat melalui prompt tersembunyi. Tidak ada password bawaan. Bootstrap menolak menimpa pusat yang sudah ada. Jangan menaruh password di argumen perintah atau source.

Web menggunakan port 8080 dan API 8000 pada loopback untuk pengujian lokal. Buka halaman login web pada lingkungan development yang Anda kelola. Pada onboarding cloud, validasi menggunakan request lokal tanpa tautan preview.

## Alur yang tersedia

1. Pemilik pusat masuk ke web, mendaftarkan mitra, membuat toko, lalu membuat akun pimpinan mitra dengan peran `partner_lead`.
2. Pimpinan masuk, membuka katalog organisasinya, lalu membuat SKU, varian, kondisi beku/siap jual, konversi kemasan/porsi, harga grosir per kemasan dan harga eceran per biji (integer rupiah/IDR).
3. Pimpinan membuat kasir/kepala toko dan memilih toko yang ditugaskan. Perubahan role/penugasan mencabut seluruh token pengguna yang diubah.
4. Kasir masuk lewat Flutter, memilih toko aktif yang ditugaskan dan melihat/mencari produk aktif. Harga grosir tidak dikirim pada endpoint katalog toko.
5. Pengelola dapat menonaktifkan toko/akun tanpa menghapus riwayat. Pusat dapat mengaktifkan/nonaktifkan mitra. Pemilik pusat tidak dapat menonaktifkan pusat lewat API ini agar jalur pengelolaan tidak terkunci.
6. Web dan Flutter menyediakan [pergantian kata sandi](docs/native-password-change.md) dengan verifikasi kata sandi lama; seluruh token dicabut setelah berhasil. Logout mencabut token saat ini.

Admin pusat dapat mengelola metadata onboarding mitra; hak ini tidak otomatis membuka katalog operasional mitra. Audit yang dilihat pusat tanpa keanggotaan mitra terbatas pada perubahan identitas/onboarding, sehingga harga tidak bocor lewat audit.

Pengelola kini dapat membuka **Organisasi → Pengguna → Tinjau reaktivasi akun** untuk akun nonaktif yang diizinkan. [Reaktivasi](docs/account-reactivation.md) memeriksa versi akses, mencatat alasan dan mewajibkan login baru; role/penugasan/password tetap.

## Pesanan dan stok

API/web dan [Flutter mobile/tablet](docs/native-order-reapproval.md) kini menyediakan [usulan pengurangan setelah persetujuan](docs/order-reapproval.md), sebelum pengiriman pertama, dengan hold pengiriman, persetujuan ulang penerima dan riwayat snapshot. Revisi SKU/sisa setelah kirim serta retur karantina/kredit kini tersedia dalam alur terpisah.

[Kasus selisih penerimaan](docs/shipment-discrepancies.md) kini tersedia melalui API/web: snapshot rusak/kurang, catatan bersama, usulan rencana owner/admin pusat dan konfirmasi pimpinan mitra. Konfirmasi tidak otomatis memindahkan stok. [Eksekusi barang pengganti](docs/replacement-fulfillment.md) kini tersedia di API/web: kirim parsial dari batch sumber, terima aktual dan pencatatan stok hingga selesai diterima baik. [Layar kasus/pengganti Flutter](docs/native-shipment-cases.md) juga tersedia. [Retur karantina dan kredit](docs/finance-and-pos-adjustments.md) kini tersedia dengan persetujuan terpisah, tanpa mengubah penerimaan awal.

Buka tautan **Pesanan dan stok** pada toko atau **Pesanan pusat** pada navigasi. Alur: pengajuan → persetujuan pimpinan → gudang sumber → alokasi batch/pengiriman → penerimaan aktual. Harga dan konversi disimpan sebagai snapshot; barang rusak masuk karantina. Toko sendiri memakai transfer internal bernilai komersial nol.

Ikuti [panduan operasional](docs/supply-workflow.md) dan [panduan pesanan Flutter](docs/native-ordering.md) serta [pengajuan baru](docs/native-order-entry.md) untuk persiapan SKU pusat/lokal, stock intake dan batas penanganan selisih. Flutter kini mendukung persetujuan/penolakan/pembatalan berizin dan penerimaan kiriman aktual. Pengajuan baru kini juga melalui Flutter dengan pratinjau server; perubahan sebelum persetujuan kini juga tersedia bagi pimpinan berwenang, dan pemilihan sumber/dispatch kini tersedia melalui modul Gudang Flutter; lihat [panduan gudang native](docs/native-warehouse.md). Pengolahan stok dan opname/review kini juga tersedia pada Flutter bagi petugas berwenang. Penerimaan awal gudang pusat kini tersedia melalui **Stok → Penerimaan awal gudang**, dengan [review konversi server](docs/native-warehouse-intake.md).

Buka **Pengolahan, susut dan stok opname** pada toko untuk mencatat buka kemasan, kukus dan proposal hitung fisik. Snapshot lama tidak dapat menimpa pergerakan baru; approval opname memerlukan peninjau berwenang yang berbeda dari pengusul. Pada Flutter, petugas berwenang membuka tab **Stok → Pengolahan dan opname** untuk buka/kukus, pengajuan dan review yang sama. Ikuti [panduan kontrol stok](docs/stock-control.md).

Riwayat pergerakan kini dapat dibaca pada [web](docs/web-stock-ledger.md) serta Flutter **Stok → Riwayat pergerakan stok**, termasuk filter periode WIB, sumber/bucket dan batch historis. Lihat [panduan ledger native](docs/native-stock-ledger.md).

Penelusuran [kedaluwarsa batch](docs/batch-expiry.md) kini tersedia di web dan [Flutter](docs/native-batch-expiry.md), dengan batas tanggal pilihan pengguna dan filter batch bersaldo termasuk karantina. Pada Flutter buka **Stok → Telusuri kedaluwarsa batch**. Recall dan blok jual/masa simpan/FEFO mengikuti [SOP eksplisit organisasi](docs/reliability-and-offline.md), terpisah dari filter tanggal ini.

Web juga menyediakan [CSV riwayat pergerakan stok](docs/ledger-export.md) berdasarkan filter aktif, maksimal10.000 catatan/8MiB. Perubahan dalam biji tetap terpisah dari saldo fisik.

[Revisi SKU dan sisa pesanan](docs/order-revisions.md) kini tersedia pada API/web/Flutter, termasuk setelah pengiriman parsial: penghapusan/substitusi hanya untuk bagian belum dikirim, penambahan memakai quote baru dan persetujuan pihak lawan.

[Katalog lanjutan dan kontrak](docs/catalog-and-contracts.md) menyediakan gambar privat, harga bermasa berlaku, resep paket dengan debit komponen pada POS web/Flutter serta kontrak/PDF/consent di API/web.

## Stok minimum

Pengelola dapat mengatur ambang biji per SKU/toko melalui web dan Flutter; lihat [panduan pengaturan native](docs/native-stock-minimum.md). Web dan Flutter menampilkan peringatan saat stok utuh + terbuka + siap berada di bawah ambang; karantina dan susut dikecualikan. Tidak ada pesanan otomatis atau push notification. Ikuti [panduan stok minimum](docs/stock-minimums.md).

## POS tunai dan kas shift

Buka **POS tunai** dari toko di web atau modul Penjualan Flutter. Buka shift, pilih produk/satuan/batch, tinjau total server, lalu konfirmasi uang tunai. Struk berasal dari transaksi tersimpan; membuka/cetak ulang tidak memotong stok lagi. Draft tersimpan di server dan dapat dipulihkan setelah login ulang.

Pengelola berwenang mencatat kas masuk/keluar melalui web atau modul **Kas shift** Flutter; lihat [panduan kas native](docs/native-shift-cash.md). Tutup shift memerlukan hitungan fisik; selisih wajib diberi alasan dan draft aktif harus diselesaikan/dibatalkan. Web menyediakan [pencarian riwayat struk](docs/web-receipt-history.md) dengan filter nomor/periode WIB serta ringkasan penjualan per tanggal WIB. Flutter kini menyediakan [riwayat struk berhalaman](docs/native-receipt-history.md) dengan filter periode WIB dan nomor struk. Lihat [panduan POS dan shift](docs/cash-pos.md) untuk batas satuan, retry dan hak akses.

## Tagihan, non-tunai, voucher dan refund

[DL-12–DL-17](docs/product/delivery-status.md) kini diimplementasikan dalam cakupan development. [Tagihan dan penyesuaian POS](docs/finance-and-pos-adjustments.md) menyediakan invoice/piutang, ketentuan pajak eksplisit, pembayaran parsial terverifikasi, nota kredit pusat→mitra, konsinyasi dari laporan disetujui, voucher retail didanai penerbit serta refund historis yang ditinjau. POS web/Flutter mendukung QRIS/transfer dengan pemeriksaan bukti manual; tidak ada integrasi gateway. Refund memakai metode asal dan stok kembali hanya ke karantina. Administrasi invoice/kontrak/voucher/aset/laporan komersial kini tersedia di web dan [Flutter native](docs/native-administration-and-bluetooth.md); eksekusi retur, checkout/voucher dan refund juga tersedia pada Flutter mobile/tablet.

## Laporan penjualan tunai

Web dan Flutter menyediakan filter periode/toko, perbandingan toko dan rincian produk dengan snapshot historis. Web dapat mengekspor CSV hingga 10.000 transaksi; akses mengikuti membership organisasi dan assignment toko. Laporan ini khusus POS tunai tersimpan. Ikuti [panduan laporan](docs/cash-reporting.md).

## Jejak audit

Pengelola organisasi dapat menelusuri perubahan melalui [halaman audit web](docs/web-audit.md) dan [modul Audit Flutter](docs/native-audit.md), dengan filter periode WIB, aksi, pelaku dan objek serta snapshot sebelum/sesudah. Akses mengikuti API; audit organisasi tidak tersedia bagi kasir/kepala toko/gudang. Web kini menyediakan [CSV audit berfilter](docs/audit-export.md), maksimal10.000 catatan/8MiB dengan scope yang sama dan perlindungan formula spreadsheet.

## Flutter pada perangkat

Untuk emulator Android, default debug API adalah `http://10.0.2.2:8000/api/v1/`. Mesin developer harus dapat menjangkau backend; localhost cloud tidak otomatis tersedia pada perangkat Anda. Untuk perangkat fisik/iOS gunakan alamat API yang dapat dijangkau dengan TLS dan SDK lokal:

```bash
cd apps/pos
flutter pub get --enforce-lockfile
flutter run --dart-define=API_BASE_URL=https://api.example.com/api/v1/
```

Alamat API wajib diakhiri `/`; build release menolak HTTP. Sesi dan antrean offline tunai Flutter disimpan AES-256-GCM, kunci pada OS Keychain/Keystore, terikat akun/API dan expiry. Restore memeriksa server; gangguan jaringan hanya membuka izin offline tersimpan. [Panduan keamanan/offline](docs/reliability-and-offline.md) menjelaskan review konflik dan pemulihan data. iOS memerlukan macOS/Xcode/signing; build perangkat dan printer belum diuji. Flutter web membutuhkan kebijakan CORS tersendiri bila digunakan lintas origin; target utama tahap ini adalah mobile/tablet native.

## Validasi

```bash
scripts/check
python3 scripts/smoke-identity.py
scripts/composer api validate --strict
scripts/composer web validate --strict
# Hentikan layanan development setelah selesai:
docker compose down
```

`smoke-identity.py` membuat container/API/web dan database sementara terpisah, menjalankan alur HTTP nyata, lalu membersihkan container dan jaringan buatannya. Akun acak untuk pengujian tidak ditambahkan ke database development. CI menjalankan tes ini; CI remote belum dikonfirmasi.

## Dynamic module dan atomic design

Laravel: provider modul didaftarkan eksplisit pada `config/modules.php` masing-masing aplikasi. Catalog backend membutuhkan Identity. Mematikan modul tidak menghapus data. Flag bukan authorization. Flutter: modul bertipe didaftarkan di composition root `lib/main.dart`; contoh `--dart-define=FEATURE_INVENTORY=false` mematikan shell stok. Kode modul dibundel saat build, bukan executable plugin yang diunduh saat runtime.

Blade memakai `resources/views/components/{atoms,molecules,organisms,templates}`; Flutter memakai `lib/design_system`. Ubah `packages/design-tokens/tokens.json`, kemudian jalankan `python3 scripts/generate-tokens.py`. Logika akses berada di API, bukan komponen UI.

Progres delivery dilacak dengan [ID dan status tetap](docs/product/delivery-status.md); kesiapan produksi dipisahkan dari fitur yang sudah dibangun.

## Batas implementasi dan pekerjaan berikutnya

Katalog lokal tetap milik organisasi; katalog B2B pusat kini tersedia untuk pemohon berwenang. SKU tujuan dipilih eksplisit per baris order dengan isi kemasan dan kondisi yang sama. Harga/konversi order sudah mempunyai snapshot. Gambar, resep paket POS, harga efektif/khusus mitra dan kontrak/PDF/consent tersedia; PostgreSQL belum diverifikasi. Akun dapat dinonaktifkan dan [direaktivasi melalui API/web](docs/account-reactivation.md); reset sandi SMTP dan MFA/sesi tersedia; penggabungan identitas lintas organisasi tetap di luar cakupan.

Ordering/approval serta ledger intake/dispatch/receipt sudah tersedia. Revisi SKU/jumlah bagian belum dikirim tersedia dengan persetujuan ulang; retur karantina/kredit, refund, voucher, invoice manual, aset, pelaporan komersial dan offline tunai kini tersedia. QRIS/transfer memerlukan verifikasi manual; gateway otomatis tetap dikesampingkan; integrasi ESC/POS LAN dan cetak browser kini tersedia, perangkat fisik belum diuji. Jangan memakai sistem ini untuk mencatat penjualan nyata sebelum alur stok, keuangan dan UAT selesai.

Konsinyasi memerlukan kontrak/laporan disetujui; stok negatif ditolak. Offline hanya tunai dengan review konflik. SOP masa simpan/FEFO diaktifkan pimpinan organisasi dengan referensi dan menit eksplisit, tanpa umur simpan bawaan. QRIS/transfer masih diverifikasi manual, akses data mitra tetap scoped. Pembukaan/pengukusan memakai batch beku yang sama; resep paket memakai alokasi komponen eksplisit. Susut proses dicatat langsung bersama hasil fisik; susut terpisah menunggu review. Rollback migrasi kontrol stok ditolak setelah digunakan agar data tidak hilang.

SQLite dipilih untuk development irisan ini; migrasi dan uji PostgreSQL serta concurrency wajib sebelum deployment pilot untuk stok/keuangan. Deployment production memerlukan HTTPS, cookie Secure, konfigurasi APP_DEBUG=false, backup/restore dan secrets yang dikelola dengan benar.

## Jaringan dan dokumen

Setup membutuhkan Docker registry, Packagist, codeload.github.com dan pub.dev. Wrapper container memakai trust bundle host melalui mount read-only, tanpa mematikan TLS. Composer lock menggunakan codeload resmi dengan commit sama karena api.github.com dibatasi. Unduhan artifact Flutter tambahan dari storage.googleapis.com masih memerlukan penerapan allowlist pada pengaturan lingkungan.

- [Arsitektur](docs/architecture/decisions.md)
- [Pola pengembangan modul](docs/architecture/module-development.md)
- [Kebutuhan dan keputusan terbuka](docs/product/requirements.md)
- [Roadmap](docs/product/roadmap.md)
- [Bukti pengujian](docs/verification.md)


Delivery tambahan: [printer, konsinyasi/bagi hasil otomatis, sponsor promo dan recall terkoordinasi](docs/printing-commercial-and-recalls.md). Perjanjian dua pihak mengaktifkan formula/budget eksplisit; pembukuan otomatis berupa jurnal penyelesaian terpisah dari invoice manual. Compose kini menjalankan scheduler tiap menit. Printer native mendukung ESC/POS LAN58/80mm dan browser memakai driver sistem; kompatibilitas model fisik belum dikonfirmasi. Recall penerima memerlukan hold/release pimpinan organisasi; penahanan lintas tenant otomatis menunggu keputusan pengguna. Push/gateway tetap dikesampingkan.

Printer struk Flutter kini mengutamakan **Bluetooth Classic/SPP Android** dengan pemilihan perangkat terpasang, izin koneksi, kertas58/80mm dan konfirmasi fisik; LAN tetap kompatibel. Lihat [panduan administrasi native dan Bluetooth](docs/native-administration-and-bluetooth.md) untuk cakupan, retry dan batas pengujian perangkat. Blok recall otomatis lintas organisasi dan pembayaran otomatis ke rekening dilewati sesuai arahan pengguna.

[Operator ERP dan buku besar manual](docs/accounting-and-operator-permissions.md) kini tersedia di web/Flutter: grant produksi per pengguna/gudang, QC tetap owner/admin, akun/periode/jurnal berimbang dengan review pengelola lain, pembalikan terhubung dan neraca saldo dan ringkasan akun dari jurnal posted. Tidak otomatis membukukan ulang transaksi ERP/POS atau menetapkan akun/pajak perusahaan.
