# Integrasi keuangan wholesale — ADR053

Lanjutan paket1,9 November2026. Menghubungkan invoice wholesale, penerimaan/pembayaran terverifikasi, pembalikan dan nota kredit ke event GL ADR052. Tidak mengubah harga, persetujuan dua pihak, batas kredit, stok fisik, laci POS atau kewenangan partner yang sudah ada.

## Mengaktifkan melalui web atau Flutter

1. Buka **Pembukuan dan jurnal** → **Pemetaan jurnal otomatis** pada organisasi sendiri. COA, periode dan pemetaan dasar ADR052 harus sudah dibuat serta disetujui pengelola lain.
2. Buat10akun tambahan dengan kelompok/saldo normal yang sesuai, lalu **Ajukan integrasi jurnal wholesale**. Pilih piutang, pendapatan grosir, diskon, retur/kredit, pendapatan ongkir, pajak keluaran, utang dagang, penampung pembelian, pajak masukan dan beban ongkir. Akun antar fungsi berbeda; sistem tidak memilih nomor/nama/default chart.
3. Pilih sendiri apakah pajak pembelian dapat dikreditkan atau masuk penampung biaya; pilih ongkir sebagai beban periode atau penampung biaya pembelian. Ini keputusan yang harus ditinjau organisasi berdasarkan bukti/kewenangannya. Tidak mengasumsikan status PKP, tarif atau hak kredit pajak dari jenis organisasi/invoice.
4. Pengelola lain meninjau seluruh snapshot akun dasar/tambahan, perlakuan pajak/ongkir serta bukti. Baseline policy ID/versi harus masih aktif. Konfigurasi baru berlaku sesudah disetujui. Pusat dan mitra mengaktifkan buku masing-masing secara terpisah; izin pusat untuk onboarding tidak memberikan akses ke buku mitra.
5. Terima invoice/kredit atau verifikasi pembayaran melalui workflow Billing biasa. **Antrean jurnal sumber** dan **Neraca saldo** menampilkan hasil worker `accounting:post`. Scheduler Compose menjalankannya tiap menit. Status transaksi usaha selesai dan jurnal posted adalah keadaan yang berbeda; periode/pemetaan kasus diperiksa di antrean.

Owner/admin pusat nyata dan pimpinan mitra nyata pada organisasi sendiri dapat mengajukan/meninjau dengan pemisahan pembuat-reviewer. Operator, warehouse, cashier dan pihak lain tidak mendapat hak chart/policy. Web tetap memakai Backend HTTP dan Flutter tetap form/repository native dengan UUID/path/body/actor/version dibekukan pada unknown; tidak ada WebView/akses DB API dari web.

## Jurnal keuangan yang dihasilkan

| Sumber terkonfirmasi | Buku penjual | Buku pembeli |
| --- | --- | --- |
| Invoice putus beli diterima | Dr piutang total, Dr diskon; Cr pendapatan bruto, ongkir dan pajak keluaran sesuai snapshot invoice | Dr penampung pembelian neto, pajak masukan/penampung sesuai pilihan, ongkir beban/penampung sesuai pilihan; Cr utang dagang total |
| Pembayaran collection terverifikasi | Dr kas/bank, Cr piutang | Dr utang dagang, Cr kas/bank |
| Pembalikan pembayaran terverifikasi | Lawan persis terms pembayaran asal | Lawan persis terms pembayaran asal |
| Nota kredit diterima pembeli | Dr retur/kredit dan pajak keluaran, Cr piutang | Dr utang dagang, Cr penampung pembelian dan pajak masukan/penampung sesuai kebijakan invoice asal |
| Pengembalian dana kredit terverifikasi | Dr piutang, Cr kas/bank | Dr kas/bank, Cr utang dagang |

Saldo debit/kredit AR/AP boleh beralih arah akibat kredit sesudah lunas; saldo finansial Billing tetap memisahkan outstanding dan customer credit seperti sebelumnya. Refund baru dicatat setelah manusia memverifikasi dana aktual. `qris_manual` dan transfer memakai akun bank/non-tunai yang dipilih; ini bukan panggilan provider/bank atau penambahan laci kasir.

Invoice diakui pada tanggal WIB persetujuan, bukan tanggal proposal atau jatuh tempo. Payment memakai `paid_on` eksplisit, reversal memakai tanggal WIB `reversed_at`, kredit memakai tanggal WIB persetujuannya. Worker tidak memindahkan tanggal agar cocok dengan periode terbuka. Nominal pajak/ongkir berasal invoice yang sudah disepakati, bukan kalkulasi aturan pajak baru.

## Pemetaan asal, replay dan kegagalan

