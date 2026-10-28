# Testyco API

Laravel JSON API dengan modul Identity, Catalog, Ordering, Inventory, Fulfillment, Shifts dan Sales. Registry provider pada config/modules.php; database development SQLite. Kontrak OpenAPI 0.6.0 mencakup POS tunai online dan shift kasir, selain supply/stock-control. Lihat README root dan docs/cash-pos.md. Refund, QRIS, billing dan offline belum tersedia.

Ambang stok minimum per SKU/toko dan peringatan saat halaman dimuat tersedia; pengaturan melalui web, pembacaan web/Flutter. Lihat docs/stock-minimums.md di root. Belum ada push/email atau pesanan otomatis.
