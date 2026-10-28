# Bukti implementasi dan pengujian

Dijalankan pada lingkungan onboarding, 28 Oktober 2026. Hasil lokal, bukan UAT bisnis atau CI remote.

| Pemeriksaan | Hasil |
| --- | --- |
| API PHPUnit | 43 tes lulus, 294 assertions |
| Web PHPUnit | 19 tes lulus, 71 assertions |
| Flutter | 15 tes lulus, termasuk stok/pesanan mobile 390px dan tablet 1024px |
| Flutter analyze | Lulus, tanpa issue |
| Migrasi SQLite supply workflow | Lulus, tanpa reset data |
| Compose API/web dan smoke metadata | Healthy dan lulus |
| Smoke HTTP nyata, database/container terpisah | Lulus: CSRF/login, onboarding/katalog, pengajuan/approval, dispatch, penerimaan aktual, replay dan isolasi mitra |
| Composer validate --strict | Kedua aplikasi lulus |
| JS formulir order | Syntax check Node lulus; browser end-to-end penambahan baris dengan JS belum diuji |
| Kontrak OpenAPI 0.3.0 | 39 operation ID unik, referensi schema dan parameter path konsisten; belum memakai validator OpenAPI eksternal |
| PostgreSQL/concurrency multi-koneksi | Belum diuji; development memakai SQLite |
| Android/iOS build/perangkat/printer | Belum diuji |
| Flutter web release build | Percobaan sebelumnya terblokir proxy 403 saat mengunduh Web SDK dari storage.googleapis.com; tidak diklaim berhasil |

## Bukti alur baru

- Pusat tidak melihat draft mitra; hanya pimpinan berwenang yang menyetujui. Kasir, mitra lain dan gudang tanpa penugasan ditolak.
- Pengajuan dan approval tidak mereservasi/mengubah stok. Harga yang berubah sebelum approval memerlukan amendment dan review; harga/pack snapshot tetap setelah approval.
- Intake/dispatch/receipt dengan UUID operasi yang sama tidak menggandakan stok. Payload yang berubah dengan key lama ditolak 409.
- Pengiriman melebihi stok ditolak. Bila item kedua gagal, debit item pertama, shipment dan claim operasi ikut rollback.
- Pesanan 20 kemasan isi 8, diterima baik 18 dan rusak 2: 144 biji usable, 16 quarantined, status partially_received. Catatan selisih wajib; jumlah kirim tidak dipakai sebagai penerimaan fiktif.
- Dua pengiriman 18 dan 2 yang diterima seluruhnya baik menghasilkan completed dan 160 biji. Kiriman baru dibatasi sisa order; barang hilang/rusak tidak otomatis memberi allowance pengganti.
- Transfer toko sendiri memindahkan stok dengan nilai komersial nol; jumlah ledger awal/debit/kredit direkonsiliasi dalam tes.
- Konversi kemasan order yang disetujui tetap dipakai walau katalog kemudian berubah dari 8 menjadi 10.
- API stok kasir hanya mencakup toko ditugaskan; ledger pengelolaan, order, approval dan receipt kasir ditolak.
- Form web menampilkan alokasi sumber, jumlah aktual terpisah, karantina dan progress kirim. Stock/order Flutter memakai API, memiliki retry dan diuji pada dua ukuran.
- Pagination Flutter tidak meneruskan bearer token ke next_page_url eksternal.

## Identitas dan keamanan yang tetap diverifikasi

Token hashed dan delapan jam expiry, logout revocation, password change dengan password lama, pencabutan sesi pada deaktivasi/perubahan hak, scope organisasi/store, pencegahan role escalation/cross-assignment, audit tanpa password/token, dan filtrasi harga grosir untuk kasir. Admin pusat tanpa membership mitra tidak mendapat data katalog/stock atau audit harga mitra melalui akses onboarding.

Smoke web memakai APP_ENV=local agar CSRF aktif: POST login tanpa token ditolak 419, cookie/token valid berhasil. Kredensial acak hanya berada pada fixture database sementara. Tidak ada akun test yang ditambahkan ke database development.

## Runtime dan persistensi

Compose memakai --no-reload agar override environment tetap tersedia, serta NO_PROXY/no_proxy untuk API internal. Wrapper memakai CA host read-only; TLS/checksum tidak dinonaktifkan. Cache pub dipertahankan dan versi dependensi tetap dikunci.

Draft install_script/start_skill diperbarui untuk migrasi dan alur supply. Penyimpanan draft belum menerbitkan snapshot atau menerapkan perubahan jaringan; pengguna meninjau/simpan lalu Publish pada pengaturan lingkungan. Kebutuhan storage.googleapis.com untuk artifact Flutter sudah tersimpan dari tahap sebelumnya.

## Batas yang tersisa

Belum ada perubahan pasca-approval/reapproval, resolusi retur/kredit/pengganti, pemilihan FEFO otomatis, blocking expiry berdasarkan SOP, bukti foto/suhu terstruktur, buka kemasan/pengukusan, opname, notifikasi minimum, checkout POS/shift, invoice, voucher, laporan, aset atau offline. Flutter belum melakukan aksi operasional pengajuan/approval/dispatch/receipt; gunakan web. Seluruh pilot masih memerlukan keputusan bisnis, PostgreSQL/concurrency, backup/restore dan UAT.
