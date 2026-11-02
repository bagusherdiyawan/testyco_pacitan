# Pengurangan pesanan setelah persetujuan

Irisan PRD 04-EXC/BRR-01: API dan web menyediakan usulan pengurangan jumlah pesanan yang telah disetujui. Ini belum seluruh resolusi pengecualian order.

## Alur operasional

Dari detail pesanan web buka **Usulan pengurangan setelah persetujuan**. Owner/admin organisasi pemasok dapat mengusulkan jumlah baru pada status approved/processing, sebelum ada satu pun shipment. Isi semua baris tepat sekali; setidaknya satu jumlah harus berkurang dan setiap baris tetap minimal satu kemasan. Alasan wajib. Petugas gudang tidak mendapat hak mengusulkan hanya karena assignment gudang.

Pesanan asli tetap berlaku selama usulan pending: ID baris, SKU pusat/lokal, konversi, harga, jumlah, total, sumber dan status lama dipertahankan. Usulan menyimpan snapshot sebelum/sesudah beserta persetujuan asal dan menaikkan versi induk. Pemrosesan, dispatch dan pembatalan ditahan API, termasuk request dengan versi terbaru. UI gudang/native menerima can_process/can_dispatch=false; batch dispatch disembunyikan.

Pimpinan mitra berwenang menyetujui/menolak usulan wholesale. Transfer internal menggunakan approver owner/admin yang sudah berlaku; tidak memperkenalkan aturan reviewer independen baru. Persetujuan menerapkan jumlah/total dari snapshot server, mempertahankan ID baris dan mencatat approver/waktu baru. Harga master yang berubah tidak menghitung ulang quote yang telah disetujui. Nilai transfer internal tetap nol. Penolakan penerima atau penarikan owner/admin pemasok mempertahankan jumlah/total asli dan melepas hold. Setiap keputusan menaikkan versi sekali, membutuhkan versi pending yang sama, dan menyimpan pelaku/catatan/waktu. Alasan wajib untuk reject/withdraw. Penolakan bukan pembatalan pesanan.

Tidak ada write stok, ledger, kas, invoice atau kredit pada proposal/keputusan. Pengiriman normal berikutnya memakai kuota yang telah disetujui ulang; pergerakan stok tetap melalui StockLedger. Riwayat dan snapshot diaudit pada kedua organisasi terkait, tanpa memberi pusat akses ke penjualan eceran mitra. Izin organisasi/toko tetap diperiksa API; capability hanya hint tampilan.

## Kontrak dan retry

OpenAPI 0.20.0 menambah GET/POST `orders/{order}/changes` dan POST `orders/{order}/changes/{change}/approve|reject|withdraw`. GET privat/no-store, pagination 50 descending ID, order/context/capability server. Request mutasi membutuhkan UUID operation_id dan versi asli yang ditinjau. Proposal hanya menerima ID baris/jumlah; harga dan total bukan input client. UUID identik pada actor/scope/body yang sama mengembalikan hasil tersimpan, termasuk setelah keputusan berikutnya; penggunaan berbeda ditolak 409. Setelah ACK muat context terkini karena hasil replay dapat historis.

Web tetap BFF HTTP, tidak membaca DB API. Session server menyimpan path/body/UUID/versi sebelum pengiriman. Respons hilang, 5xx, atau ACK tidak cocok menahan request immutable per pesanan. GET halaman menampilkan request tertahan; POST retry mengabaikan edit browser dan mengirim salinan persis. Operasi pengganti ditolak; penolakan izin setelah hasil unknown tidak menghapus request lama. Respons definitif pada pengiriman awal membebaskan slot agar input dapat ditinjau ulang. ACK harus cocok order, change/status dan kenaikan versi satu.

Session bukan antrean tahan restart: logout/401 menghapus session; login berhasil juga membersihkan pending akun sebelumnya. Setelah kehilangan session, tinjau riwayat/order sebelum membuat operasi baru. Tidak ada jaminan recovery lintas perangkat/session atau sinkronisasi offline. Token/cookie API tidak ditaruh dalam HTML. Komponen atomic existing digunakan bersama organisme order-change; domain tetap pada API.

## Migrasi dan batas

Migrasi `2026_11_02_000006_create_order_changes` hanya menambah tabel history. FK restrict dan nullable unique pending_order_id memastikan satu usulan aktif per pesanan; seluruh mutasi mengunci parent yang sama dengan workflow/pengiriman. Rollback ditolak jika tabel sudah pernah dipakai agar history tidak terhapus; gunakan perbaikan maju atau restore yang ditinjau. Migrasi development dijalankan tanpa reset data. Pengujian SQLite tidak membuktikan concurrency PostgreSQL multi-koneksi.

Belum tersedia penghapusan seluruh baris, penambahan jumlah/SKU, substitusi, perubahan harga/metode/alamat, revisi sesudah pengiriman, retur/pengganti/kredit/refund, invoice, notifikasi push, maupun layar composer reapproval Flutter. Endpoint amendment native lama tetap khusus awaiting_approval/rejected; jangan perluas PUT lama untuk melewati workflow ini. UI native existing mematuhi capability hold dan API menolak mutasi stale. Browser/perangkat nyata, UAT dan PostgreSQL tetap perlu verifikasi.
