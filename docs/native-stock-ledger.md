# Riwayat pergerakan stok di Flutter

Halaman web berfilter kini juga tersedia; lihat [panduan web](web-stock-ledger.md).

Irisan PRD 05-MOV serta 01-ACL. Petugas berwenang kini membaca ledger dari mobile/tablet untuk menelusuri penambahan, perpindahan keadaan, pengiriman, penerimaan dan debit POS. Fitur ini read-only; tidak menambah stok, mengubah audit atau membuat operasi pengganti.

## Penggunaan dan akses

Pilih toko, buka **Stok → Riwayat pergerakan stok**, atau **Riwayat batch ini** pada kartu batch. Link per batch membawa inventory_batch_id yang tepat, bukan pencocokan nama/kode. **Lihat semua batch** membuang filter batch dan kembali ke halaman 1 dengan periode/pencarian/sumber/bucket yang sudah diterapkan tetap sama.

Owner/admin/pimpinan mitra sesuai membership serta kepala toko/warehouse assigned boleh membaca. Kasir tidak mendapat riwayat ledger ini; akses struk kasir tetap mengikuti Sales. Tombol native hanya petunjuk navigasi; endpoint memeriksa ulang role/organisasi/assignment. Admin pusat untuk onboarding tidak otomatis membaca ledger mitra. Toko nonaktif mempertahankan riwayat API yang berizin dalam organisasi aktif; pemilih toko Flutter tetap mengikuti toko aktif yang sudah ada.

Default periode hari ini kalender WIB, termasuk tanggal akhir. Buka **Filter riwayat** untuk mengubah tanggal YYYY-MM-DD, pencarian SKU/nama/kode batch, jenis sumber atau bucket. Maksimal 31 hari inklusif. Pilih **Terapkan filter**; isian belum diterapkan tidak mengganti label atau query data lama. Pagination dan refresh memakai filter yang benar-benar diterapkan. Input tanggal/urutan/rentang salah ditampilkan sebagai error form, tanpa request baru.

Setiap halaman maksimal 100 baris, terbaru berdasarkan created_at lalu ID. Waktu observasi ditampilkan. Filter akhir kalender dikirim sebagai awal hari berikutnya; server memakai interval `[from,to)` bertimezone. Tanggal hari bisnis/penutupan shift tidak diubah oleh filter ini. API legacy tanpa kedua batas waktu tetap membaca seluruh riwayat berhalaman.

## Membaca baris dengan tepat

Baris menampilkan SKU/nama dari snapshot batch, kode batch dan isi kemasan historis, perubahan bertanda +/− dalam **biji**, bucket, jenis sumber, waktu WIB, pelaku #ID, catatan, ID pergerakan dan referensi sumber yang dapat disalin. Perubahan katalog/nonaktifnya master tidak mengganti snapshot batch. Nama toko pada header adalah metadata toko saat dibaca. ID pelaku tidak diklaim sebagai nama historis pengguna.

- `+16 biji · Utuh`: penambahan bucket utuh sebesar 16, bukan saldo akhir 16.
- Buka 1 kemasan isi 8 menghasilkan `−8 Utuh` dan `+8 Beku terbuka`, dengan sumber proses yang sama.
- Karantina/susut tetap terlihat sebagai bucket sendiri. Menjumlahkan halaman atau hasil filter tidak menghasilkan saldo batch secara lengkap.

Saldo terkini tetap pada halaman stok. Daftar ini bukan running balance, sertifikasi mutu, reservasi atau bukti bahwa semua operasi offline sudah masuk. Tiap halaman merupakan observasi baru; penambahan entry saat berpindah halaman dapat menggeser hasil. Refresh ke halaman awal untuk melihat aktivitas terbaru.

Referensi sumber adalah `source_id` ledger, **berbeda dari UUID request idempotensi** dan tidak selalu ID order/struk utama:

| Jenis | Referensi yang disimpan |
| --- | --- |
| warehouse_intake | ID sumber penerimaan internal, terkait audit intake |
| shipment_dispatch / shipment_receipt | ID baris shipment |
| inventory_open / inventory_cook | ID proses stok |
| stock_count / stock_waste | ID proposal hitung/susut |
| pos_sale | ID alokasi stok penjualan |

Tidak ada link yang melewati izin untuk membuka objek sumber. Gunakan batch, jumlah, waktu, pelaku, catatan dan audit/detail yang berizin untuk rekonsiliasi. Riwayat membantu pemeriksaan setelah respons hilang/restart, tetapi tidak memulihkan UUID yang hilang atau membuktikan otomatis bahwa aman membuat pengganti. Pending lintas restart/offline tetap belum tersedia.

## Kontrak dan struktur

GET `/api/v1/stores/{store}/inventory/ledger` mempertahankan field stock_entries lama dan pagination 100. Tambahan: sku/name/batch_code/condition/pack_size/expires_on dari batch, `occurred_at` UTC eksplisit, store, observed_at dan filters. `created_at` lama tetap dipertahankan. Native memakai occurred_at untuk WIB tanpa bergantung zona waktu perangkat; fallback raw legacy diperlakukan sebagai UTC sesuai penyimpanan aplikasi.

Parameter opsional: `page`, `batch_id`, `q` maksimal 100 karakter, `source_type`, `bucket`, serta pasangan `from`/`to` ISO bertimezone. Batch dari toko lain ditolak 404; query scoped pada entry dan batch milik toko yang sama. Jenis/bucket invalid, tanggal tanpa zona, pasangan tidak lengkap atau rentang lebih dari 31 hari ditolak 422. API membaca riwayat produk nonaktif, tanpa harga grosir/eceran atau nama master terkini. Respons private/no-store.

LedgerHistory memiliki read model Inventory; StockLedger tetap satu-satunya jalur perubahan saldo. DTO/repository/controller/page native terpisah, StockMovementCard hanya tampilan reusable. Inventory registry/flag tetap berlaku; tidak ada provider, migrasi atau dependensi baru. Tidak ada ekspor, running balance, perubahan izin finansial, approval, auto-order atau pengiriman pesan kepada pihak lain.

Pengujian mencakup akses lintas tenant/role/assignment, histori nonaktif, snapshot batch, delta bertanda, filter gabungan, batas WIB inklusif/eksklusif, pagination statis, no writes, error/retry/wrong source, navigasi per batch dan widget 390px text scale 1,5/1200px. SQLite/widget/HTTP bukan bukti PostgreSQL concurrency, UAT atau build/perangkat Android/iOS.
