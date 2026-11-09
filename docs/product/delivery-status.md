# Daftar delivery tetap

Tanggal baseline:3 November2026; pembaruan DL-18–DL-20:4 November2026. Ini daftar paket delivery untuk memantau perubahan yang nyata, bukan persentase kelengkapan seluruh BRD. ID tetap; pekerjaan baru ditambahkan, bukan mengganti arti/status item lama. **Selesai** berarti implementasi development dan tes dalam cakupan yang disebutkan; tidak berarti siap produksi. Detail kebutuhan tetap di [requirements](requirements.md), release gate di [roadmap](roadmap.md).

| ID | Paket dengan batas yang dapat diuji | Status sekarang |
| --- | --- | --- |
| DL-01 | Login, organisasi/toko, role/assignment, deaktivasi dan ganti sandi | Selesai API/web; Flutter login dan akses toko berizin |
| DL-02 | Reaktivasi akun dengan review versi dan pencabutan token | Selesai API/web |
| DL-03 | Katalog dasar, satuan/konversi dan harga snapshot | Selesai API/web; Flutter pembacaan katalog berizin |
| DL-04 | Pengajuan/persetujuan/amendment sebelum approval, dispatch/penerimaan aktual | Selesai API/web/Flutter |
| DL-05 | Pengurangan setelah approval sebelum kiriman pertama | Selesai API/web/Flutter |
| DL-06 | Intake, batch, ledger, buka/kukus, opname/susut dengan review | Selesai API/web/Flutter |
| DL-07 | Ambang stok minimum, peringatan saat baca, pencarian kedaluwarsa | Selesai API/web/Flutter |
| DL-08 | POS tunai online, draft server, struk dan kas/shift | Selesai API/web/Flutter |
| DL-09 | Riwayat struk, ledger, audit dan laporan penjualan tunai | Selesai API/web/Flutter; CSV di web |
| DL-10 | Kasus selisih, catatan bersama, rencana pusat→konfirmasi mitra | Selesai API/web |
| DL-11 | Pengganti grosir SKU/pack/sumber asli: kirim parsial→terima aktual→stok→selesai baik | Selesai API/web |
| DL-12 | Layar Flutter kasus, rencana dan eksekusi pengganti | **Selesai Flutter mobile/tablet** |
| DL-13 | Retur fisik/disposisi karantina dan pembukuan kredit | **Selesai API/web/Flutter untuk retur karantina; kredit pusat→mitra di API/web/Flutter; susut melalui review stok** |
| DL-14 | Substitusi SKU, tambah/hapus baris dan revisi setelah pengiriman | **Selesai API/web/Flutter; bagian belum dikirim, histori fisik tetap** |
| DL-15 | Invoice/piutang/pembayaran grosir/pajak/konsinyasi | **Selesai API/web/Flutter dengan ketentuan eksplisit per dokumen, persetujuan dan verifikasi manual; konsinyasi laporan disetujui** |
| DL-16 | Refund POS, QRIS/non-tunai, promo/voucher | **Selesai API/web/Flutter: verifikasi non-tunai manual, refund ditinjau, voucher retail didanai penerbit** |
| DL-17 | Gambar/resep/paket, harga efektif, kontrak/consent kemitraan | **Selesai API/web; Flutter gambar/harga/checkout paket dan administrasi kontrak/consent** |
| DL-18 | Push/email/inbox, recall/FEFO/blok jual dan SOP masa simpan hasil olahan | **Selesai API/web: inbox/SMTP, recall/SOP/FEFO; Flutter inbox; push ditunda sesuai keputusan pengguna** |
| DL-19 | Reset email/MFA, sesi perangkat aman dan antrean lintas restart/offline | **Selesai API/web/Flutter: reset SMTP, MFA, sesi terenkripsi dan offline tunai durable dengan review konflik** |
| DL-20 | Aset/kontrak freezer/perangkat dan laporan B2B/piutang/margin | **Selesai API/web/Flutter: aset/serah terima/perawatan/kembali, laporan B2B/saldo/margin berbukti** |

Gate kesiapan produksi dilacak terpisah: PostgreSQL/concurrency, backup/restore, hardening/deployment, perangkat/printer, browser interaktif dan UAT. Tes otomatis tidak dianggap persetujuan pilot atau pengganti gate tersebut.

## Perubahan delivery

