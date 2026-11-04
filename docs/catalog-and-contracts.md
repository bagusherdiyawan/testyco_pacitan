# Gambar, harga efektif, resep paket dan kontrak

DL-17 tersedia pada API/web untuk pengelolaan; Flutter menggunakan katalog harga efektif, gambar privat dan checkout paket. Dari **Katalog → produk → Gambar, harga efektif dan resep paket**, pengelola mengatur data. Dari **Organisasi → Kontrak dan persetujuan kemitraan**, pusat menerbitkan kesepakatan untuk pimpinan mitra.

## Harga dan gambar

Periode harga memakai tanggal kalender WIB inklusif, channel retail per biji/paket atau wholesale per kemasan dan integer rupiah eksplisit. Periode aktif dalam produk/channel/cakupan mitra yang sama tidak boleh overlap. Grosir memilih harga khusus mitra, lalu umum, lalu harga dasar; eceran memilih periode umum organisasi, lalu dasar. Semua writer mengunci produk yang sama dengan quote checkout; perubahan saat review/payment men-stale hash. Pembatalan periode berversi menyimpan riwayat. Order, draft yang sudah dibekukan, struk dan harga historis tidak ditulis ulang. Katalog read memuat metadata harga/resep/gambar secara bulk per halaman, menjaga scope dan tanpa N+1 per SKU.

Gambar PNG/JPEG/WebP raster maksimal5MiB/4096px disimpan privat berdasarkan SHA256. Base64 ketat, file SVG dan URL/path dari browser tidak diterima. Upload berversi/UUID, read membutuhkan pengelola organisasi atau akses toko assigned yang sesuai. Response binary memakai private/no-store/nosniff/CSP; BFF hanya menyalin bytes/MIME yang diizinkan, tidak cookie/token/path. Flutter mengambil bounded base64 melalui ApiClient dan menampilkan di memori, tanpa menyimpan kredensial/URL rahasia atau file gambar ke disk.

Private disk default `apps/api/storage/app/private`; `PRIVATE_STORAGE_PATH` dapat menunjuk volume privat persisten. Isolated smoke mengarahkannya ke `/tmp/private-assets` di container sementara. Backup produksi harus mencakup private files bersama database; tidak mengklaim backup/restore produksi telah diuji.

## Resep paket dan POS

SKU paket memiliki pack_size1/portion_size1; satu unit piece pada checkout berarti satu paket. Resep berversi memuat SKU komponen organisasi sendiri dan jumlah biji eksplisit. Tidak menerima self/nested recipes atau komponen lintas organisasi. Komponen dibekukan dalam snapshot, harus tetap aktif/condition sesuai saat checkout, dan perubahan resep men-stale draft lama.

Contoh paket2 ayam +1 udang: pilih batch opened/ready masing-masing komponen. Draft memeriksa jumlah persis resep×jumlah paket; pembayaran memotong ayam2 dan udang1 pada satu transaksi StockLedger, bersama sale/shift/operation receipt. Tidak membagi stok sealed otomatis atau menjual karantina. Insufficient batch mana pun membatalkan seluruh pembayaran. Harga paket mengikuti SKU paket/effective retail, bukan jumlah harga komponen. Struk menyimpan recipe_snapshot, allocations mengacu batch komponen sebenarnya, dan laporan pendapatan memakai SKU paket.

Web POS menyediakan pilihan paket dan form batch komponen; Flutter cart menampilkan selector setiap komponen dan jumlah paket, memakai draft/pay/recovery existing. Stok tidak direservasi saat draft. Repeat UUID/payment pada intent yang sama tidak memotong stok lagi. Endpoint kasir tidak mengirim harga grosir. Ini POS tunai existing; QRIS/refund/promo DL-16 masih menunggu keputusan terpisah.

## Kontrak dan consent

Modul Partnership terdaftar eksplisit pada API dan web, bergantung Identity. Owner/admin pusat membuat draft untuk mitra aktif, dengan periode, mode putus beli/konsinyasi, ketentuan, penggunaan merek, cakupan data, fasilitas dan kewajiban yang diisi eksplisit. Lampiran PDF privat opsional maksimal5MiB dibatasi header/MIME PDF, disimpan privat dan diunduh sebagai attachment, tidak di-render inline. Path penyimpanan tidak dikirim pada API/audit.

Draft dan audit draft hanya terlihat pusat pemilik. Setelah publish, pimpinan mitra yang dituju dapat membaca dan mengunduh, menyetujui dengan checkbox consent serta nama penanggung jawab, atau menolak dengan alasan. Pusat dapat menarik draft/published; accepted tidak dapat ditimpa/ditarik melalui workflow ini. Perubahan ketentuan dibuat sebagai dokumen baru. Versi/aktor/waktu/keputusan dan SHA256 terms termasuk digest PDF dicatat serta diaudit kedua pihak setelah terbit. Ini bukti persetujuan aplikasi, bukan sertifikat tanda tangan digital.

Kontrak tidak otomatis memperluas ACL/data POS, mengaktifkan bagi hasil konsinyasi, menagih invoice, memotong fasilitas atau membuat transaksi keuangan. Sumber03-KON memang meminta konsinyasi ditandai proses khusus; keputusan D-01/D-03/D-08 untuk DL-13/15/16 tetap terbuka. Tidak ada tarif pajak/denda/pembiayaan otomatis yang diasumsikan.

API mutation memakai UUID/version dan transaksi. Shared PendingMutation pada BFF membekukan payload sebelum POST, mempertahankan unknown melewati denial, memvalidasi ACK termasuk data penting, kemudian memuat riwayat.401/login/logout membersihkan session lama. Pagination dan links lokal, teks selalu escaped. Riwayat terpakai menolak rollback; files memakai private persistent volume. Lihat [verifikasi](verification.md); browser interaktif, perangkat nyata, UAT dan PostgreSQL concurrency belum diverifikasi.


Pembaruan4 November2026 (menggantikan keterangan backlog keuangan/retur/non-tunai pada irisan lama): [retur, tagihan dan penyesuaian POS](finance-and-pos-adjustments.md) kini diimplementasikan untuk DL-13/DL-15/DL-16 dengan input eksplisit, persetujuan dan verifikasi manual. D-01/D-03/D-08 belum menjadi kebijakan global; gateway, bagi hasil otomatis dan pendanaan promo lintas organisasi belum aktif. Laporan tunai tetap struk tunai sebelum pengurangan refund; histori refund/shift terpisah.
