# Testyco Pacitan

Monorepo Laravel API, Laravel web terpisah dan Flutter mobile/tablet untuk jaringan mitra dan POS dimsum.

**Sudah diimplementasikan:** identitas dan katalog awal, pengajuan/persetujuan pesanan, pengiriman parsial, penerimaan aktual, batch/ledger, pembukaan kemasan, pengukusan hasil aktual serta opname/susut dengan review terpisah. Web dan Flutter kini menyediakan POS tunai online, draft tersimpan di server, struk digital dan buka/tutup shift. Ini implementasi development; UAT, concurrency PostgreSQL dan perangkat nyata belum diverifikasi.

| Direktori | Isi |
| --- | --- |
| `apps/api` | Laravel 12 + Sanctum, modul Identity, Catalog, Ordering, Inventory, Fulfillment, Shifts dan Sales, persistence SQLite untuk development |
| `apps/web` | Laravel 12 + Blade, frontend/BFF melalui HTTP; token di session server, CSRF pada form |
| `apps/pos` | Flutter 3.35.7, login, toko, katalog, stok, pesanan dan POS tunai/shift mobile/tablet |
| `packages/design-tokens` | Token visual bersama; komponen atomic design native tiap framework |
| `contracts/openapi.yaml` | Kontrak API 0.7.0 (JSON valid YAML, OpenAPI 3.1) |
| `docs/product` | Kebutuhan, keputusan terbuka dan roadmap |
| `docs/reference/Markdown` | Dokumen sumber dari lampiran ZIP |

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
6. Web menyediakan pergantian kata sandi dengan verifikasi kata sandi lama; seluruh token dicabut setelah berhasil. Logout mencabut token saat ini.

Admin pusat dapat mengelola metadata onboarding mitra; hak ini tidak otomatis membuka katalog operasional mitra. Audit yang dilihat pusat tanpa keanggotaan mitra terbatas pada perubahan identitas/onboarding, sehingga harga tidak bocor lewat audit.

## Pesanan dan stok

Buka tautan **Pesanan dan stok** pada toko atau **Pesanan pusat** pada navigasi. Alur: pengajuan → persetujuan pimpinan → gudang sumber → alokasi batch/pengiriman → penerimaan aktual. Harga dan konversi disimpan sebagai snapshot; barang rusak masuk karantina. Toko sendiri memakai transfer internal bernilai komersial nol.

Ikuti [panduan operasional](docs/supply-workflow.md) untuk persiapan SKU pusat/lokal, stock intake dan batas penanganan selisih. Flutter membaca status pesanan/pengiriman; aksi supply tetap melalui web. Pengolahan stok dan opname/review kini juga tersedia pada Flutter bagi petugas berwenang.

Buka **Pengolahan, susut dan stok opname** pada toko untuk mencatat buka kemasan, kukus dan proposal hitung fisik. Snapshot lama tidak dapat menimpa pergerakan baru; approval opname memerlukan peninjau berwenang yang berbeda dari pengusul. Pada Flutter, petugas berwenang membuka tab **Stok → Pengolahan dan opname** untuk buka/kukus, pengajuan dan review yang sama. Ikuti [panduan kontrol stok](docs/stock-control.md).

## Stok minimum

Pengelola dapat mengatur ambang biji per SKU/toko melalui web. Web dan Flutter menampilkan peringatan saat stok utuh + terbuka + siap berada di bawah ambang; karantina dan susut dikecualikan. Tidak ada pesanan otomatis atau push notification. Ikuti [panduan stok minimum](docs/stock-minimums.md).

## POS tunai dan kas shift

Buka **POS tunai** dari toko di web atau modul Penjualan Flutter. Buka shift, pilih produk/satuan/batch, tinjau total server, lalu konfirmasi uang tunai. Struk berasal dari transaksi tersimpan; membuka/cetak ulang tidak memotong stok lagi. Draft tersimpan di server dan dapat dipulihkan setelah login ulang.

