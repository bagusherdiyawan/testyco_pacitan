# Testyco API

Laravel JSON API dengan modul Identity, Catalog, Ordering, Inventory, Fulfillment, Shifts dan Sales. Registry provider pada config/modules.php; database development SQLite. Kontrak OpenAPI 0.16.0 mencakup POS tunai online dan shift kasir, selain supply/stock-control. Lihat README root dan docs/cash-pos.md. Refund, QRIS, billing dan offline belum tersedia.

Ambang stok minimum per SKU/toko dan peringatan saat halaman dimuat tersedia; pengaturan dan pembacaan melalui web/Flutter, dengan GET detail ID SKU untuk editor native (docs/native-stock-minimum.md). Lihat docs/stock-minimums.md di root. Belum ada push/email atau pesanan otomatis.

Inventory menyediakan control-context berizin untuk UI native, tanpa dependensi Ordering; aturan mutasi stok tetap sama.

Reporting menyediakan laporan POS tunai per organisasi/toko, produk historis dan filter periode. Ekspor CSV hanya web, maksimal 10.000 transaksi; semua output memakai scope server yang sama. Lihat docs/cash-reporting.md di root.

Keputusan pesanan berizin dan penerimaan aktual mobile/tablet tersedia melalui API versi/idempotensi yang sama; lihat docs/native-ordering.md. Pengajuan baru Flutter kini memakai pratinjau server/review_hash dan UUID; perubahan sebelum persetujuan kini tersedia melalui native, serta pemilihan sumber/dispatch melalui modul Gudang native (docs/native-warehouse.md).

Lihat docs/native-order-entry.md untuk pengajuan baru, kondisi hasil belum diketahui, dan reset navigasi saat sesi berakhir. Tidak ada antrean tahan restart.

Lihat docs/native-order-amend.md untuk preview/PUT sebelum approval, binding versi/ID, serta reset lokal eksplisit saat versi berubah.

Penerimaan awal gudang pusat kini juga melalui Flutter dengan katalog tanpa harga, preview konversi server dan retry immutable; lihat docs/native-warehouse-intake.md. Stok mitra tetap melalui penerimaan pesanan yang disetujui.

Riwayat ledger stok native kini memiliki filter periode WIB, batch, sumber/bucket dan pencarian snapshot historis; read-only dan staff-scoped. Lihat docs/native-stock-ledger.md. Referensi sumber bukan UUID request dan delta bukan saldo terkini.

Pengurangan jumlah pascapersetujuan sebelum pengiriman pertama kini tersedia melalui API/web, dengan hold, snapshot dan approve/reject/withdraw. Lihat [panduan reapproval](../../docs/order-reapproval.md) untuk scope, peran, retry session dan migrasi history; revisi sesudah kirim tetap backlog, layar reapproval Flutter kini tersedia (docs/native-order-reapproval.md).

Reaktivasi akun nonaktif kini melalui review versi akses, UUID, alasan dan otorisasi server; role/assignment/password dipertahankan, token lama dicabut. Lihat [panduan akun](../../docs/account-reactivation.md).

Ekspor audit organisasi kini melalui bounded JSON API dan CSV web, dengan query scope/allowlist yang sama seperti pembaca, maksimal10.000 catatan/8MiB dan periode31hari. Lihat [panduan](../../docs/audit-export.md).
