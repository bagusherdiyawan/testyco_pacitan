# Valuasi pengganti, barang hilang dan retur — ADR060

Keputusan pengguna10November2026: biaya pembelian dibagi pada barang yang masih dimiliki pembeli termasuk karantina; biaya fisik pengganti ditanggung pemasok. Beban barang hilang ditentukan per kasus oleh pusat lalu dikonfirmasi pimpinan mitra. Retur kembali menjadi milik pemasok setelah fisik diterima dan nota kredit disetujui. Tidak mengaktifkan push, gateway, bank payout atau automatic cross-organization recall hold.

## Menggunakan melalui web dan Flutter

Aktifkan pemetaan akun prospektif yang telah disetujui pengelola berbeda pada organisasi masing-masing. Buku wholesale, kepemilikan receipt dan invoice final mengikuti ADR053–059. Buka **Pembukuan dan jurnal → Biaya pengganti, hilang dan retur**. Operator/gudang/kasir tidak memperoleh hak pembukuan; admin onboarding pusat tidak dapat membaca buku mitra. Kesepakatan biaya hilang hanya membagikan data kasus/nominal yang diperlukan kepada kedua pihak, bukan membuka buku umum pihak lain.

Antrean biaya dan jurnal adalah dua status terpisah. Scheduler menjalankan `accounting:revalue` untuk invoice serta costing distribusi, kemudian `accounting:post` untuk GL. Hasil unknown di web/Flutter mempertahankan UUID/path/body/actor/version dan persetujuan yang sama. Jangan membuat operasi pengganti. Retry hanya mengantrekan ulang sumber server; tidak menerima harga, saldo, jumlah fisik atau penanggung biaya baru dari client.

## Pengganti tanpa tagihan tambahan

Penerimaan baik/karantina pengganti dari buyout terbukti mempunyai tambahan biaya pembelian nol. Nol ini dibuktikan oleh receipt ownership asal, bukan dugaan terhadap biaya unknown. Setelah invoice final asal selesai direkonsiliasi, sisa nilai persediaan dibagi ke jumlah barang yang masih dimiliki, termasuk karantina, dalam batch receipt asal dan penggantinya. Seluruh rupiah terkonservasi dengan alokasi kumulatif integer; satu bukti monetary StockLedger per batch/job menaikkan revision tanpa memalsukan pergerakan fisik.

Contoh pembelian Rp200000,40biji sudah terjual dengan HPP Rp80000. Sisa60biji bernilai Rp120000. Setelah20biji pengganti diterima, total80biji tetap bernilai Rp120000: batch asal60/Rp90000 dan pengganti20/Rp30000. HPP lama tidak dikapitalisasi lagi dan tetap Rp80000. Refund berikutnya memakai biaya historis penjualan asal; penjualan baru menggunakan pool setelah pembagian. Susut yang telah dibukukan juga tidak dihapus. Barang rusak awal tetap karantina sampai disposisi fisik terpisah.

Pemasok mencatat beban pengganti pada akun loss yang telah dipetakan, lawan persediaan, menggunakan biaya dispatch asli hanya untuk bagian yang benar-benar diterima. Bagian pengganti yang hilang tetap residual review dan memerlukan persetujuan per kasus. Tidak membuat invoice, penerimaan kas atau HPP penjualan baru untuk pengganti. Konsinyasi/ownership tanpa bukti tidak otomatis diperlakukan sebagai buyout.

Jika pengganti tiba sebelum invoice final, biaya dibekukan sebagai antrean menunggu invoice. Invoice final akan membangunkan antrean terkait. Stok terkait ditahan dari penjualan/dispatch/deplesi selama pembagian biaya belum selesai agar provisional nol tidak menghasilkan HPP nol yang salah. Transfer atau pool bercampur order lain memerlukan alokasi lineage terperinci; retry tidak menerobosnya.

## Barang hilang: persetujuan biaya per kasus

Penerimaan aktual menyiapkan residual dari immutable dispatch cost: biaya kirim asal dikurangi floor(biaya asal × biji diterima / biji dikirim). Ini tersedia untuk kiriman grosir, pengganti dan retur buyout terbukti. Tidak menambah stok tujuan atau menganggap barang hilang telah diterima. Biaya unknown tidak diberi nominal tebakan; residual terbukti0 tidak membuat jurnal nominal0.

1. Laporkan kasus selisih operasional melalui alur biasa.
2. Owner/admin pusat membuka **Usulkan pembagian biaya hilang**, mengisi porsi pemilik barang saat dikirim (0–10000 basis point) dan bukti. Untuk kiriman awal/pengganti pemilik saat dikirim adalah pemasok; untuk retur yang belum beralih kepemilikan adalah pembeli. Nama pemilik ditampilkan pada form. Tidak ada porsi bawaan.
3. Pimpinan mitra meninjau nominal/porsi, menyatakan persetujuan dan menyetujui atau menolak. Pusat tidak dapat menyetujui proposalnya sendiri melalui scope pusat. Proposal ditolak dapat diajukan ulang dengan versi terbaru.
4. Sistem menghitung beban pemilik dengan floor; sisa menjadi klaim ke pihak lain. Contoh residual Rp10001 dan porsi5000: beban pemilik Rp5000, klaim Rp5001. Kedua buku mencatat klaim yang sama, masing-masing piutang dan utang. Pemetaan wholesale kedua pihak wajib untuk klaim; akun beban/persediaan memakai snapshot approved yang sesuai sumber.

