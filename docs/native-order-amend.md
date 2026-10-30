# Perubahan pengajuan sebelum persetujuan di Flutter

Irisan PRD 04-REQ/APR untuk status awaiting_approval/rejected, memakai aturan PUT yang sebelumnya sudah tersedia pada web/API. Fitur ini tidak membuka perubahan setelah approval atau reapproval baru. Pengiriman gudang tetap melalui web.

## Izin dan alur

Buka **Pesanan → Detail → Ubah pengajuan** bila tombol tersedia. Hanya pemberi persetujuan yang berwenang (pimpinan mitra untuk wholesale, owner/admin pusat untuk internal transfer) dengan akses penerima dan toko aktif. Kepala toko yang boleh membuat pengajuan tidak otomatis boleh mengubahnya; kasir, mitra lain dan hak pemasok/onboarding pusat tidak memberi akses ini. Native memakai recipient_actions.amend dari server dan default deny jika metadata tidak tersedia.

Composer yang sama dengan pengajuan baru memuat product ID pusat/lokal, jumlah kemasan, metode ambil/antar dan catatan yang benar-benar tersimpan. Pasangan SKU tidak ditebak dari nama. Pilihan yang sekarang nonaktif/tidak cocok harus diperbaiki secara eksplisit. Ubah item/jumlah/metode/catatan, lalu **Tinjau pengajuan di server**. Preview tidak menyimpan perubahan, audit atau stok.

Tinjau snapshot harga/konversi dan tujuan dari server, lalu **Konfirmasi perubahan**. Payload memakai ID order yang sama, versi awal yang ditinjau, hash preview perubahan dan UUID operasi. API memeriksa state/versi/izin serta hash sebelum mengganti baris. Hash terikat original order_id/version; quote order lain atau quote create tidak bisa dipakai. Perubahan harga/tujuan/payload setelah review ditolak tanpa menghapus item lama atau meninggalkan claim operasi.

Sesudah tersimpan, ID order tetap, versi bertambah sekali dan status kembali awaiting_approval; keputusan penolakan lama dihapus dari status aktif tetapi histori before/after tetap ada pada audit. Tidak ada stok, invoice, kredit atau pengiriman otomatis. **Lihat detail pengajuan** kembali ke detail dan memuat status terbaru. Preview/hash bersifat concurrency guard, bukan izin, tanda tangan harga atau reservasi stok. PUT tanpa hash masih kompatibel untuk pemanggil lama, tetap memakai harga/izin/version API.

## Perubahan bersamaan dan jaringan

Selama composer hidup, refresh tidak mengangkat isian lokal ke versi order yang baru secara diam-diam. Jika order berubah, tindakan ditahan dan tombol **Mulai ulang dari versi terbaru** ditampilkan. Dialog menjelaskan bahwa perubahan lokal akan dibuang. Setelah disetujui pengguna, seluruh baris, jumlah dan catatan diisi dari versi terbaru; key form diperbarui agar state TextFormField lama tidak tertinggal. Jika order sudah disetujui/ditutup atau izin hilang, pengeditan ditolak dan detail tetap dapat diperiksa sesuai akses.

Jika hanya harga katalog berubah dan original version masih sama, muat ulang katalog dan tinjau lagi; intent lokal tidak otomatis dijadikan harga baru. Status/error tidak pernah membuat aplikasi menulis delta stok atau mengirim approval.

Respons PUT yang tidak diketahui mempertahankan nested body, original version/hash dan UUID. Refresh tidak mengganti pending request dan tombol mulai ulang tidak dapat membuangnya. Gunakan retry yang sama. Replay server yang sudah committed mengembalikan hasil awal, meskipun harga/status kemudian berubah; tidak menambah versi atau menimpa perubahan berikutnya. Native hanya memakai ID ACK untuk navigasi lalu GET detail terbaru.

Pending hanya di memori. Pergantian sesi/tutup paksa masih memerlukan rekonsiliasi detail dan audit sebelum tindakan pengganti; tidak ada antrean offline atau nonce durable. Penolakan setelah hasil unknown tetap ditangani konservatif, tidak memutar UUID agar lolos konflik. Auth-boundary navigator tetap kembali ke login saat sesi berakhir.

## API dan batas

POST `/api/v1/orders/{order}/preview` menerima version, delivery_mode, note opsional dan lines. PUT `/api/v1/orders/{order}` memakai body yang sama ditambah UUID dan review_hash pada native. Preview/create/amend berbagi pembangun snapshot; server mengunci order/target/produk dan menjaga penggantian baris/audit/result atomik. Tidak ada migrasi/dependensi baru.

Tes mencakup cross-order hash, stale version/price, peran terlarang, preview tanpa penulisan, replay, composer prefill, reset eksplisit dan widget 390px text scale 1,5/1200px. HTTP smoke memverifikasi hash-bound preview/PUT/replay pada order yang sama sebelum approval. Pemilihan sumber/dispatch kini tersedia di [modul Gudang](native-warehouse.md). Belum ada perubahan setelah approval, PostgreSQL concurrency, build perangkat, UAT atau recovery offline.
