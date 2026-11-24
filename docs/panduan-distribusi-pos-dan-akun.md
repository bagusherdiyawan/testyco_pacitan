# Distribusi aplikasi POS dan akun pengguna

## APK

- File: `Testyco-POS-1.0.0.apk` (dibuat dengan `flutter build apk --release`).
- Alamat API tertanam: `https://api-mitra.testyco.id/api/v1/`. Untuk alamat lain, bangun ulang dengan
  `--dart-define=API_BASE_URL=https://alamat-api-anda/api/v1/` (wajib HTTPS dan diakhiri `/`).
- Nama aplikasi "Testyco POS", id paket `id.testyco.testyco_pos`.
- Ditandatangani dengan keystore rilis. Setiap pembaruan HARUS memakai keystore yang sama, kalau tidak
  Android menolak memperbarui aplikasi yang sudah terpasang.
  - Keystore dan kata sandinya ada di `~/testyco-keys/` (`testyco-release.jks`, `key.properties`).
  - Salin ke tempat aman (pengelola kata sandi atau penyimpanan terenkripsi). Jangan dikirim ke pengguna
    dan jangan di-commit; `android/key.properties` dan `*.jks` sudah diabaikan git.
- Pengguna memasang lewat file APK: izinkan "pasang dari sumber ini" untuk aplikasi yang dipakai membuka file.

## Akun pengguna

Akun dibuat oleh pemilik pusat; tidak ada kata sandi bawaan.

1. Pemilik pusat dibuat sekali dengan `php artisan identity:bootstrap` (lihat `docs/deploy-shared-hosting.md`).
2. Masuk di `https://management-mitra.testyco.id` sebagai pemilik, buka organisasi (pusat atau mitra), lalu
   bagian **Tambah pengguna**:
   - Nama, email, kata sandi awal (12+ karakter, huruf besar/kecil dan angka).
   - Peran: `cashier` (kasir) adalah bawaan; ada juga pengelola toko, pimpinan mitra, gudang, admin.
   - **Penugasan toko**: kasir dan pengelola toko harus ditugaskan ke toko/booth agar bisa berjualan.
3. Berikan email dan kata sandi awal kepada pengguna lewat saluran yang aman, lalu minta mereka mengganti
   kata sandi (menu Kata sandi di web, atau di aplikasi).
4. Kasir masuk di aplikasi POS dengan email dan kata sandi tersebut.

Urutan awal yang disarankan: buat organisasi mitra → buat booth (toko) → tambah kasir dan tugaskan ke booth →
buka shift di aplikasi.
