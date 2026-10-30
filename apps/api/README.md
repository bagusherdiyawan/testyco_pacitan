# Testyco API

Laravel JSON API dengan modul Identity, Catalog, Ordering, Inventory, Fulfillment, Shifts dan Sales. Registry provider pada config/modules.php; database development SQLite. Kontrak OpenAPI 0.14.0 mencakup POS tunai online dan shift kasir, selain supply/stock-control. Lihat README root dan docs/cash-pos.md. Refund, QRIS, billing dan offline belum tersedia.

Ambang stok minimum per SKU/toko dan peringatan saat halaman dimuat tersedia; pengaturan dan pembacaan melalui web/Flutter, dengan GET detail ID SKU untuk editor native (docs/native-stock-minimum.md). Lihat docs/stock-minimums.md di root. Belum ada push/email atau pesanan otomatis.

Inventory menyediakan control-context berizin untuk UI native, tanpa dependensi Ordering; aturan mutasi stok tetap sama.

Reporting menyediakan laporan POS tunai per organisasi/toko, produk historis dan filter periode. Ekspor CSV hanya web, maksimal 10.000 transaksi; semua output memakai scope server yang sama. Lihat docs/cash-reporting.md di root.

Keputusan pesanan berizin dan penerimaan aktual mobile/tablet tersedia melalui API versi/idempotensi yang sama; lihat docs/native-ordering.md. Pengajuan baru Flutter kini memakai pratinjau server/review_hash dan UUID; perubahan sebelum persetujuan kini tersedia melalui native, serta pemilihan sumber/dispatch melalui modul Gudang native (docs/native-warehouse.md).

Lihat docs/native-order-entry.md untuk pengajuan baru, kondisi hasil belum diketahui, dan reset navigasi saat sesi berakhir. Tidak ada antrean tahan restart.

Lihat docs/native-order-amend.md untuk preview/PUT sebelum approval, binding versi/ID, serta reset lokal eksplisit saat versi berubah.