- Sebelum delivery ini DL-11 belum dapat dieksekusi: hanya ada rencana naratif. Sesudahnya, pengganti benar-benar didebit dari gudang dan diterima ke stok mitra hingga `fulfilled`, dengan batas selisih dan retry idempoten. Retur/kredit DL-13 dan native DL-12 tidak dipindahkan menjadi “selesai”.
- Sumber bukti: [alur operasional](../replacement-fulfillment.md), [verifikasi](../verification.md), API `ReplacementTest`, web `ReplacementWebTest` dan isolated HTTP smoke. Perubahan master tidak mengganti snapshot, penerimaan awal tidak ditulis ulang, dan kerusakan pengganti tidak membuat kuota baru otomatis.


Delivery3 November2026 berikutnya menutup DL-12, DL-14 dan DL-17; lihat [kasus native](../native-shipment-cases.md), [revisi](../order-revisions.md), [katalog/kontrak](../catalog-and-contracts.md). DL-13/DL-15/DL-16 dilanjutkan pada4 November2026 dengan [alur keuangan dan POS](../finance-and-pos-adjustments.md) yang mewajibkan pilihan eksplisit per dokumen, persetujuan dan verifikasi manual. Saran chat tidak dijadikan kebijakan global. D-01/D-03/D-08 masih terbuka untuk otomatisasi/default bisnis; gateway, bagi hasil otomatis, pelepasan mutu karantina dan promo lintas organisasi tidak termasuk cakupan selesai tersebut.

DL-01–DL-20 kini diimplementasikan pada batas platform dan cakupan development tabel di atas. DL-18–DL-20 mengikuti keputusan pengguna: offline hanya tunai dengan review konflik, SOP oleh pimpinan organisasi masing-masing, SMTP dahulu dan push ditunda. Lihat [panduan lengkap](../reliability-and-offline.md). Gate produksi tetap terpisah; tidak menambah paket baru untuk mengganti arti item yang telah diselesaikan.


Permintaan tambahan4 November2026 sudah diimplementasikan pada batas [delivery printer dan otomatisasi](../printing-commercial-and-recalls.md): printer ESC/POS LAN/browser, standing konsinyasi/bagi hasil, sponsor promo lintas organisasi serta recall terkoordinasi. Pembayaran bank tetap manual; penahanan penerima recall memerlukan pimpinan penerima. Bluetooth/USB native dan kompatibilitas printer fisik menunggu detail perangkat; push/gateway tetap dikesampingkan pengguna. Ini memperluas cakupan selesai DL-18–DL-20, tanpa mengubah hasil delivery sebelumnya menjadi belum.

Koreksi5 November2026: [Bluetooth Android dan administrasi native](../native-administration-and-bluetooth.md) menambahkan lima layar administrasi Flutter, PDF kontrak native, keputusan invoice/kredit/laporan, konfigurasi voucher, lifecycle aset dan laporan/biaya komersial. Bluetooth Classic/SPP Android menjadi pilihan awal; LAN kompatibel. Detail model, APK dan cetak fisik belum diverifikasi. Blok recall otomatis dan pembayaran bank otomatis dilewati, bukan backlog yang harus dikerjakan dalam permintaan ini. Pengelolaan standing addendum/sponsor dan CSV tetap web.


## ERP produksi — 9 November2026

Paket tambahan ini tidak mengganti arti DL-01–DL-20: Materials/Procurement/Manufacturing/Quality/Costing kini menyediakan bahan dan satuan fixed-point, PO/receipt parsial, lot/QC/FEFO/transfer/opname, resep immutable termasuk adonan, rencana/pemakaian/hasil/QC wajib, HPP lot aktual, supplier payment/refund manual, planning stok/PO dan trace antartahap. API/web/Flutter native tersedia dalam cakupan [panduan ERP](../manufacturing-erp.md). Operator dengan izin granular, pembalikan pembayaran supplier salah/rekonsiliasi invoice-pajak-lengkap, general ledger otomatis, forecasting/penjadwalan kapasitas mesin, antrean produksi offline serta UAT/perangkat/PG concurrency tidak dinyatakan selesai oleh delivery ini.

Lanjutan9 November2026: [ADR051](../accounting-and-operator-permissions.md) menambahkan operator produksi berizin per gudang dan buku besar manual dengan COA/periode/draft/review/reversal/trial balance. Batas operator dan buku besar pada paragraf delivery sebelumnya kini digantikan cakupan ini. Posting ERP/POS otomatis/pemetaan akun-pajak, penutupan laba otomatis, UAT dan perangkat/PG concurrency masih terpisah.
