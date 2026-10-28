# Testyco POS

Flutter mobile/tablet: login, toko, katalog, stok per keadaan, pesanan dan rincian pengiriman melalui API. Modul Sales menyediakan POS tunai online, draft server/recovery, struk digital dan buka/tutup shift. Kas masuk/keluar pengelola dan proses/review stok memakai web. Registry fitur berada di lib/main.dart; atomic design di lib/design_system.

Lihat README root dan docs/cash-pos.md. Token hanya di memori; login ulang lalu pulihkan draft/struk dari server setelah aplikasi ditutup. Belum ada offline, QRIS, refund, printer fisik atau build perangkat terverifikasi.

Ambang stok minimum per SKU/toko dan peringatan saat halaman dimuat tersedia; pengaturan melalui web, pembacaan web/Flutter. Lihat docs/stock-minimums.md di root. Belum ada push/email atau pesanan otomatis.