Pengelola berwenang mencatat kas masuk/keluar melalui web. Tutup shift memerlukan hitungan fisik; selisih wajib diberi alasan dan draft aktif harus diselesaikan/dibatalkan. Web menyediakan riwayat struk serta ringkasan penjualan per tanggal WIB. Lihat [panduan POS dan shift](docs/cash-pos.md) untuk batas satuan, retry dan hak akses.

## Flutter pada perangkat

Untuk emulator Android, default debug API adalah `http://10.0.2.2:8000/api/v1/`. Mesin developer harus dapat menjangkau backend; localhost cloud tidak otomatis tersedia pada perangkat Anda. Untuk perangkat fisik/iOS gunakan alamat API yang dapat dijangkau dengan TLS dan SDK lokal:

```bash
cd apps/pos
flutter pub get --enforce-lockfile
flutter run --dart-define=API_BASE_URL=https://api.example.com/api/v1/
```

Alamat API wajib diakhiri `/`; build release menolak HTTP. Token disimpan di memori, tidak di localStorage/file, sehingga aplikasi meminta login kembali setelah ditutup. Secure storage dan sesi persisten belum dibuat. iOS memerlukan macOS/Xcode/signing; build perangkat dan printer belum diuji. Flutter web membutuhkan kebijakan CORS tersendiri bila digunakan lintas origin; target utama tahap ini adalah mobile/tablet native.

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

## Batas implementasi dan pekerjaan berikutnya

Katalog lokal tetap milik organisasi; katalog B2B pusat kini tersedia untuk pemohon berwenang. SKU tujuan dipilih eksplisit per baris order dengan isi kemasan dan kondisi yang sama. Harga/konversi order sudah mempunyai snapshot. Kontrak harga khusus/bermasa berlaku, gambar, resep/paket, kontrak kemitraan dan PostgreSQL belum dibuat. Akun dinonaktifkan melalui API/web; reaktivasi akun, pemulihan password melalui email, MFA dan siklus pengelolaan anggota lintas organisasi belum tersedia.

Ordering/approval serta ledger intake/dispatch/receipt sudah tersedia. Perubahan setelah approval/reapproval, resolusi retur/kredit/pengganti, refund, integrasi printer, voucher, invoice, pelaporan lanjutan, aset dan offline masih backlog. POS tunai, shift, struk digital dan ringkasan penjualan awal tersedia; QRIS/non-tunai belum tersedia. Jangan memakai sistem ini untuk mencatat penjualan nyata sebelum alur stok, keuangan dan UAT selesai.

Kebijakan konsinyasi, stok minus, offline, QRIS dan cakupan data mitra tetap mengikuti keputusan bisnis yang belum ditutup. Pembukaan/pengukusan memakai batch beku yang sama, bukan resep lintas SKU. Masa simpan siap jual tidak dihitung otomatis. Susut proses dicatat langsung bersama hasil fisik; susut terpisah menunggu review. Rollback migrasi kontrol stok ditolak setelah digunakan agar data tidak hilang.

SQLite dipilih untuk development irisan ini; migrasi dan uji PostgreSQL serta concurrency wajib sebelum deployment pilot untuk stok/keuangan. Deployment production memerlukan HTTPS, cookie Secure, konfigurasi APP_DEBUG=false, backup/restore dan secrets yang dikelola dengan benar.

## Jaringan dan dokumen

Setup membutuhkan Docker registry, Packagist, codeload.github.com dan pub.dev. Wrapper container memakai trust bundle host melalui mount read-only, tanpa mematikan TLS. Composer lock menggunakan codeload resmi dengan commit sama karena api.github.com dibatasi. Unduhan artifact Flutter tambahan dari storage.googleapis.com masih memerlukan penerapan allowlist pada pengaturan lingkungan.

- [Arsitektur](docs/architecture/decisions.md)
- [Pola pengembangan modul](docs/architecture/module-development.md)
- [Kebutuhan dan keputusan terbuka](docs/product/requirements.md)
- [Roadmap](docs/product/roadmap.md)
- [Bukti pengujian](docs/verification.md)
