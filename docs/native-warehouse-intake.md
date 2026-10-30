# Penerimaan awal gudang di Flutter

Irisan PRD 05-RCV/MOV, katalog/satuan PRD 02 dan izin PRD 01. Flutter kini mencatat penambahan stok gudang pusat melalui workflow Inventory yang sama dengan web. Ini penerimaan awal barang fisik yang diterima baik, bukan penerimaan pesanan mitra, koreksi opname atau retur/refund.

## Alur operasional

Pilih toko pusat, buka **Stok → Penerimaan awal gudang** sebagai owner/admin pusat atau warehouse assigned. Tombol memakai metadata tipe/role toko sebagai petunjuk navigasi; endpoint memeriksa ulang izin. Kasir, kepala toko, mitra, pusat tanpa membership terkait dan warehouse unassigned tidak dapat memakai endpoint ini. Inventory tidak memerlukan Ordering/Fulfillment untuk fitur penerimaan awal; `FEATURE_INVENTORY` tetap mengatur modul native.

Katalog penerimaan mengambil SKU aktif milik organisasi sumber, hanya ID/SKU/nama/kondisi/isi kemasan, tanpa harga. Setiap halaman memeriksa source ID/aktif serta `can_intake`; metadata lama/hilang/salah toko tidak mengaktifkan isian. Tidak ada auto-match nama. Pagination memakai path source dan nomor halaman, tanpa mengikuti next_page_url eksternal. Pembacaan tiap halaman bukan snapshot katalog beku.

1. Cari dan pilih SKU pusat secara eksplisit. Isi kode batch, kedaluwarsa YYYY-MM-DD, jumlah **kemasan diterima baik** serta referensi/catatan pemeriksaan. Tidak ada jumlah, tanggal, batch atau catatan bawaan.
2. Pilih **Tinjau penerimaan di server**. Review menampilkan gudang, SKU/nama/kondisi, batch, kedaluwarsa dan konversi otoritatif: contoh 2 kemasan isi 8 = **16 biji ditambahkan**. Ini delta penambahan, bukan total saldo batch terkini.
3. Periksa fisik, lalu konfirmasi. POST mengkredit usable, menambah stock_revision serta ledger/audit atomik sekali. API tidak mempercayai quantity_pieces/pack_size kiriman client.
4. Sesudah ACK, halaman menampilkan batch ID serta penambahan yang ditinjau dan menahan konfirmasi ulang. **Kembali dan muat stok terkini** memuat ulang halaman stok. Saldo dari replay POST tidak diberi label saldo terbaru.

Jumlah wajib integer desimal 1–10000; kode batch maksimal 80 karakter dan catatan wajib maksimal 2000. UI memeriksa tanggal kalender nyata termasuk leap year. Sistem belum memblokir kedaluwarsa otomatis atau menyatakan mutu aman; tanggal/kondisi fisik mengikuti SOP. Barang rusak/selisih mitra tetap melalui actual receipt/order dan karantina, bukan dihitung menjadi penerimaan awal baik.

Identitas batch tetap store/product/batch_code/pack_size historis. Penerimaan tambahan pada identitas yang sama sah setelah barang fisik diperiksa. Kedaluwarsa atau kondisi berbeda pada identitas yang sama ditolak tanpa perubahan parsial. Perubahan master pack_size tidak menulis ulang batch lama; preview baru harus ditinjau untuk konversi baru.

## Review, perubahan dan jaringan

Preview read-only memakai builder snapshot yang sama dengan POST. `review_hash` mengikat gudang/nama, product ID, SKU/nama/kondisi/isi, batch/tanggal/jumlah dan catatan. Perubahan fakta antara preview dan POST ditolak sebelum batch/ledger/audit tersimpan. Hash adalah pemeriksaan optimistis, bukan signature, izin, reservasi atau TTL. Legacy web/API tanpa hash tetap kompatibel dan selalu memakai data produk server.

Setelah preview, isian terkunci. **Ubah isian** membuang quote yang belum dikirim. Refresh membuang quote, memuat katalog terbaru dan mempertahankan intent lokal untuk diperiksa lagi; SKU yang sudah tidak aktif tidak diganti otomatis. Konflik/penolakan definitif pertama meminta reload dan review baru, tanpa rebase quote atau konversi diam-diam.

Jika respons tidak pasti, pertahankan halaman dan gunakan **Ulangi penerimaan yang sama**. Controller menyimpan path/UUID/body JSON serta review immutable; edit/refresh/pop ditahan. Denial setelah percobaan sebelumnya unknown tidak membuktikan tidak ada commit, sehingga UUID tidak diputar/dibuang otomatis. Replay committed UUID mengembalikan batch asli sebelum validasi master terbaru, termasuk jika produk telah berubah/nonaktif, dengan izin sumber saat ini tetap diperiksa.

Halaman tidak menafsirkan saldo historis dalam hasil replay sebagai saldo terkini; dispatch/proses lain dapat mengubah saldo setelah intake awal. Kegagalan pemuatan stok setelah kembali tidak menjadi alasan membuat intake pengganti. Pending masih hanya di memori halaman. Setelah restart, perangkat mati atau sesi berakhir, periksa batch/ledger/audit sebelum membuat pengganti. Belum ada secure storage, antrean tahan restart atau operasi offline.

## Struktur dan kontrak

WarehouseIntake memiliki authorization/snapshot/preview/record, StockLedger tetap satu-satunya penulis saldo, Operations menjaga idempotensi dan Audit mencatat penambahan. Controller hanya validasi/transport; web terpisah tetap HTTP dan tidak mengakses database API. Tidak ada migrasi/dependensi baru.

- GET `/api/v1/stores/{store}/inventory/intake-products`: paginated 100 SKU dengan store/can_intake/observed_at, private/no-store.
- POST `.../intakes/preview`: input produk/batch/tanggal/kemasan/catatan; read-only tanpa batch, stock entry, operation receipt atau audit.
- POST `.../intakes`: UUID dan input sama, review_hash opsional untuk kompatibilitas; native selalu mengirim hash. Hasil batch asli; gunakan GET stok untuk saldo terkini.

DTO/repository/controller/page dipisahkan, IntakeReviewCard hanya merender snapshot server. Pengujian mencakup scope/role, pagination tanpa harga, preview tanpa penulisan, perubahan konversi/body, expiry conflict, replay setelah master nonaktif, unknown/denial, malformed ACK, tanggal/jumlah invalid, edit quote, mobile 390px text scale 1,5/tablet 1200px dan navigasi kembali ke stok. SQLite/widget/HTTP bukan bukti PostgreSQL concurrency, build Android/iOS, UAT atau keamanan pangan.
