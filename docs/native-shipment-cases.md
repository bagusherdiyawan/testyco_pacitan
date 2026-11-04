# Kasus selisih dan pengganti pada Flutter

DL-12 tersedia pada mobile/tablet dari detail **Pesanan → kiriman yang sudah diterima → Kasus selisih dan penyelesaian**, serta tap kiriman diterima pada detail **Gudang**. Flutter menggunakan API kasus/rencana/pengganti existing; tidak menambah aturan bisnis atau stok di klien.

Penerima berwenang dapat melaporkan selisih aktual, kedua pihak menambah catatan, owner/admin pusat mengusulkan retur/pengganti/kredit, dan pimpinan mitra mengonfirmasi/menolak. Supplier dapat menarik usulan. Semua tombol mengikuti capability server; kasir dan pihak lain tetap ditolak API. Snapshot menampilkan SKU sumber/lokal, batch, isi, kirim/baik/rusak/kurang; sisa order belum dikirim bukan kekurangan kiriman.

Rencana `replacement` terkonfirmasi membuka **Pengiriman dan penerimaan pengganti**: pilih batch sumber dan jumlah parsial, lalu penerima mengisi baik/rusak eksplisit termasuk nol serta alasan selisih. Kuota berasal dari kasus dan berkurang oleh semua jumlah yang dikirim, bukan hanya yang diterima baik. `fulfilled` berarti semua pengganti diterima baik; kerusakan/kehilangan baru tidak menambah kuota. Stok dibukukan oleh API; barang rusak awal tetap karantina. Konfirmasi rencana retur/kredit sendiri belum mengeksekusi barang atau keuangan.

Repository memeriksa parent/kasus/plan/baris/batch dan pagination lokal. Riwayat notes50 dan closed plans50 memakai halaman independen; pending plan ditampilkan terpisah. Riwayat pengganti50 memakai kuota global. Tidak mengikuti URL pagination luar API.

Setiap aksi mempunyai dialog isian lalu review sebelum POST. UUID/path/body/review dibekukan melalui serialisasi JSON; perubahan data widget tidak mengubah request. Timeout/5xx/408/429/ACK salah mempertahankan operasi, menahan refresh/pop/aksi baru dan mengulang persis melalui retry, termasuk sesudah denial berikutnya. ACK memeriksa scope, versi dan isi penting, lalu memuat context terbaru.401 memakai reset rute autentikasi existing. Pending hanya memori halaman, bukan antrean lintas restart; rekonsiliasi riwayat sebelum mengganti operasi setelah aplikasi ditutup.

UI memakai organisme `ShipmentCaseCard` dan komponen Material adaptif. Pengujian controller dan widget390/1200px meliputi review sebelum write, hasil hilang sesudah commit, retry tanpa duplikasi, role/capability, foreign scopes, stale candidate dan kuota. Pengujian bukan bukti Android/iOS/perangkat fisik/UAT. Lihat [kasus API/web](shipment-discrepancies.md), [eksekusi pengganti](replacement-fulfillment.md) dan [verifikasi](verification.md).
