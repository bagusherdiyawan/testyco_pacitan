# Deploy ke shared hosting (cPanel)

API dan web dipasang sebagai dua aplikasi Laravel terpisah di dua subdomain, misalnya
`api.domain-anda.com` dan `app.domain-anda.com`. Web memanggil API lewat HTTP dari server ke server.

## Syarat hosting

| Kebutuhan | Keterangan |
| --- | --- |
| PHP **8.4.1 atau lebih baru** | Dependensi Symfony 8 menolak PHP di bawahnya. Pilih di cPanel > Select PHP Version / MultiPHP Manager. |
| Ekstensi PHP | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `bcmath`. Umumnya sudah aktif. |
| MySQL/MariaDB | Satu database untuk API. Web tidak memakai database aplikasi. |
| Akses terminal | cPanel > Terminal atau SSH. Dibutuhkan untuk migrasi dan membuat akun pemilik (prompt interaktif). |
| Cron | Untuk penjadwalan akuntansi (tiap menit; jika host membatasi, 5 menit tidak masalah). |
| SSL | Aktifkan AutoSSL/Let's Encrypt untuk kedua subdomain. Aplikasi POS Android rilis mewajibkan HTTPS. |

Jika hosting tidak menyediakan PHP 8.4 atau terminal, gunakan VPS.

## 1. Siapkan paket (di komputer Anda)

```bash
scripts/package-shared-hosting
```

Hasilnya `dist/testyco-api.zip` dan `dist/testyco-web.zip`. Folder `dist/` tidak masuk git.
Setiap zip berisi file `.env` dengan `APP_KEY` baru. Hapus zip setelah diunggah dan jangan dibagikan.
Template `.env` ada di `deploy/api.env.production` dan `deploy/web.env.production`.

## 2. Buat subdomain dan database

1. cPanel > **Domains/Subdomains**: buat `api.domain-anda.com` dan `app.domain-anda.com`.
   Ubah **Document Root** masing-masing menjadi:
   - `testyco/api/public`
   - `testyco/web/public`
2. cPanel > **MySQL Databases**: buat database, buat user, beri semua hak akses. Catat nama database, user, dan password.
3. Pilih PHP 8.4 untuk kedua subdomain.

## 3. Unggah dan ekstrak

Di File Manager, unggah ke folder `testyco/` (satu level di atas `public_html`):

- `testyco-api.zip` lalu ekstrak ke `testyco/api`
- `testyco-web.zip` lalu ekstrak ke `testyco/web`

Pastikan `testyco/api/public/index.php` dan `testyco/web/public/index.php` ada, dan file `.env` ikut terekstrak
(File Manager > Settings > Show Hidden Files).

## 4. Isi `.env`

`testyco/api/.env`: ganti `DOMAIN-ANDA.com` dan isi `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

`testyco/web/.env`: ganti `DOMAIN-ANDA.com`. `API_BASE_URL` berisi alamat API tanpa `/api/v1`, contoh
`https://api.domain-anda.com`.

`APP_DEBUG` harus tetap `false`.

## 5. Migrasi dan akun pemilik

Di cPanel Terminal (gunakan PHP 8.4, misalnya `/opt/cpanel/ea-php84/root/usr/bin/php` jika `php` bawaan lebih lama):

```bash
cd ~/testyco/api
php artisan migrate --force
php artisan identity:bootstrap
```

`identity:bootstrap` meminta nama pusat, email, dan kata sandi pemilik lewat prompt. Tidak ada kata sandi bawaan.
Perintah ini menolak berjalan dua kali jika pusat sudah ada.

Lalu cache konfigurasi:

```bash
php artisan config:cache && php artisan route:cache
cd ~/testyco/web && php artisan config:cache && php artisan route:cache
```

## 6. Cron penjadwal

cPanel > **Cron Jobs**, tiap menit:

```bash
* * * * * /opt/cpanel/ea-php84/root/usr/bin/php /home/CPUSER/testyco/api/artisan schedule:run >> /dev/null 2>&1
```

Ganti `CPUSER` dengan nama akun cPanel dan sesuaikan path PHP 8.4 dari host Anda.

## 7. Periksa

- `https://api.domain-anda.com/up` harus menjawab 200.
- `https://app.domain-anda.com` menampilkan halaman depan; masuk dengan akun dari langkah 5.
- Setelah masuk, dashboard dan daftar organisasi harus tampil. Jika web menampilkan "API belum tersedia",
  periksa `API_BASE_URL` dan sertifikat SSL subdomain API.

## Aplikasi POS Android

Bangun APK rilis dengan alamat API produksi (HTTPS wajib, diakhiri `/`):

```bash
cd apps/pos
flutter build apk --release --dart-define=API_BASE_URL=https://api.domain-anda.com/api/v1/
```

## Pembaruan berikutnya

1. Jalankan `scripts/package-shared-hosting` lagi.
2. Unggah dan ekstrak zip baru **tanpa menimpa `.env`** (hapus `.env` dari zip yang akan diekstrak, atau pulihkan dari cadangan).
3. `php artisan migrate --force`, lalu `php artisan config:cache && php artisan route:cache` dan kosongkan
   `storage/framework/cache` bila perlu.

## Batasan yang perlu diketahui

- Aplikasi diuji dengan SQLite. Jalur MySQL (termasuk pengelompokan harian pada grafik tren penjualan) belum
  diuji di MySQL sungguhan; periksa dashboard dan laporan setelah deploy.
- Tidak ada antrean worker: `QUEUE_CONNECTION=sync`. Email keluar dimatikan (`RELIABILITY_SMTP_ENABLED=false`)
  sampai SMTP dikonfigurasi dan diverifikasi.
- Jika folder `storage` atau `bootstrap/cache` tidak bisa ditulis, ubah izin menjadi 775.
