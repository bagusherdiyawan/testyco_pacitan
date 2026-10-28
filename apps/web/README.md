# Testyco web

Laravel Blade frontend terpisah dari API. Token pengguna berada pada session server; setiap mutasi memakai CSRF dan authorization API. Modul Operations menyediakan pesanan, pengiriman, penerimaan serta kontrol stok. Modul Pos menyediakan shift, keranjang, review/pembayaran tunai, struk digital/cetak browser, kas masuk/keluar, riwayat dan ringkasan penjualan. Lihat README root, docs/supply-workflow.md, docs/stock-control.md dan docs/cash-pos.md.

Ambang stok minimum per SKU/toko dan peringatan saat halaman dimuat tersedia; pengaturan melalui web, pembacaan web/Flutter. Lihat docs/stock-minimums.md di root. Belum ada push/email atau pesanan otomatis.
