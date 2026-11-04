# POS tunai online dan shift kasir

Irisan PRD 06-CART/UNIT/PAY/SAVE/PRN dan 07-SHF/CASH/END/REP; bukan penerimaan penuh PRD. Web dan Flutter menggunakan API yang sama. `scripts/setup` memasang lockfile dan menjalankan migrasi baru tanpa reset data. Katalog aktif dan stok toko harus tersedia melalui alur supply/pengolahan sebelumnya.

## Akses dan shift

Kasir, kepala toko, pimpinan mitra, owner/admin organisasi dapat berjualan pada toko yang berwenang. Gudang tidak mendapat hak POS. Admin pusat tidak otomatis dapat membaca penjualan mitra. Kasir hanya melihat draft/struk/shift sendiri; pengelola berwenang dapat membaca riwayat toko dan laporan. Pembayaran, pembatalan draft dan tutup shift dilakukan kasir pemilik.

1. Masuk, pilih toko lalu buka **POS tunai** di web atau modul Penjualan Flutter.
2. Isi label perangkat dan uang awal fisik dalam rupiah bulat. Label bukan registrasi atau autentikasi perangkat.
3. Satu kasir dapat memiliki satu shift aktif per toko. Kasir lain boleh membuka shift sendiri. Ini batas teknis awal, bukan kebijakan final laci/perangkat bersama.
4. Pergantian hari tidak menutup shift otomatis. Hilang sesi tidak menghapus shift/draft; masuk kembali dengan akun yang sama.

## Keranjang, stok dan harga

Pilih produk, satuan biji/porsi/kemasan, jumlah dan sumber batch/keadaan secara eksplisit. Harga POS selalu berasal dari harga eceran per biji server: porsi × isi porsi dan kemasan × isi kemasan. Harga grosir B2B tidak dipakai. Tidak ada pajak, promo atau diskon baru yang dihitung pada tahap ini.

Kemasan memakai stok utuh dengan isi kemasan historis yang cocok. Biji/porsi memakai stok terbuka atau siap; stok utuh harus dibuka melalui kontrol stok dahulu. Karantina dan susut tidak dapat dijual. SKU/kondisi sumber harus cocok. UI web/Flutter mendukung satu sumber batch/keadaan per SKU; API mendukung beberapa alokasi per baris. Tidak ada pemilihan FEFO otomatis atau sertifikasi mutu dari angka stok. Masa simpan hasil olahan belum dihitung otomatis.

Tinjau keranjang untuk membuat draft server. Draft belum memotong/mereservasi stok dan belum menerima pembayaran; maksimal 20 draft aktif per shift. API mengabaikan harga/total kiriman client dan menghitung snapshot sendiri. Jika master harga/konversi berubah sebelum bayar, pembayaran ditolak; batalkan draft dan tinjau keranjang baru. Stok diperiksa lagi saat bayar, sehingga draft dapat gagal bila stok sudah dipakai transaksi lain.

## Pembayaran dan pemulihan

Isi uang tunai yang benar-benar diterima lalu konfirmasi. Server mensyaratkan uang cukup dan menghitung kembalian. Penjualan, baris snapshot, alokasi batch, ledger stok, revisi batch, kas shift dan audit tersimpan dalam satu transaksi. Kegagalan salah satu baris membatalkan seluruh perubahan. Rupiah adalah integer; batas teknis setiap input/akumulasi shift Rp1 triliun, bukan limit komersial yang disepakati.

Jika respons hilang, gunakan pemulihan draft/struk, jangan membuat penjualan pengganti sebelum memeriksa status. Flutter mempertahankan UUID dan nominal pembayaran untuk retry saat hasil belum diketahui. Setelah aplikasi ditutup, login ulang lalu pilih draft aktif atau struk terakhir dari server. Draft yang sudah dibayar mengarah pada struk asli; satu draft hanya dapat menghasilkan satu penjualan, termasuk retry dengan UUID baru. Nominal berbeda pada draft yang sudah dibayar ditolak. Ini pemulihan transaksi online, bukan antrean offline.

