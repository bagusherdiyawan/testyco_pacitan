# Testyco web

Laravel Blade frontend terpisah dari API. Token pengguna berada pada session server; setiap mutasi memakai CSRF dan authorization API. Modul Operations menyediakan pesanan, pengiriman, penerimaan serta kontrol stok. Modul Pos menyediakan shift, keranjang, review/pembayaran tunai, struk digital/cetak browser, kas masuk/keluar, riwayat dan ringkasan penjualan. Lihat README root, docs/supply-workflow.md, docs/stock-control.md dan docs/cash-pos.md.

Ambang stok minimum per SKU/toko dan peringatan saat halaman dimuat tersedia; pengaturan melalui web, pembacaan web/Flutter. Lihat docs/stock-minimums.md di root. Belum ada push/email atau pesanan otomatis.

Reporting menyediakan laporan POS tunai per organisasi/toko, produk historis dan filter periode. Ekspor CSV hanya web, maksimal 10.000 transaksi; semua output memakai scope server yang sama. Lihat docs/cash-reporting.md di root.

Jejak audit: organisasi → Buka jejak audit dengan filter dan snapshot. Kalender WIB, filter exact, pagination lokal dan snapshot teks berizin melalui API; lihat [panduan](../../docs/web-audit.md).

Ledger stok: Pesanan dan stok toko → Riwayat pergerakan stok / Riwayat batch ini. Filter WIB/batch/sumber/bucket, signed pieces dan snapshot historis; lihat [panduan](../../docs/web-stock-ledger.md).

Riwayat struk: POS → Riwayat struk, pencarian nomor dan pasangan tanggal WIB opsional. Tanpa tanggal tetap seluruh riwayat berhalaman, scope dari API; lihat [panduan](../../docs/web-receipt-history.md).

Kedaluwarsa batch: Pesanan dan stok toko → Telusuri kedaluwarsa batch; batas kemasan inklusif opsional dan filter saldo termasuk karantina, tanpa horizon otomatis. Lihat [panduan](../../docs/batch-expiry.md).

Pengurangan jumlah pascapersetujuan sebelum pengiriman pertama kini tersedia melalui API/web, dengan hold, snapshot dan approve/reject/withdraw. Lihat [panduan reapproval](../../docs/order-reapproval.md) untuk scope, peran, retry session dan migrasi history; revisi sesudah kirim tetap backlog, layar reapproval Flutter kini tersedia (docs/native-order-reapproval.md).

Reaktivasi akun nonaktif kini melalui review versi akses, UUID, alasan dan otorisasi server; role/assignment/password dipertahankan, token lama dicabut. Lihat [panduan akun](../../docs/account-reactivation.md).

Ekspor audit organisasi kini melalui bounded JSON API dan CSV web, dengan query scope/allowlist yang sama seperti pembaca, maksimal10.000 catatan/8MiB dan periode31hari. Lihat [panduan](../../docs/audit-export.md).

CSV pergerakan stok web kini melalui bounded JSON ledger API dengan query staff/store/batch yang sama. Angka bertanda tetap delta biji, bukan saldo; lihat [panduan](../../docs/ledger-export.md).

Kasus selisih kiriman kini tersedia melalui API/web: snapshot penerimaan aktual, catatan bersama, usulan rencana owner/admin pusat lalu konfirmasi pimpinan mitra. Operasi kasus/rencana tidak mengeksekusi stok; workflow pengganti terpisah kini mendebit/mengkredit stok melalui ledger, tanpa mengubah order asal/invoice. Retur/kredit belum tersedia. Lihat [panduan kasus](../../docs/shipment-discrepancies.md).

[Pengiriman pengganti](../../docs/replacement-fulfillment.md): rencana dikonfirmasi → batch/dispatch parsial → receipt aktual → status fulfilled.
