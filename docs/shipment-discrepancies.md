# Kasus selisih penerimaan dan rencana penyelesaian

API dan web menyediakan pencatatan kasus dari kiriman yang penerimaannya sudah dikonfirmasi dengan barang rusak atau kurang. Dari detail pesanan, buka **Kasus selisih kiriman** pada kiriman terkait. Ini irisan PRD09-PROB: pencatatan, komunikasi dan kesepakatan rencana. Eksekusi retur fisik, pengiriman pengganti dan pembukuan kredit/invoice belum tersedia; layar Flutter khusus kasus belum dibuat.

## Otoritas yang disepakati

Keputusan pengguna di chat tanggal 2 November 2026: **“Owner/admin pusat, lalu dikonfirmasi pimpinan mitra.”** D-11 menetapkan dua tahap untuk rencana penyelesaian grosir pusat→mitra. Owner/admin organisasi pusat pemasok mengusulkan tipe retur, pengganti atau kredit beserta uraian; hanya `partner_lead` organisasi penerima dapat mengonfirmasi atau menolak. Pemasok dapat menarik usulannya. Keputusan ini tidak menentukan nominal kredit, perpajakan, biaya kirim, disposisi barang karantina atau penanggung kerugian (D-01 tetap terbuka).

Pelapor mengikuti hak penerima pesanan existing; petugas berwenang di kedua pihak dapat menambahkan catatan yang dibaca bersama. Gudang sumber harus memiliki assignment yang sesuai dan tidak dapat mengusulkan/menyetujui rencana. Kasir, organisasi lain dan admin pusat yang hanya memiliki akses onboarding ditolak. Role di API tetap otoritas; flag di UI hanya membantu tampilan. Tidak ada syarat orang berbeda yang ditambahkan ke keputusan dua peran ini.

Riwayat toko nonaktif tetap dapat dibaca/diberi catatan oleh pengguna berwenang dalam organisasi aktif, tanpa membuka transaksi stok baru. Proposal membutuhkan organisasi penerima aktif; konfirmasi membutuhkan pemasok aktif. Penolakan/penarikan tetap dapat melepaskan usulan ketika pihak lain nonaktif, dengan membership aktif pelaku. Transfer internal dapat mencatat kasus/catatan, tetapi seluruh aksi rencana dinonaktifkan sampai aturan persetujuannya disepakati.

## Snapshot dan state

Satu kiriman mempunyai maksimal satu kasus. Snapshot server membekukan penerima/waktu/catatan serta tiap baris: ID baris pesanan/kiriman/batch sumber, SKU lokal dan sumber, batch, tanggal kemasan, isi kemasan, kemasan dikirim/baik/rusak/kurang dan biji rusak/kurang. Harga dan saldo stok tidak disertakan. Angka dari browser diabaikan; snapshot memakai penerimaan dan metadata historis, bukan master terbaru.

Contoh: pesanan20, baru dikirim8, diterima6 baik dan1 rusak berarti kurang1, masalah2 kemasan. Sisa12 yang belum dikirim bukan barang hilang. Penerimaan belum selesai atau seluruhnya baik tidak dapat dilaporkan sebagai kasus.

| Operasi | State kasus sesudah operasi | Dampak rencana |
| --- | --- | --- |
| Laporkan selisih | `reported`, versi1 | Belum ada rencana |
| Pusat mengusulkan | `awaiting_confirmation` | Satu `pending` |
| Pimpinan mitra mengonfirmasi | `plan_confirmed` | `confirmed`, kesepakatan administratif |
| Mitra menolak / pusat menarik | `reported` | `rejected` / `withdrawn`, riwayat dipertahankan |
| Tambahkan catatan | State sama, versi naik | Rencana tetap; review keputusan lama menjadi stale |

Sesudah `plan_confirmed`, catatan tetap dapat ditambah; workflow ini tidak mengganti rencana yang sudah disepakati atau menandai eksekusi selesai. Catatan dan usulan memiliki referensi teks opsional (maks500 karakter), bukan upload lampiran. Isi catatan dibagikan kedua pihak; hindari menulis data POS atau rahasia yang tidak perlu dibagikan.

## Integritas, retry dan audit

Semua mutasi mengunci pesanan induk→kiriman→kasus→rencana di transaksi yang sama. Nullable unique `pending_case_id` menjaga satu usulan aktif. Pelaporan memerlukan `order_version`; sesudahnya mutasi memakai versi kasus independen. Setiap catatan/usulan/keputusan menaikkan versi kasus sekali. Tidak ada perubahan versi/status/jumlah pesanan, shipment, stok/revision/ledger, kas, invoice atau kuota pengganti. Barang rusak tetap pada karantina yang sudah dicatat saat receipt; persetujuan rencana tidak memindahkannya.

UUID operasi disimpan dengan actor/scope/body dan hasil transaksi. Retry identik memberikan hasil historis tanpa mengulang mutasi; UUID sama untuk payload lain ditolak409. Versi stale juga409. API GET menampilkan notes50 per `page`, riwayat rencana tertutup50 per `resolutions_page`, dan rencana pending terpisah agar selalu terlihat.

Web hanya memakai Backend HTTP dengan session token server dan CSRF. Context memverifikasi parent/kasus/riwayat/pagination, membuat link lokal dan meng-escape teks. Sebelum POST, capability dan versi diperiksa ulang. UUID/path/body/review dibekukan di session per kiriman sebelum request. Timeout,408/429,5xx atau ACK tidak cocok menahan operasi untuk retry identik, termasuk sesudah penolakan berikutnya. GET pending tidak mengganti review dari API; browser tidak dapat mengganti payload retry. Parent lain diarahkan kembali ke parent asli. ACK harus sesuai parent/kasus/versi+1 dan data aksi; sesudah ACK, baca context baru.401/logout/login akun baru membersihkan pending. Ini bukan antrean offline atau pemulihan lintas session/restart.

Event `shipment.discrepancy_reported`, `_noted`, `_resolution_proposed`, `_resolution_confirmed`, `_resolution_rejected`, `_resolution_withdrawn` dicatat pada kedua organisasi; transfer internal hanya satu salinan. Event operasional ini tidak masuk allowlist onboarding pusat. Riwayat actor/reason/reference dan snapshot tetap tersedia, tidak ditimpa master katalog.

Migrasi tambahan `2026_11_02_000008_create_shipment_discrepancies.php` membuat kasus, catatan dan rencana tanpa reset data. Rollback ditolak setelah digunakan. SQLite development dan tes HTTP telah diverifikasi; PostgreSQL concurrency, perangkat nyata dan UAT belum. Lihat [bukti pengujian](verification.md).

## Kontrak dan batas berikutnya

OpenAPI0.24.0 menambah GET/POST `/api/v1/orders/{order}/shipments/{shipment}/discrepancy`, POST `/notes`, POST `/resolutions` dan POST `/resolutions/{resolution}/confirm|reject|withdraw`. Semua respons private/no-store. API tidak menerima nominal, kuantitas retur atau instruksi pemindahan stok.

Eksekusi berikutnya perlu aturan fisik retur/pengganti, sumber/tujuan/batch dan disposisi karantina, jumlah yang dapat dieksekusi bertahap, penanggung ongkos, invoice/credit ledger dan pembatalan/revisi rencana terkonfirmasi. Approval transfer internal, lampiran file dan UI Flutter juga belum diimplementasikan. Kesepakatan rencana saat ini tidak dianggap bukti pengiriman pengganti, refund, penerimaan retur atau pelunasan.
