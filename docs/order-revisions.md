# Revisi SKU dan sisa pesanan

DL-14 tersedia melalui API, web dan Flutter. Buka **Revisi SKU dan sisa pesanan** dari detail pesanan atau gudang. Ini workflow `revisions` terpisah dari pengurangan lama `changes` dan amendment sebelum approval. Tujuannya mengubah kebutuhan yang belum dikirim sambil mempertahankan bukti fisik dan harga barang yang sudah disepakati.

Contoh: order20 ayam isi8 sudah dikirim8. Usulan mempertahankan8 ayam dan menambah3 udang memakai quote baru. Order asli tetap20 sampai pihak lawan menyetujui; setelahnya kuota ayam tersisa0 dan udang3. Harga8 ayam mengikuti snapshot lama,3 udang memakai snapshot baru. Receipt dan ledger kiriman8 sebelumnya tetap. Tidak mengubah barang terkirim secara retroaktif; koreksi fisik/keuangan barang itu mengikuti pengganti/retur/kredit terpisah.

## Aturan dan persetujuan

- Grosir pusat→mitra dengan status approved/processing/dispatched/partially_received/completed dapat diusulkan. Supplier owner/admin atau recipient partner_lead mengusulkan; pihak organisasi lawan menyetujui/menolak, pihak pengusul menarik. Izin bukan syarat dua orang berbeda; pengguna multi-membership diperiksa terhadap peran pihak yang dibutuhkan. Gudang, kepala toko, kasir dan tenant lain tidak dapat memutuskan.
- Setiap baris existing wajib tepat sekali, jumlah antara seluruh kemasan asli yang sudah dikirim dan jumlah lama.0 hanya untuk baris belum dikirim. Kenaikan dan substitusi menjadi baris baru dengan quote baru. SKU/isi/condition/harga/ID baris lama tidak ditimpa atau dihapus; tombstone0 menjaga referensi historis.
- Tambahan memakai SKU pusat dan SKU lokal aktif dengan isi/condition cocok, supplier sama, jumlah1–10000. Pemetaan lokal baru diusulkan pimpinan mitra. Pusat hanya dapat menggunakan mapping lokal yang sudah ada pada order, tanpa membuka katalog operasional mitra lain.
- Maks100 baris total dan minimal satu barang dipertahankan/ditambahkan. Menambah SKU yang sama sebagai lot quote baru diperbolehkan, dengan ID dan harga snapshot tersendiri. Unique SKU lama di tabel line diganti validasi transaksi create/amend existing; request order baru tetap mewajibkan SKU distinct.
- Proposal baru menggunakan nullable unique pending_order_id yang sama dengan pengurangan lama. Selama pending, process/dispatch/receipt/cancel ditahan pada parent lock. Reject/withdraw mempertahankan order semula dan melepas hold. Approval membutuhkan target dan supplier aktif.

## Review, histori dan integritas

GET context mencakup floor jumlah yang dikirim, snapshot asli, katalog pilihan scoped dan history50. POST preview read-only mengembalikan before/after/harga server serta review_hash; hash mengabaikan ID baru sementara dan mengikat order/version/pihak/alasan/quote. Web dan Flutter menampilkan review ini sebelum POST proposal. Harga yang berubah di antara review dan submit menghasilkan409. Hash adalah guard review, bukan kredensial/otorisasi; direct API dapat menerima quote server tanpa hash untuk kompatibilitas pola existing.

Approval mengunci parent→change, memastikan snapshot belum berubah, mengubah jumlah lama dan menambah baris quote baru, menghitung ulang total/status pemenuhan serta mencatat approval/versi baru. Snapshot kiriman/receipt, batch/ledger/revision stok, kas dan invoice tidak ditulis. `completed` hanya ketika good receipt semua baris sudah sesuai; kekurangan fisik awal tetap terlihat. Audit kedua organisasi menyimpan before/after/reason/decision. Event tidak memperluas allowlist onboarding.

Operasi memakai UUID dan actor/scope/payload, termasuk keputusan historis yang sudah commit. Native memakai review immutable, scoped ACK, local pagination dan unknown retry existing; record kind membedakan revision/reduction dan quote keputusan menampilkan baris baru juga. Web membekukan candidate review di session, mengonfirmasi salinan server, menyimpan pending sebelum POST dan memeriksa ACK parent/version/revision/status. Unknown/408/429/5xx/malformed ACK menahan body/UUID melewati denial berikutnya; login/logout/401 membersihkan akun lama. Replay bukan state terkini, sehingga context dibaca kembali. Bukan offline atau recovery lintas restart.

Migrasi tambahan kind menjaga semua history lama sebagai reduction; legacy decision endpoint menolak revision ID dan legacy history tidak menerima format baru. Legacy UI dapat membaca tombstone0, tetapi composer pengurangan lama dinonaktifkan pada order demikian. Rollback ditolak setelah revision dipakai. API/web/Flutter regresi dan HTTP terisolasi diuji; PostgreSQL concurrency, perangkat nyata dan UAT belum. Lihat [verifikasi](verification.md).
