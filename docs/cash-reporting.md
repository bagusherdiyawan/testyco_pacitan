# Laporan penjualan tunai lintas toko

Irisan PRD 11-PART/SELL/KPI/EXP. Modul Reporting menyediakan ringkasan organisasi, perbandingan toko, rincian produk historis dan ekspor CSV transaksi. Ini belum seluruh dashboard PRD 11: penjualan grosir pusat, piutang, margin, order terbuka, perjanjian akses lintas mitra dan definisi mitra aktif belum menjadi metrik laporan ini.

## Akses dan penggunaan

Web: pada **Workspace organisasi**, pengguna berwenang membuka **Laporan penjualan tunai**. Pilih tanggal awal/akhir kalender WIB, termasuk tanggal akhir, serta semua toko berizin atau satu toko. Default hari ini WIB. Maksimal 31 hari. Ini tidak menetapkan jam pergantian hari bisnis atau menutup shift.

Flutter: modul **Laporan** tersedia setelah memilih toko, bagi owner/admin/pimpinan mitra/kepala toko yang memiliki organization_id. Default toko yang dipilih dan hari ini WIB; pemilih periode dan dropdown dapat mengubah filter. Produk mempunyai tombol halaman sebelumnya/berikutnya. Refresh/error/retry tidak menampilkan error sebagai nol penjualan. Ekspor saat ini melalui web, bukan penyimpanan file native.

Owner/admin/pimpinan mitra hanya mencakup organisasi membership aktifnya. Kepala toko hanya toko yang ditugaskan; opsi semua toko tetap terbatas assignment. Filter toko lain ditolak, bukan diperlakukan sebagai nol. Kasir dan gudang tidak mempunyai laporan agregat ini; kasir tetap mempunyai akses struk miliknya melalui POS. Admin pusat untuk onboarding tidak otomatis boleh membaca laporan POS mitra. Toko nonaktif tetap dapat masuk riwayat berizin; organisasi nonaktif mengikuti batas akses Identity dan ditolak.

## Definisi angka

Sumber hanya sale status completed, metode cash, currency IDR yang tersimpan pada interval awal inklusif/akhir eksklusif. Draft tidak masuk. Total transaksi dihitung per struk. Kotor = subtotal historis; diskon = discount_amount tercatat; bersih = total_amount historis. Saat ini POS tidak mendukung diskon/refund/non-tunai, sehingga diskon transaksi aktual bernilai nol. Jangan menafsirkan laporan ini sebagai rekonsiliasi refund yang sudah diimplementasikan.

Kas awal, kas masuk/keluar, uang diterima dan kembalian tidak menjadi omzet tambahan. Grosir, transfer internal, invoice/piutang tidak dijumlahkan dengan POS. Total organisasi dihitung dari hasil query agregasi toko yang sama, sehingga jumlah baris toko merekonsiliasi ke total. Toko dalam scope tanpa penjualan tampil nol.

Produk dikelompokkan menurut product_id, SKU/nama/kondisi snapshot penjualan. Perubahan/nonaktifnya master tidak mengubah riwayat. Nama historis berbeda dapat muncul pada beberapa baris untuk product_id yang sama. Biji terjual = quantity × pieces_per_unit historis, sehingga porsi/kemasan tidak dijumlahkan seolah semuanya biji. Nilai produk adalah jumlah line_total kotor, belum alokasi diskon/refund. Satu struk bisa mempunyai beberapa produk; hitungan struk per produk tidak boleh dijumlahkan menjadi jumlah struk organisasi. Filter produk/status lain belum tersedia; rincian produk mengikuti toko/periode yang dipilih.

Kartu toko memakai nama/status toko saat laporan dibaca; CSV menyimpan nama toko/kasir saat transaksi terjadi. Label historis struk tidak ditulis ulang dari master.

## Ekspor dan kesegaran

Web mengunduh CSV UTF-8 dengan BOM, satu baris per struk: nomor, store_id, nama toko, sold_at UTC bertimezone, nama kasir historis, currency, kotor, diskon, bersih, tunai diterima dan kembalian. Rupiah adalah integer. Nilai teks yang menyerupai formula spreadsheet diberi apostrof agar dibaca sebagai teks; aplikasi spreadsheet dapat menampilkan apostrof tersebut. CSV memakai quoting standar, tanpa mengeksekusi isi nama/catatan.

Batas ekspor 10.000 transaksi. Hasil berlebih ditolak dengan pesan mempersempit toko/periode, tanpa pemotongan diam-diam. Batas ini kontrol resource awal, bukan target kapasitas production. Backend mengambil maksimal 10.001 record sebelum menyiapkan output, sehingga pemeriksaan batas tidak berlomba dengan query hitung terpisah. File tidak disimpan di server; API dan BFF mengirim attachment private/no-store/nosniff dan BFF tidak meneruskan cookie/kredensial upstream. Persiapan ekspor diaudit dengan aktor, scope, periode dan jumlah baris; ini bukan bukti download selesai.

Laporan/produk/ekspor membaca data saat masing-masing request. Waktu observasi ditampilkan; total ringkasan dan rincian produk yang dimuat terpisah bukan snapshot yang dibekukan bersama. Refresh untuk transaksi baru. Hanya data tersimpan yang dihitung; tidak ada klaim antrean offline telah kosong atau semua perangkat tersinkron. Tidak ada worker, email, push atau pesan ke pihak lain pada fitur ini.

## Modul dan API

Reporting backend bergantung pada Identity dan Sales, dan hanya membaca melalui CashSalesReadModel milik Sales. Tidak ada penulisan saldo/ledger/shift dari laporan; hanya event audit persiapan ekspor. Web memakai Backend HTTP, tanpa database domain. Native memakai repository/DTO dan organism CashSalesMetrics. Registry provider mengaktifkan modul Laravel; `FEATURE_REPORTING=false` menyembunyikan modul Flutter saat build. Flag bukan authorization.

API: `GET /api/v1/organizations/{organization}/reports/cash-sales`, `/products`, `/export`. Semua memakai from/to ISO-8601 bertimezone dan store_id opsional; products memakai page (50 baris). Tanpa store_id berarti seluruh toko yang berwenang pada organisasi itu, bukan seluruh database. Tidak ada endpoint publik untuk membaca angka bisnis. Tidak memerlukan migrasi/dependensi baru.

SQLite development dan widget 390px/1200px telah diuji, termasuk text scale 1,5 pada mobile. PostgreSQL/concurrency, load produksi, perangkat Android/iOS, backup/restore dan UAT bisnis belum diverifikasi. Belum ada ekspor async di atas batas, export native, refund, laporan non-tunai/B2B, margin atau akuntansi penuh.


Pembaruan4 November2026 (menggantikan keterangan backlog keuangan/retur/non-tunai pada irisan lama): [retur, tagihan dan penyesuaian POS](finance-and-pos-adjustments.md) kini diimplementasikan untuk DL-13/DL-15/DL-16 dengan input eksplisit, persetujuan dan verifikasi manual. D-01/D-03/D-08 belum menjadi kebijakan global; gateway, bagi hasil otomatis dan pendanaan promo lintas organisasi belum aktif. Laporan tunai tetap struk tunai sebelum pengurangan refund; histori refund/shift terpisah.