Jurnal pemilik: Dr loss untuk porsi sendiri, Dr trade_receivable untuk klaim, Cr finished untuk seluruh residual. Buku pihak lain: Dr loss / Cr trade_payable sebesar klaim. Ini pengakuan beban dan hak/kewajiban; **bukan bukti uang sudah dibayar** dan tidak mengirim dana. Pelunasan bank tetap melalui bukti pembayaran/rekonsiliasi keuangan yang ditinjau. Source review baru ditutup setelah bagian received dan missing sama-sama tercakup. Periode tertutup, sumber sudah direkonsiliasi manual atau akun tidak cocok menolak pembebanan ganda.

## Retur: titipan dahulu, kepemilikan setelah kredit

Dispatch retur mengambil biaya pool pembeli secara aktual dan menyimpan bukti nilai barang dalam perjalanan. Buku pembeli masih memiliki aset tersebut sampai syarat perpindahan terpenuhi. Gudang pemasok menerima fisik ke karantina sebagai **titipan**: jumlah ini dikecualikan dari quantity dan nilai pool milik pemasok, tidak mencairkan biaya rata-rata stok lain dan tidak dapat dipakai untuk susut/disposisi milik sendiri. Pembacaan valuasi membedakan biji milik organisasi dengan biji titipan.

Setelah kredit yang diterima mitra mencakup barang retur tersebut, worker memindahkan ownership dan nilai secara atomik. Kredit parsial yang belum mencakup kuantitas retur tidak ditafsirkan sepihak sebagai pengalihan semua barang; barang tetap titipan sampai cakupannya terpenuhi. Histori kredit/physical return tetap terpisah dan tidak ditimpa. Kredit maupun biaya tidak mengembalikan kuota kirim barang secara otomatis.

Pemasok memulihkan **biaya dispatch asli**, bukan harga jual, ke persediaan karantina dengan lawan COGS. Pembeli mengeluarkan nilai aktual barang dari aset dalam perjalanan melalui purchase clearing. Selisih antara biaya barang dan kredit biaya yang disetujui (termasuk pajak nonrecoverable sesuai policy asal) diakui sebagai loss bertanda, sehingga clearing tidak tersisa tanpa penjelasan. Kredit dasar/pajak di kedua buku tetap melalui BillingPosting yang sudah ada. Alokasi kredit antarbaris memakai bobot harga historis; pembulatan kumulatif mempertahankan total.

Contoh retur20biji: biaya pembeli Rp40000, kredit Rp40000, biaya asal pemasok Rp20000. Sebelum kredit, pemasok memegang20biji titipan tanpa aset tambahan. Sesudah persetujuan, pemasok mempunyai20biji karantina/Rp20000, membalik COGS Rp20000; pembeli mengeluarkan aset Rp40000. Jika retur hanya diterima18biji, bagian diterima dan bagian hilang dihitung terpisah; pembeli tidak kehilangan seluruh aset dua kali. Retur sama sekali tidak diterima tidak membentuk stok pemasok dan menunggu persetujuan beban hilang.

## Kekurangan kiriman dan kredit invoice

Untuk kasus **murni kekurangan tanpa barang rusak**, kredit disetujui yang mencakup kekurangan dapat merekonsiliasi invoice basis dispatched ke kuantitas aktual. Nominal invoice lama tidak diubah. Bobot alokasi mengikuti kuantitas yang tersisa setelah kredit; kiriman yang seluruhnya hilang tidak mengambil biaya dari kiriman lain. Biaya final dikurangi kredit dan dialokasikan hanya kepada barang yang benar-benar diterima; freight/pajak tetap mengikuti policy asal. Koreksi baru efektif paling awal pada tanggal persetujuan kredit. Jika kasus sekaligus rusak+kurang tidak menyediakan cakupan unit yang tidak ambigu, antrean tetap review; nominal kredit saja tidak dipakai untuk menebak alokasi fisik. Biaya tersisa tanpa satu pun barang diterima harus diselesaikan sebagai tagihan/beban berbukti, bukan dibuat menjadi aset fiktif.

## Bukti, batas dan aktivasi

Migrasi36 menambah antrean distribusi, immutable monetary proofs, bukti retur/titipan dan persetujuan biaya hilang. Tidak melakukan backfill nilai/ownership lama atau reset DB. Perubahan persediaan hanya melalui StockLedger; ownership quantity dan monetary delta dibuktikan bersama revision. Savepoint planning mencegah sebagian batch berubah jika prasyarat berikutnya gagal. Penerimaan fisik, source outbox, bukti dan UUID tetap satu transaksi; persistence failure membatalkan semuanya. Period close menolak antrean dan kesepakatan yang belum selesai.

Otomatisasi berlaku pada sumber buyout prospektif yang terbukti. Unknown, cutover, sumber manual-reconciled, mapping berbeda, konsinyasi fisik dan lineage campuran/transfer yang tidak dapat dibuktikan tetap review; tidak diselesaikan dengan angka buatan. Invoice final sesudah biaya pengganti telah dibagi atau retur tanpa invoice/ownership proof memerlukan pemeriksaan lineage. Biaya physical consignor masih di luar otomatisasi ini. PostgreSQL concurrency, perangkat dan UAT tetap release gates.
