# GL hak bagi hasil dan pendanaan sponsor

ADR056 menghubungkan **hak kontraktual** pada CommercialJournal ke buku besar otomatis. Perjanjian dan budget dua pihak tetap menjadi sumber nominal; policy GL adalah persetujuan finance per organisasi, bukan izin melihat buku pihak lain. Pembayaran tetap pencatatan bukti manual, bukan transfer bank otomatis.

## Aktivasi eksplisit

Pada **Pemetaan jurnal otomatis → Ajukan integrasi GL bagi hasil dan sponsor**, pilih baseline aktif/versi terkini,10akun fungsi tersendiri, basis pengakuan `contractual_rights`, dan perlakuan pajak masukan bagi hasil: recoverable atau expense. Pengelola berbeda menyetujui. Akun harus aktif, berjenis/saldo normal sesuai fungsi, milik organisasi sendiri, dan berbeda dari fungsi POS/wholesale lain. Tidak ada COA/pajak/tingkat bagi hasil bawaan.

Extension mempertahankan mapping dasar dan snapshot wholesale yang sudah ada. Proposal stale ditolak. Proposal dasar/wholesale tidak boleh diam-diam menghilangkan integrasi komersial aktif; untuk mengganti cakupan, hentikan policy secara eksplisit terlebih dahulu. Proposal komersial baru dapat mengganti akun komersial sambil membawa fungsi lama. Koreksi posisi lama tetap terikat policy asal walaupun sudah superseded/suspended; aktivasi bersifat prospektif.

## Jurnal sumber

| Sumber | Pemasok/sponsor | Mitra/penerbit |
| --- | --- | --- |
| Hak bagi hasil positif | Dr share_receivable / Cr share_income untuk bagian sebelum pajak; Dr share_receivable / Cr share_output_tax untuk pajak eksplisit | Dr share_expense / Cr share_payable, ditambah Dr share_input_tax atau share_expense menurut policy / Cr share_payable |
| Refund/koreksi hak | Selisih signed dari posisi kumulatif, memakai akun policy asal | Selisih signed dengan arah lawan |
| Klaim sponsor | Dr sponsor_expense / Cr sponsor_payable | Dr sponsor_receivable / Cr sponsor_recovery |
| Koreksi refund sponsor | Mengurangi biaya/utang sesuai delta klaim asli | Mengurangi piutang/pemulihan sponsor sesuai delta |
| Collection bagi hasil | Dr kas/bank / Cr share_receivable | Dr share_payable / Cr kas/bank |
| Payout sponsor | Dr sponsor_payable / Cr kas/bank | Dr kas/bank / Cr sponsor_receivable |

Nominal negatif membalik debit/kredit yang sama; ini termasuk credit balance hasil koreksi atau kerugian yang secara eksplisit dibagi dalam perjanjian. Kas/bank dipilih dari metode pembayaran yang diverifikasi, tidak mengubah cash drawer POS. Pajak di sini hanya pajak yang disepakati perjanjian; pemilihan akun bukan keputusan sah tentang kewajiban fiskal.

Setiap delta CommercialJournal mempunyai ID sumber sendiri. Posisi, delta, binding policy dan outbox dicatat dalam transaksi yang sama. Worker accounting:post memakai uniqueness sumber/journal dan periode asal. Kegagalan outbox membatalkan proyeksi, bukan membiarkan saldo komersial tanpa jurnal. Replay/no-op tidak menambah biaya atau jurnal.

Akrual pertama memakai tanggal WIB penjualan, bukan tanggal worker. Koreksi memakai tanggal refund sumber; bila akumulasi pertama sudah berisi refund atau satu delta menggabungkan beberapa tanggal/versi yang tidak terbukti, antrean review dibuat dan tanggal tidak digeser diam-diam. Posisi sebelum aktivasi tidak diimpor. Pembayaran atas posisi sebelum aktivasi/policy campuran atau sumber direkonsiliasi manual juga masuk review. Periode sumber tertutup memakai reopen/retry jurnal sumber yang sudah tersedia.

## POS, costing dan batas ownership

POS barang putus beli dengan sponsor yang telah memiliki extension GL dapat membukukan penjualan, diskon penuh dan HPP batch; recovery sponsor dibukukan terpisah dari diskon, sehingga pendanaan tidak dihitung dua kali. Refund mempertahankan policy POS asal. Klaim sponsor/pembayaran tidak menambah omzet tunai atau memasukkan penerimaan B2B ke laporan kasir.

GL hak bagi hasil **tidak** mengubah biaya formula dalam kontrak menjadi biaya persediaan aktual. Kepemilikan stok konsinyasi tetap consignor; HPP fisik consignor, stok perjalanan, retur/pengganti serta atribusi multi-receipt memerlukan jejak ownership/biaya terperinci. Event POS konsinyasi tetap review bila biaya/kepemilikannya belum dapat dibuktikan. Jangan menandai GL keseluruhan lengkap hanya karena akrual share sudah posted. Laporan dan closing memperlihatkan/menghalangi antrean sumber tersebut. Tidak ada pengakuan gross/net principal-agent otomatis atau akses penuh pusat ke POS mitra.

Pembaruan ADR061,11November2026: [valuasi fisik consignor](consignor-inventory.md) kini melacak aset titipan/perjalanan, HPP penjualan, refund fisik, retur dan susut reviewed dari biaya aktual supplier. Pernyataan belum otomatis di atas digantikan untuk bukti prospektif pada cakupan tersebut. GL hak formula kontrak tetap terpisah; review gross/net revenue principal-agent penerima tidak ditutup hanya karena HPP supplier sudah tersedia.
