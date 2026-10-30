# Testyco POS

Flutter mobile/tablet: login, toko, katalog, stok per keadaan, pesanan dan rincian pengiriman melalui API. Modul Sales menyediakan POS tunai online, draft server/recovery, struk digital dan buka/tutup shift. Pengolahan stok, pengajuan opname/susut dan review tersedia bagi petugas berwenang pada Flutter. Kas masuk/keluar pengelola kini melalui web dan modul Kas shift Flutter; lihat docs/native-shift-cash.md. Registry fitur berada di lib/main.dart; atomic design di lib/design_system.

Lihat README root, docs/cash-pos.md dan docs/stock-control.md. Token hanya di memori; login ulang lalu pulihkan draft/struk dari server setelah aplikasi ditutup. Belum ada offline, QRIS, refund, printer fisik atau build perangkat terverifikasi.

Ambang stok minimum per SKU/toko dan peringatan saat halaman dimuat tersedia; pengaturan dan pembacaan melalui web/Flutter (docs/native-stock-minimum.md). Lihat docs/stock-minimums.md di root. Belum ada push/email atau pesanan otomatis.

Pending operasi stok hanya disimpan di memori halaman. Jika aplikasi ditutup paksa ketika hasil belum diketahui, rekonsiliasi saldo/riwayat/audit sebelum mencatat ulang; pemulihan otomatis operasi stok lintas restart belum dibuat.

Reporting menyediakan laporan POS tunai per organisasi/toko, produk historis dan filter periode. Ekspor CSV hanya web, maksimal 10.000 transaksi; semua output memakai scope server yang sama. Lihat docs/cash-reporting.md di root.

Keputusan pesanan berizin dan penerimaan aktual mobile/tablet tersedia melalui API versi/idempotensi yang sama; lihat docs/native-ordering.md. Pengajuan baru Flutter kini memakai pratinjau server/review_hash dan UUID; perubahan sebelum persetujuan kini tersedia melalui native, serta pemilihan sumber/dispatch melalui modul Gudang native (docs/native-warehouse.md).

Lihat docs/native-order-entry.md untuk pengajuan baru, kondisi hasil belum diketahui, dan reset navigasi saat sesi berakhir. Tidak ada antrean tahan restart.

Lihat docs/native-order-amend.md untuk preview/PUT sebelum approval, binding versi/ID, serta reset lokal eksplisit saat versi berubah.

Penerimaan awal gudang pusat kini juga melalui Flutter dengan katalog tanpa harga, preview konversi server dan retry immutable; lihat docs/native-warehouse-intake.md. Stok mitra tetap melalui penerimaan pesanan yang disetujui.

Riwayat ledger stok native kini memiliki filter periode WIB, batch, sumber/bucket dan pencarian snapshot historis; read-only dan staff-scoped. Lihat docs/native-stock-ledger.md. Referensi sumber bukan UUID request dan delta bukan saldo terkini.

Riwayat struk: POS → Riwayat struk, periode WIB/nomor dan pagination 50, scope API, pembukaan struk historis tanpa checkout. Lihat [panduan](../../docs/native-receipt-history.md).