- `wholesale_policy` menambah snapshot pemetaan dan metadata immutable pada migrasi28. Pemetaan dasar tidak diubah diam-diam; proposal membawa ID/versinya. Approval oleh pembuat sendiri/stale base/foreign account ditolak. Tidak ada default opt-in bagi konfigurasi ADR052 lama.
- Source types: `wholesale_invoice`, `wholesale_payment`, `wholesale_payment_reverse`, `wholesale_credit`. Unique source/org dan operation receipt mencegah duplikasi; event kedua organisasi dibuat dalam transaksi yang sama dengan perubahan dokumen. Bila salah satu insert gagal, persetujuan/pembayaran dan semua event dibatalkan. Worker membukukan buku tiap organisasi sendiri; periode yang bermasalah pada satu pihak tidak membuka atau mengubah buku pihak lain.
- Invoice dan koreksinya memakai policy asal. Perubahan pajak/ongkir/mapping baru tidak mengubah dokumen lama. Menghentikan policy menghentikan invoice baru; pelunasan/kredit/reversal invoice yang sudah tertangkap tetap dicatat dengan otorisasi dan snapshot asal. Event pending yang sudah diotorisasi tetap diproses.
- Penggantian pemetaan dasar biasa tidak dapat mematikan wholesale aktif tanpa tindakan suspend eksplisit. Form dasar disembunyikan pada konfigurasi wholesale aktif; proposal wholesale berikutnya menyalin akun dasar yang sudah disetujui. Untuk mengganti akun dasar, hentikan policy, ajukan/review dasar baru, lalu ajukan/review ekstensi wholesale baru. Obligasi invoice lama tetap terhubung ke asalnya.
- Dokumen invoice lama tanpa event, pembayaran asal yang tidak tertangkap, sumber yang direkonsiliasi manual dan invoice konsinyasi masuk `review_required`, bukan diimpor/dianggap putus beli. Reversal pembayaran dengan jurnal asal yang direkonsiliasi manual juga perlu review. Periode tertutup tetap memakai retry periode asal; kasus ekonomi perlu jurnal manual reviewed dan penautan ADR052, tidak bisa dilewati dengan retry biasa.

## Batas persediaan dan cakupan

Ini menyelesaikan integrasi **keuangan Billing buyout** yang sebelumnya manual pada paket1. Tidak menilai persediaan pembeli dari harga invoice secara diam-diam: penerimaan fisik dapat terjadi sebelum invoice dan barang mungkin sudah dijual. Penampung pembelian adalah akun sementara yang wajib direkonsiliasi ke persediaan/biaya yang benar dengan bukti. Jangan menduplikasi nilai opening batch dan jurnal pembelian.

Perpindahan nilai produk lintas batch/organisasi, HPP distribusi penjual dan penilaian batch pembeli otomatis, kepemilikan konsinyasi, accrual settlement/komisi, serta piutang sponsor promo ke GL masih memerlukan rekonsiliasi manual. Modul commercial settlement/sponsor yang sudah tersedia tetap bekerja pada perjanjiannya; tidak dimatikan atau diposting dua kali sebagai invoice buyout. Tidak mengimplementasikan push, payment gateway, auto recall lintas organisasi atau payout bank yang telah dilewati pengguna.

Tidak ada account policy usaha nyata yang diaktifkan oleh migrasi/pengujian. Verifikasi SQLite development/HTTP/widget bukan audit akuntan, uji PostgreSQL concurrency, perangkat/printer fisik atau UAT bisnis.

## Pengujian

`WholesaleAccountingTest` memeriksa kedua buku, angka net/gross/pajak/ongkir, tax/freight eksplisit, payment/reversal/refund, nota kredit dua pihak, source replay, atomic rollback kedua event, pending/rejected/disabled policy, original mapping sesudah suspend/replace, original period, cutover, role/tenant denial, stale base/foreign account dan konsinyasi review. Tes web/native menambah form review dengan base ID/versi/tax/freight yang tetap frozen; tampilan390/1200px. Smoke HTTP terisolasi menjalankan konfigurasi source/buyer via akun pengelola dan reviewer berbeda, shipment aktual, invoice/collection/reversal/credit serta worker nyata10postings sekali, BFF keuangan kedua pihak dan source tenant denial.

Lanjutan jejak distribusi: bagian **Biaya historis distribusi** (`section=distribution_costs`) kini menampilkan pergerakan shipment/replacement/return dispatch dari cost ledger organisasi sendiri, dengan pagination30, batch/revisi/sumber, jumlah dan nilai historis atau unknown. Reader finance-only ini tersedia melalui API/BFF/native dan tidak memposting jurnal atau mengubah nilai stok pembeli. Ini fondasi penelusuran, bukan penyelesaian otomatisasi HPP distribusi. Verifikasi perubahan:23tes API/1011assertions dan6tes web/46assertions lulus; Flutter analyze bersih. OpenAPI0.33.1 menambahkan section tersebut.
