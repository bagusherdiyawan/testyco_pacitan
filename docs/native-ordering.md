# Persetujuan dan penerimaan pesanan di Flutter

Irisan PRD 04-APR, 05-RCV dan 09 penerimaan aktual. Flutter mobile/tablet kini dapat menyetujui, menolak atau membatalkan pesanan sesuai izin/status serta menerima kiriman berdasarkan barang fisik. Pengajuan baru kini tersedia pada [composer Flutter](native-order-entry.md). Perubahan sebelum persetujuan kini tersedia di [alur perubahan native](native-order-amend.md); pemilihan gudang dan dispatch kini juga melalui [modul Gudang Flutter](native-warehouse.md). Tidak ada kebijakan finansial/retur baru pada irisan ini.

## Izin dan navigasi

Masuk, pilih toko, buka modul **Pesanan** lalu detail order. Role pemohon yang diizinkan tetap mengikuti API (owner/admin/pimpinan mitra/kepala toko/gudang sesuai membership dan assignment). Kasir tidak mendapat mutasi ini. `FEATURE_ORDERING=false` menonaktifkan modul presentasi saat build; flag tidak menggantikan otorisasi server.

Pimpinan mitra menyetujui/menolak pesanan grosir organisasinya; transfer internal pusat disetujui owner/admin pusat. Kepala toko/gudang penerima boleh mencatat penerimaan pada toko assigned, tetapi tidak mendapat approval hanya karena bisa menerima. Hak pusat sebagai pemasok untuk membaca order yang sudah disetujui tidak memberinya hak penerimaan toko mitra. Tidak ada override onboarding untuk tindakan operasional mitra.

GET detail mengembalikan `recipient_actions` sesuai membership, access_side, status dan toko aktif. Approve/reject hanya awaiting_approval/rejected; cancel juga approved/processing sebelum dispatch; receive hanya dispatched/partially_received. Setiap kiriman tetap harus belum mempunyai received_at. Flag ini petunjuk UI; workflow API memeriksa ulang izin/versi/state dalam transaksi. Toko nonaktif tidak mempunyai tombol mutasi; riwayat tetap mengikuti izin Identity/Ordering.

## Keputusan pesanan

1. Buka detail dan tinjau SKU pusat/lokal, jumlah kemasan, isi historis, harga per kemasan dan total server.
2. Pilih **Setujui pengajuan**, **Tolak pengajuan** atau **Batalkan pesanan** bila tersedia.
3. Tinjau ringkasan dan versi dalam dialog. Penolakan/pembatalan wajib alasan; alasan tersimpan pada histori keputusan. Persetujuan memeriksa harga/konversi terbaru di server dan belum menambah stok tujuan.
4. Jika harga/kemasan berubah atau versi stale, muat ulang dan gunakan web untuk memperbarui pengajuan bila perlu. Aplikasi tidak memperbarui versi/payload lama diam-diam.

## Penerimaan aktual

Dari kiriman belum diterima, pilih **Isi penerimaan**. Semua input baik dan rusak dimulai kosong. Isi setiap batch secara eksplisit, termasuk angka 0. Satuan input adalah **kemasan**, bukan biji; konversi memakai pack_size historis kiriman, bukan master saat ini.

Jumlah baik + rusak tidak boleh melampaui jumlah dikirim. Catatan kondisi per batch wajib bila tidak semua kiriman diterima baik, termasuk kerusakan atau kehilangan. Catatan umum tidak menggantikan alasan per batch. Bila tidak ada barang fisik diterima, good=0 dan damaged=0 dapat dicatat dengan alasan; sistem tidak mengarang stok.

Tinjau seluruh batch pada dialog, lalu konfirmasi. API mengharuskan semua baris tepat sekali dan menyimpan seluruh penerimaan/ledger/audit secara atomik. Kegagalan baris kedua membatalkan kredit baris pertama. Contoh 20 kemasan isi 8, baik 18/rusak 2 → 144 biji usable + 16 karantina. Order tetap partially_received; tidak ada kiriman pengganti, kredit atau penerimaan fiktif otomatis. Barang rusak tidak menjadi stok siap jual.

Sesudah sukses, detail dimuat ulang dan daftar pesanan diperbarui saat kembali. Kiriman yang telah diterima tidak mempunyai formulir penerimaan baru. Selisih, kondisi dan catatan penerimaan tetap tampil untuk tindak lanjut.

## Jaringan, refresh dan batas pemulihan

Controller menyimpan UUID, path dan seluruh body nested sebagai JSON immutable selama hasil POST belum diketahui. Form/tindakan baru dan pop ditahan. Gunakan **Ulangi pencatatan yang sama**; reload tidak mengganti operasi pending, meskipun order/shipment di server sudah berubah. Replay identik mengembalikan hasil awal sebelum pemeriksaan versi domain, sehingga tidak menggandakan kredit stok.

Jika respons definitif 409/403/401 diterima, pilihan snapshot dan tindakan dinonaktifkan sampai detail diperiksa ulang. Refresh membuang pilihan kiriman dan isian fisik; pilih kiriman lagi, hitung/periksa kembali, dan kirim versi yang benar-benar ditinjau. Bila POST sudah sukses tetapi refresh gagal, aplikasi menyatakan pencatatan tersimpan dan meminta reload, tanpa menyiapkan mutasi baru.

Pending operation hanya berada di memori halaman. Tutup paksa, perangkat mati atau pergantian sesi dapat menghilangkan UUID. Setelah login kembali, periksa status kiriman, jumlah baik/rusak, catatan keputusan dan audit sebelum membuat tindakan pengganti. Penerimaan yang sudah confirmed tidak boleh dicatat ulang hanya karena respons perangkat sebelumnya tidak terlihat. Belum ada antrean offline, secure-storage/pemulihan otomatis lintas restart atau sinkronisasi konflik.

## Modul dan batas verifikasi

Ordering mengekspos metadata recipient_actions; Fulfillment/Inventory tetap memiliki penerimaan dan stok. Jika Fulfillment backend dimatikan, detail menyatakan fulfillment_enabled=false; Flutter melewati endpoint shipments dan tidak menawarkan penerimaan. Metadata response lama tanpa recipient_actions tetap dapat dibaca, tetapi tidak diberi tombol mutasi berdasarkan tebakan role/legacy can_approve.

DTO, repository, controller dan page/form terpisah. Molecule ShipmentReceiptFields menerima nilai/controller tampilan tanpa mengubah ledger. Domain stock/cash tetap di API. Tidak ada migrasi/dependensi baru.

Widget mobile 390px dengan text scale 1,5 dan tablet 1200px, akses API, retry dan atomic receipt sudah diuji. Ini belum build/perangkat Android/iOS, UAT gudang/toko, PostgreSQL concurrency atau bukti kualitas pangan. QRIS, refund, retur/kredit/pengganti, revisi SKU/penambahan/penghapusan baris, revisi setelah kirim dan offline tetap belum tersedia. Pemilihan sumber/dispatch kini tersedia pada modul Gudang.

Pengurangan jumlah pascapersetujuan sebelum pengiriman pertama kini melalui **Persetujuan ulang dan riwayat** dari detail pesanan. Lihat [panduan native](native-order-reapproval.md) untuk review, keputusan dan retry immutable terpisah dari amendment sebelum approval.
