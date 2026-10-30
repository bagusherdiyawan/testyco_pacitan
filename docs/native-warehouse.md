# Pemenuhan gudang di Flutter

Irisan PRD 09-PICK/SHIP dan 04 pemrosesan pusat. Flutter kini menyediakan inbox gudang, penetapan sumber, alokasi beberapa batch dan pengiriman parsial melalui workflow API yang sama dengan web. Tidak ada aturan finansial, reservasi atau pengganti baru.

## Navigasi dan sumber

Masuk sebagai owner/admin pusat atau petugas warehouse assigned. Pilih toko pusat yang akan menjadi gudang sumber, lalu buka modul **Gudang** (melalui **Lainnya** pada mobile bila navigasi penuh). Untuk memilih sumber lain, gunakan **Ganti toko** sebelum membuka order. Ini mengikuti model toko pusat yang sama dengan web; tidak menambahkan jenis lokasi/aturan gudang baru.

GET `/stores` menambah metadata Identity `organization_type`. Flutter menampilkan modul hanya untuk tipe center dan role owner/admin/warehouse; metadata lama tanpa tipe menyembunyikan modul. Ini petunjuk navigasi, bukan hak mutasi. Server tetap memeriksa organisasi aktif, role dan assignment. Mitra, kepala toko, kasir, pusat lain atau warehouse unassigned tidak mendapat data operasional sumber ini.

Inbox berhalaman 50 pesanan dibatasi pada pemasok dan toko sumber yang dipilih. Owner/admin melihat order approved yang belum bersumber (kecuali toko tujuan sama dengan calon sumber), serta riwayat yang sudah bersumber di toko ini. Warehouse hanya melihat order setelah ditugaskan ke gudangnya. Pengajuan awaiting/rejected yang belum disetujui tidak masuk inbox pemasok. Pagination memakai page pada path scoped, bukan next_page_url eksternal.

1. Owner/admin memilih order, meninjau tujuan, antar/ambil dan versi, lalu **Gunakan gudang ini**. Dialog menegaskan nama sumber yang dipilih. Ini mengubah status menjadi processing tanpa memotong/mereservasi stok.
2. Setelah sumber ditetapkan, petugas berwenang meninjau sisa pesanan dan stok batch utuh di gudang tersebut. Batch dipilih manual melalui jumlah kemasan, tanpa default. Kosong atau 0 berarti tidak dikirim. Beberapa batch per SKU serta subset item/kuantitas diperbolehkan untuk kiriman parsial.
3. Isi petugas/kurir/serah-terima dan catatan bila perlu. **Tinjau pengiriman** menunjukkan SKU snapshot, batch, expiry, jumlah kemasan × isi historis, sumber/tujuan/mode serta versi yang akan dikirim. Konfirmasi hanya setelah fisik diperiksa.
4. Workflow mengurangi stok utuh sumber, menaikkan stock_revision, menyimpan shipment/baris dan audit atomik. Kegagalan batch/item membatalkan seluruh kiriman. Tujuan belum mendapat stok sampai penerimaan aktual yang sudah tersedia di modul Pesanan.

Saldo kandidat hanya usable_pieces/kemasan utuh, dengan product_id pemasok dan pack_size historis yang cocok. Karantina, opened/ready dan stok gudang lain tidak ditawarkan. Expiry ditampilkan untuk pemeriksaan SOP; belum ada FEFO atau blok kedaluwarsa/mutu otomatis. stock_revision pada context adalah informasi, bukan parameter guard dispatch baru; server memeriksa stok terkini di transaksi. Stock opname/proses tetap memakai guard revision masing-masing.

## Sisa dan hak tindakan

Context mengembalikan `can_process`/`can_dispatch` dari izin, toko/organisasi tujuan aktif, sumber aktif, status dan sisa. Flutter tidak menebak dari can_process lama pada GET order, role lokal atau status saja. Metadata hilang berarti tidak ada tindakan. Source pada context harus sama dengan pilihan toko; ID order juga harus cocok. Supplier permission tidak menjadi recipient permission mitra.

Sisa = quantity_packs dipesan − seluruh quantity_packs yang pernah dikirim. Bukan dipesan − diterima baik. Contoh order 20, kirim 8 → sisa 12. Bila 20 sudah dikirim dan penerima mencatat 18 baik/2 rusak, sisa tetap 0, can_dispatch=false. Tidak ada kuota pengganti, penerimaan fiktif atau kredit otomatis. Retur/kredit/pengganti serta perubahan setelah approval/reapproval tetap belum dibuat.

Transfer internal mengikuti order kind/total nol yang sudah ada; sumber harus berbeda dari toko tujuan. Tidak membuat pendapatan mitra atau invoice. Riwayat sumber nonaktif dapat dibaca melalui API berizin, tetapi flag mutasi false dan kandidat batch tidak dimuat; pemilih toko Flutter tetap mengikuti batas toko aktif yang sudah ada.

## Versi, jaringan dan batas pemulihan

Process/dispatch memakai original order version dan UUID. Stok atau versi order dapat berubah setelah ditinjau; konflik meminta refresh dan pemeriksaan fisik ulang, tanpa rebase otomatis. Refresh eksplisit mengosongkan jumlah batch/petugas/catatan dan mengganti epoch field agar angka lama tidak bertahan pada snapshot baru.

Saat respons tidak pasti, controller mempertahankan JSON nested, path, UUID dan ringkasan review immutable. Edit/refresh/pop ditahan; **Ulangi pencatatan yang sama** tidak membuat UUID baru. Penolakan setelah percobaan sebelumnya unknown tidak membuktikan tidak ada commit, sehingga pending tetap dipertahankan. Replay server mengembalikan hasil awal sebelum guard versi/status domain, dengan pemeriksaan izin saat ini tetap berlaku.

Sesudah ACK, detail GET dimuat kembali untuk membaca versi, sisa, batch dan riwayat terkini; respons replay historis tidak dipakai sebagai snapshot terbaru. ACK lalu refresh gagal tetap menyatakan pencatatan tersimpan dan tidak menjadi POST pengganti. Pending hanya dalam memori halaman. Setelah restart, perangkat mati atau sesi berakhir, periksa shipment/ledger/audit sebelum membuat pengganti. Belum ada antrean persisten atau operasi offline.

## Modul, kontrak dan verifikasi

Fulfillment backend menyediakan GET `/api/v1/stores/{store}/fulfillment/orders` dan `.../orders/{order}` sebagai read model privat/no-store, tanpa menulis stok/audit/claim. Domain mutasi tetap OrderWorkflow.process dan FulfillmentWorkflow.dispatch dengan StockLedger/Operations. Backend Fulfillment membutuhkan Ordering/Inventory. Registry Flutter menambahkan FulfillmentModule, `FEATURE_FULFILLMENT=false` menyembunyikan modul; provider backend nonaktif tetap menolak endpoint dan tidak mengizinkan mutasi.

DTO/repository/controller/page terpisah; DispatchBatchField dan DispatchReview hanya tampilan reusable. Tidak ada migrasi/dependensi baru. Bukti pengujian mencakup source/tenant/assignment, read-only context, internal transfer, multi-batch parsial, sisa berdasarkan sent, replay, perubahan stok/versi, unknown/denial, ACK/failed refresh serta widget 390px text scale 1,5/1200px. SQLite/widget bukan bukti PostgreSQL concurrency, build/perangkat Android/iOS, cold-chain safety, UAT atau jadwal/foto/suhu terstruktur.
