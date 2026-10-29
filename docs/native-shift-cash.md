# Kas masuk/keluar di Flutter

Irisan PRD 07-CASH dan 01-ACL/AUD: modul **Kas shift** menyediakan daftar shift berhalaman, detail saldo dan riwayat kas manual untuk mobile/tablet. Buka/tutup shift dan penjualan tetap melalui POS. Tidak ada kebijakan keuangan baru, migrasi atau dependensi tambahan.

## Alur dan izin

Pilih toko lalu buka **Kas shift**. Owner/admin/pimpinan mitra serta kepala toko berizin dapat membuka modul; kepala toko dibatasi assignment. Admin pusat tanpa membership mitra tidak mendapat akses operasional mitra. Kasir/gudang tidak mendapat modul pengelolaan kas ini. Penyembunyian modul bukan authorization: API memeriksa keanggotaan, assignment, role, status toko/shift dan versi setiap mutasi.

Pilih shift berdasarkan nama kasir, terminal dan waktu dibuka. Detail menampilkan kas awal + penjualan tunai + kas masuk − kas keluar = kas diharapkan. Shift tertutup menampilkan hitungan fisik, selisih serta catatan penutupan. Angka kas diharapkan bukan bukti hitungan fisik. Kas manual tidak menjadi omzet atau perubahan stok.

GET detail `/api/v1/shifts/{shift}` mengirim `can_record_movement`, hanya true bagi pengelola dengan toko aktif dan shift terbuka. Flutter tidak menebak izin dari `can_manage`, role lokal atau status saja. Respons lama tanpa flag tetap dapat dibaca tanpa tombol mutasi. Riwayat toko nonaktif masih mengikuti izin baca, tanpa pencatatan baru.

1. Pilih kas masuk/keluar, isi rupiah bulat positif, kategori dan alasan nyata. Tidak ada nominal, kategori atau alasan bawaan. Kas keluar tidak boleh melampaui kas diharapkan. Batas teknis Rp1 triliun mengikuti API, bukan limit komersial baru.
2. Tinjau jenis, jumlah, kategori, alasan dan versi shift pada dialog. Pembatalan dialog tidak membuat mutasi. Konfirmasi setelah uang fisik benar-benar bergerak.
3. POST `/api/v1/shifts/{shift}/cash-movements` memakai versi yang ditinjau dan UUID. Penjualan atau mutasi kas dari perangkat lain dapat menyebabkan 409; aplikasi meminta muat ulang dan peninjauan baru, tanpa mengganti versi otomatis.
4. Setelah konfirmasi berhasil, aplikasi memuat detail terkini. Hasil replay mungkin memuat saldo/versi lama, sehingga tidak dipakai menggantikan detail terkini. Catatan kas mempunyai ID, aktor, kategori, alasan dan waktu; audit API tetap append-only.

Refresh secara eksplisit mengosongkan isian nominal/kategori/alasan agar draft tidak dipakai dengan snapshot saldo baru tanpa ditinjau. Dialog menampilkan versi yang benar-benar dikirim. Bila detail gagal dimuat, angka lama tetap dapat dilihat tetapi mutasi dinonaktifkan sampai refresh berhasil.

## Respons tidak pasti dan batas pemulihan

Jika timeout, koneksi putus, respons tidak valid atau server gagal, controller mempertahankan UUID, path dan body JSON immutable. Isian baru, refresh dan pop halaman ditahan; gunakan **Ulangi pencatatan yang sama**. Respons penolakan setelah percobaan yang sebelumnya tidak pasti tidak membuktikan bahwa percobaan pertama gagal commit, sehingga pending tetap dipertahankan. Replay identik diperiksa server sebelum versi/status domain, termasuk setelah shift ditutup, tetapi tetap memerlukan izin operasional saat ini.

Bila server sudah mengakui pencatatan lalu GET terbaru gagal, UI menyatakan kas tersimpan, tidak mengirim pencatatan pengganti, dan meminta refresh. Konflik definitif pada percobaan pertama menonaktifkan isian sampai detail ditinjau ulang.

Pending hanya di memori halaman. Restart, perangkat mati atau pergantian sesi dapat menghilangkannya. Login kembali dan periksa ID/jenis/nominal/kategori/alasan pada riwayat serta audit sebelum membuat pengganti. Belum ada antrean offline, secure storage, pembatalan catatan kas, refund, penutupan paksa atau shift bersama.

## Struktur dan verifikasi

Module registry Flutter memakai `FEATURE_SHIFTS` (default true), terpisah dari `FEATURE_SALES`; backend Shifts tetap menjadi dependensi POS. DTO, repository, controller, daftar dan detail dipisahkan. Organism `ShiftCashSummary` dan molecule `CashMovementReview` hanya merender nilai. Domain saldo/audit/idempotensi tetap di API. Navigasi mobile memakai menu **Lainnya** bila modul melebihi lima tujuan agar seluruh modul tetap dapat dijangkau; tablet memakai rail.

Tes mencakup mobile 390px text scale 1,5 dan tablet 1200px, pagination, review, konflik versi, replay setelah penutupan, denial setelah respons hilang, kegagalan refresh setelah ack, metadata lama, toko salah serta larangan lintas tenant/assignment. SQLite dan widget bukan bukti concurrency PostgreSQL, build Android/iOS, UAT atau pergerakan uang fisik di toko.