Struk digital menyimpan nama toko/kasir, produk, satuan/konversi, harga, pembayaran dan waktu transaksi historis. Perubahan master tidak mengubah struk. GET/buka ulang/cetak browser tidak membuat transaksi atau ledger baru. Web menampilkan tombol cetak browser; Flutter menampilkan struk digital. Belum ada driver printer, Bluetooth/USB atau pengiriman struk ke pelanggan.

Draft belum dibayar boleh dibatalkan dengan alasan. Penjualan lunas tidak bisa dibatalkan melalui endpoint ini; refund/void finansial belum dibuat.

## Kas, penutupan dan laporan

Pengelola berwenang membuka halaman shift melalui web atau modul **Kas shift** Flutter untuk mencatat kas masuk/keluar dengan kategori dan alasan. Kas keluar tidak boleh melampaui kas diharapkan; versi lama ditolak. Flutter menyediakan daftar/detail shift, review, riwayat dan retry immutable; lihat [panduan kas native](native-shift-cash.md).

Kas diharapkan = uang awal + penjualan tunai neto + kas masuk − kas keluar. Uang diterima pembeli dikurangi kembalian, sehingga penjualan Rp12.000 dengan uang Rp20.000 menambah kas Rp12.000. Contoh uang awal Rp100.000 + penjualan Rp200.000 − kas keluar Rp20.000 = Rp280.000. Hitung fisik Rp275.000 berarti selisih −Rp5.000 dan wajib diberi catatan.

Selesaikan/batalkan seluruh draft aktif sebelum tutup. Kasir pemilik mengisi hitungan fisik; nilai tidak diisi otomatis sebagai bukti hitung. Versi shift harus sama dengan yang dilihat saat menghitung; jika kas berubah, muat ulang dan hitung kembali. Penutupan menyimpan expected, actual, selisih dan catatan tanpa menulis ulang penjualan.

Flutter kini menyediakan [riwayat struk berhalaman dengan filter periode/nomor](native-receipt-history.md), scope kasir sendiri/pengelola toko, serta pembukaan struk historis terpisah dari checkout. Web menyediakan [riwayat struk berfilter nomor/periode](web-receipt-history.md), tetap all-history tanpa tanggal, serta ringkasan penjualan per tanggal kalender Asia/Jakarta (WIB). Ini belum penetapan jam pergantian hari bisnis toko. API menerima interval eksplisit bertimezone, awal inklusif/akhir eksklusif, maksimal 31 hari. Ringkasan menghitung penjualan selesai, bukan order supply/transfer atau kas masuk/keluar. Pengelola hanya melihat toko yang berwenang.

## Batas dan release gate

Belum ada QRIS/transfer, promo/voucher, refund/void penjualan lunas, printer fisik, perangkat terdaftar, shift bersama/serah-terima, penutupan paksa oleh pengelola, persetujuan selisih kas, ekspor atau offline. Dynamic module melalui registry/flag saat build, bukan plugin executable yang diunduh. Sales membutuhkan Shifts, Catalog, Inventory dan Identity. Flag UI tidak menggantikan authorization API.

Tes development memakai SQLite; concurrency PostgreSQL multi-koneksi, backup/restore, perangkat Android/iOS/printer dan UAT bisnis belum diverifikasi. Jangan memakai alur ini untuk transaksi nyata sebelum gate pilot dan SOP stok/keuangan selesai.

Laporan tunai lintas toko, rincian produk historis dan ekspor CSV kini tersedia melalui modul Reporting; lihat [panduan laporan](cash-reporting.md).


Pembaruan4 November2026 (menggantikan keterangan backlog keuangan/retur/non-tunai pada irisan lama): [retur, tagihan dan penyesuaian POS](finance-and-pos-adjustments.md) kini diimplementasikan untuk DL-13/DL-15/DL-16 dengan input eksplisit, persetujuan dan verifikasi manual. D-01/D-03/D-08 belum menjadi kebijakan global; gateway, bagi hasil otomatis dan pendanaan promo lintas organisasi belum aktif. Laporan tunai tetap struk tunai sebelum pengurangan refund; histori refund/shift terpisah.
