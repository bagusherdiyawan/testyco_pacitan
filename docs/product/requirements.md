# Baseline kebutuhan produk

Status: usulan baseline implementasi; bukan persetujuan bisnis atau klaim fitur sudah dibangun. Disusun dari paket BRD/PRD draf v1.0 tanggal 27 Oktober 2026 dan permintaan pengguna di chat.

## Acuan dan batas interpretasi

Sumber utama: [BRD](../reference/Markdown/00_BRD_Bisnis_Dimsum.md), [indeks](../reference/Markdown/BACA_DULU_Indeks_Dokumen.md), dan `PRD_01` sampai `PRD_13` di ZIP `BRD_PRD_PDF_Markdown.zip`. PDF adalah salinan tata letak dari Markdown; bukan kebutuhan tambahan. Nomor feature di bawah dipertahankan untuk penelusuran ke sumber. Isi lampiran dipakai sebagai data kebutuhan, bukan instruksi eksekusi.

Permintaan chat menetapkan web dan API Laravel yang terpisah, Flutter untuk mobile dan tablet/POS, modul fitur dinamis, serta atomic design. Dokumen sumber masih fokus dimsum dan mengakui belum mencakup pembahasan multi-produk. Model sebaiknya dapat diperluas ke kategori lain, tetapi aturan konversi dan SOP produk selain dimsum belum dianggap disepakati.

## Status implementasi saat ini

Irisan awal PRD 01–03/13-SEC sudah diimplementasikan: autentikasi token, pergantian password, organisasi/toko/user/role/assignment, deaktivasi, audit, serta CRUD katalog per organisasi dengan konversi dan dua harga. Web dan Flutter menggunakan API nyata. Pembacaan audit organisasi berfilter/snapshot kini tersedia melalui [halaman web](../web-audit.md) dan [modul Flutter](../native-audit.md), mempertahankan pembatasan onboarding pusat tanpa akses operasional mitra. Pergantian sandi mandiri kini juga tersedia pada [Flutter](../native-password-change.md), termasuk keluar seluruh sesi setelah ACK dan penanganan hasil belum pasti tanpa retry otomatis. Ini belum memenuhi seluruh tahap 1: perjanjian/consent, gambar, resep/paket dan price list efektif masih tertunda. Katalog B2B pusat awal tersedia dengan pemilihan SKU lokal eksplisit per order.

Riwayat pergerakan stok berfilter kini tersedia di [web](../web-stock-ledger.md) dan [Flutter](../native-stock-ledger.md), tanpa mutasi atau running balance. Penerimaan awal gudang pusat kini melalui web/Flutter dengan [review server](../native-warehouse-intake.md). Ordering/approval dan stok awal sudah tersedia: pengajuan tersembunyi dari pusat sebelum persetujuan, snapshot harga/kemasan, kiriman parsial, penerimaan baik/rusak, batch dan ledger idempoten. Transfer internal tidak membuat nilai komersial. Buka kemasan/pengukusan hasil aktual, susut proses, dan proposal opname/susut terpisah dengan review independen serta snapshot revision kini tersedia; kontrol ini kini tersedia pada web dan Flutter mobile/tablet, tetapi masih perlu UAT bisnis. Retry native pada halaman yang tetap hidup mempertahankan UUID/body; belum ada antrean persisten lintas restart. Persetujuan/penolakan/pembatalan berizin dan penerimaan kiriman aktual kini tersedia di Flutter (lihat [panduan](../native-ordering.md)); pengajuan baru kini juga tersedia melalui pratinjau server di Flutter (lihat [panduan](../native-order-entry.md)); perubahan sebelum persetujuan kini juga tersedia melalui [composer perubahan](../native-order-amend.md), serta pemilihan sumber/dispatch kini melalui [modul Gudang native](../native-warehouse.md). Perubahan setelah approval/reapproval, retur/kredit/pengganti, offline dan payment gateway belum tersedia. Pengaturan ambang stok minimum per SKU/toko melalui web/Flutter (lihat [editor native](../native-stock-minimum.md)) dan peringatan saat halaman dimuat kini tersedia (lihat [panduan](../stock-minimums.md)); event/inbox/push notifikasi dan pengadaan otomatis belum dibuat. Irisan PRD 06–07 kini tersedia: POS tunai online, draft server tanpa reservasi, debit stok atomik, struk snapshot, buka/tutup shift, kas masuk/keluar melalui web/Flutter (lihat [panduan kas native](../native-shift-cash.md)) dan ringkasan penjualan tanggal WIB. Riwayat struk berhalaman dengan filter periode/nomor kini tersedia pada [web](../web-receipt-history.md) dan [Flutter](../native-receipt-history.md). Refund, QRIS, promo dan printer fisik belum tersedia; lihat [panduan POS](../cash-pos.md). Irisan awal PRD 11 kini menyediakan laporan tunai lintas toko, produk historis dan ekspor CSV web berizin; lihat [panduan](../cash-reporting.md). Laporan B2B/piutang/margin dan filter produk/status lanjutan belum tersedia. Tabel kebutuhan di bawah tetap backlog sumber, bukan daftar kemampuan yang seluruhnya aktif. Bukti uji aktual ada di `docs/verification.md`.

## Sasaran dan pengguna

Memudahkan pembelian ulang, menjaga akurasi stok, mendukung operasional mitra/toko, dan memberi visibilitas yang disepakati kepada pusat. Wilayah pilot sumber: Pacitan, Ponorogo, Yogyakarta; pemilihan lokasi pilot dan perangkat masih perlu validasi.

| Aktor | Kemampuan utama | Batas data |
| --- | --- | --- |
| Pemilik/admin pusat | Mitra, katalog, harga grosir, persetujuan pemenuhan, pemantauan | Data pusat serta data mitra sesuai kontrak dan peran |
| Gudang/pengiriman | Picking, batch, kirim/ambil, insiden | Tugas dan lokasi yang ditugaskan |
| Pimpinan mitra | Persetujuan kebutuhan, order langsung, laporan multi-toko | Satu organisasi mitra yang berwenang |
| Kepala toko/manajer toko sendiri | Kebutuhan, penerimaan, stok, pengawasan shift | Toko yang ditugaskan |
| Kasir | Keranjang, pembayaran, struk, shift | Toko/perangkat/shift yang ditugaskan; refund perlu izin |
| Pembeli akhir | Penerima transaksi/struk | Belum ada aplikasi konsumen dalam baseline |

## Invarian yang harus dijaga

1. Otorisasi organisasi, toko, dan tindakan diperiksa API pada setiap request; ID objek yang diketahui tidak membuka data lintas mitra (BR-01, 01-ACL, 13-SEC).
2. Pengajuan toko mitra belum merupakan pesanan pusat; hanya pimpinan berwenang yang meneruskan (BRR-01, 04-REQ/APR).
3. Penerimaan aktual menambah stok; jumlah order bukan bukti penerimaan (BRR-03, 05-RCV).
4. Konversi satuan dan harga dibekukan pada transaksi; perubahan master tidak menulis ulang sejarah (BRR-04, 02-PAK/HGA).
5. Transfer toko sendiri, penjualan pusat ke mitra, dan penjualan eceran memiliki transaksi/metrik terpisah; tidak dijumlahkan sebagai satu omzet (BRR-02, 04-OWN, 11-SELL).
6. Pembayaran, stok, pemakaian voucher, dan konfirmasi ulang harus idempoten. Koreksi menggunakan catatan kompensasi dengan alasan/audit, bukan penghapusan transaksi awal (05-MOV, 06-SAVE/VOID, 08-LIFE).
7. Pilih QRIS bukan berarti lunas; metode manual memerlukan konfirmasi. Integrasi otomatis belum disepakati (06-PAY).
8. Stok minimum memberi notifikasi, bukan order atau tagihan otomatis (BR-06, 05-MIN).
9. B2B dan B2C memiliki ruang voucher dan pihak penanggung terpisah (BRR-05, 08-B2B/B2C/FUND).
10. Data offline tidak boleh diam-diam menimpa transaksi pusat; konflik terlihat dan ditinjau (13-CON).

## Penelusuran kebutuhan dan cakupan usulan

Prioritas di sini usulan product manager, bukan perubahan status persetujuan dokumen. P0 = kandidat minimum pilot; P1 = tahap penguatan; keputusan = perlu kebijakan sebelum aktivasi. Baris tetap mencakup semua feature sumber walaupun implementasinya bertahap.

### PRD 01 Akses organisasi dan pengguna

Sumber: [PRD_01_Akses_organisasi_dan_pengguna.md](../reference/Markdown/PRD_01_Akses_organisasi_dan_pengguna.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 01-ORG — Organisasi dan toko | Pusat membuat mitra dan toko; setiap toko memiliki pemilik berupa perusahaan sendiri atau satu mitra; status aktif/nonaktif serta alamat dicatat. | Diberikan Mitra A memiliki toko A1 dan A2, saat A masuk maka hanya A1 dan A2 terlihat; Mitra B ditolak saat mencoba membuka data A1. |
| 01-USR — Akun dan penugasan | Setiap orang memiliki akun unik, dapat ditugaskan pada beberapa toko yang diizinkan, dan dapat dinonaktifkan tanpa menghapus transaksi. | Saat akun dinonaktifkan sesi baru ditolak; riwayat transaksi lama tetap menampilkan pelaku. |
| 01-ACL — Peran dan kewenangan | Pisahkan pemilik pusat, admin pusat, gudang, pimpinan mitra, kepala toko, kasir dan manajer toko sendiri; izin perubahan harga, stok, refund dan persetujuan diatur khusus. | Kasir mencoba menyetujui pembelian mitra: akses ditolak; pimpinan mitra berwenang dapat menyetujuinya. |
| 01-AUD — Jejak audit | Catat aktor, organisasi, waktu, tindakan, objek, nilai sebelum dan sesudah untuk perubahan penting; informasi sensitif tidak disimpan berlebihan. | Perubahan peran dicatat dan dapat ditelusuri; pengguna tanpa izin tidak dapat mengubah catatan audit. |

### PRD 02 Katalog produk satuan dan harga

Sumber: [PRD_02_Katalog_produk_satuan_dan_harga.md](../reference/Markdown/PRD_02_Katalog_produk_satuan_dan_harga.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 02-SKU — Produk dan varian | Catat SKU, nama, varian, kondisi beku atau siap jual, status aktif, gambar, jenis pemilik dan satuan dasar biji. | Produk tidak aktif tidak dapat dipilih untuk pesanan baru, tetapi riwayat transaksi tetap ada. |
| 02-PAK — Kemasan dan konversi | Setiap SKU kemasan menyimpan isi 8 atau 10 biji sesuai data sebenarnya; 1 porsi dapat berisi jumlah biji yang dikonfigurasi. Jangan mengubah konversi historis. | Terima 2 kemasan isi 8 menambah 16 biji; jual satu porsi 4 biji mengurangi 4 biji. |
| 02-HGA — Harga dan masa berlaku | Harga pembelian mitra dan harga jual toko dipisah; pusat mengelola harga mitra, pengaturan harga toko mengikuti kewenangan. | Pesanan yang sudah disetujui menyimpan harga pada saat transaksi walaupun daftar harga kemudian berubah. |
| 02-BND — Paket dan resep penjualan | Paket bisa berisi beberapa varian; setiap komponen mengurangi stok sesuai jumlah resep, termasuk satuan masing-masing. | Paket 2 ayam dan 1 udang mengurangi stok ayam 2 dan udang 1; stok kurang memunculkan penanganan sesuai kebijakan. |

### PRD 03 Pendaftaran mitra dan toko

Sumber: [PRD_03_Pendaftaran_mitra_dan_toko.md](../reference/Markdown/PRD_03_Pendaftaran_mitra_dan_toko.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 03-MIT — Data mitra | Pusat mencatat nama usaha, penanggung jawab, kontak, alamat tagihan, tipe kerja sama dan status; lampiran perjanjian dibatasi aksesnya. | Mitra belum aktif tidak dapat mengirim pesanan; pengaktifan meninggalkan audit. |
| 03-TOK — Data toko | Catat nama, alamat, wilayah, jenis kepemilikan, pimpinan, jam operasional dan status; setiap toko mitra terhubung ke satu mitra. | Pimpinan mitra hanya melihat toko miliknya; pusat dapat memfilter toko sendiri dan mitra. |
| 03-KON — Ketentuan kerja sama | Catat masa berlaku, penggunaan merek, cakupan data yang dibagikan, skema putus beli atau konsinyasi dan fasilitas sebagai data kontrak. Konsinyasi belum otomatis aktif. | Mencatat konsinyasi tidak membuat sistem menghitung bagi hasil; sistem menandai perlu proses khusus. |
| 03-STA — Penutupan toko | Toko dapat dinonaktifkan tanpa menghapus penjualan atau stok; penyelesaian stok, tagihan dan aset dilakukan sebelum penutupan operasional. | Toko nonaktif tidak menerima pesanan atau transaksi baru; laporan lama tetap tersedia bagi pihak berwenang. |

### PRD 04 Pengajuan persetujuan dan pesanan

Sumber: [PRD_04_Pengajuan_persetujuan_dan_pesanan.md](../reference/Markdown/PRD_04_Pengajuan_persetujuan_dan_pesanan.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 04-REQ — Pengajuan toko | Kepala toko memilih SKU, jumlah kemasan, toko penerima, catatan dan usulan pengiriman; sistem menghitung estimasi harga. | Pengajuan yang disimpan belum menjadi pesanan ke pusat dan tidak mengurangi stok pusat. |
| 04-APR — Persetujuan pimpinan | Pimpinan mitra dapat menyetujui, mengubah atau menolak pengajuan; pimpinan juga dapat membuat pesanan langsung. | Hanya pimpinan mitra berwenang dapat mengirim pesanan; kasir ditolak; perubahan jumlah tersimpan dalam riwayat. |
| 04-ORD — Status pesanan | Gunakan pengajuan, menunggu persetujuan, dikembalikan, disetujui dan dikirim ke pusat, diterima pusat, diproses, dikirim/siap diambil, diterima sebagian, selesai dan dibatalkan; pembayaran dicatat terpisah. | Pesanan diterima sebagian tidak otomatis selesai; sisa menunggu tindak lanjut yang dicatat. |
| 04-EXC — Perubahan dan pembatalan | Pusat memberi tahu kekurangan stok; perubahan setelah persetujuan memerlukan persetujuan yang relevan; tidak membuat penerimaan fiktif. | Ketika item diganti setelah persetujuan, perubahan tercatat dan mitra menyetujui kembali sebelum dipenuhi. |
| 04-OWN — Permintaan toko sendiri | Toko milik perusahaan mengajukan permintaan dan gudang memindahkan stok internal, bukan membuat omzet mitra atau piutang. | Kirim stok ke toko sendiri menghasilkan transfer stok; tidak menghasilkan penjualan ke mitra. |

### PRD 05 Stok batch dan pemrosesan dimsum

Sumber: [PRD_05_Stok_batch_dan_pemrosesan_dimsum.md](../reference/Markdown/PRD_05_Stok_batch_dan_pemrosesan_dimsum.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 05-RCV — Penerimaan | Penerima mencatat jumlah aktual per SKU, batch, tanggal kedaluwarsa dan kondisi; stok bertambah hanya atas penerimaan yang dikonfirmasi. | Pesan 20 bungkus, terima 18: stok hanya bertambah 18; selisih 2 ditandai. |
| 05-MOV — Buku pergerakan | Setiap penerimaan, transfer, penjualan, pengukusan, retur dan penyesuaian menghasilkan pergerakan dengan sumber, tujuan, pelaku dan waktu. | Pengulangan konfirmasi dengan ID yang sama tidak menggandakan stok. |
| 05-COOK — Buka dan kukus | Petugas mencatat konversi kemasan utuh ke biji terbuka dan pemindahan beku ke siap jual, dengan batch bila tersedia; hasil aktual dan susut dicatat. | Buka 1 bungkus isi 10 dan kukus 6: stok utuh turun 1 bungkus, terbuka bertambah 4 biji dan siap jual bertambah 6. |
| 05-COUNT — Stok opname dan susut | Petugas menghitung fisik; selisih ditinjau dan disetujui oleh pihak berwenang dengan alasan sebelum penyesuaian. | Selisih minus 3 belum mengubah stok sebelum disetujui; setelah disetujui tercatat minus 3 beserta alasan. |
| 05-EXP — Peringatan dan penarikan batch | Sistem menampilkan batch kedaluwarsa terdekat dan membantu pelacakan lokasi penerimaan; masa peringatan ditentukan pemilik. | Batch yang ditandai bermasalah ditampilkan di daftar lokasi terkait; pemblokiran penjualan hanya setelah aturan disetujui. |
| 05-MIN — Stok minimum | Ambang per SKU dan toko memicu notifikasi; saran berdasarkan penjualan dan waktu pasok hanya bila data memadai. | Stok turun melewati ambang menghasilkan notifikasi tanpa otomatis memesan atau meminta pembayaran. |

### PRD 06 POS transaksi dan pembayaran pembeli

Sumber: [PRD_06_POS_transaksi_dan_pembayaran_pembeli.md](../reference/Markdown/PRD_06_POS_transaksi_dan_pembayaran_pembeli.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 06-CART — Keranjang | Kasir mencari produk atau memilih tombol, menambah jumlah, memilih per biji, porsi atau kemasan serta paket; total dihitung dari harga yang berlaku. | Tambah 4 biji harga Rp3.000: subtotal Rp12.000; pengubahan jumlah memperbarui subtotal. |
| 06-PAY — Penyelesaian pembayaran | Catat tunai dan kembalian; QRIS atau transfer dicatat sebagai metode setelah konfirmasi kasir; integrasi otomatis ditangani terpisah. | Transaksi tidak dianggap dibayar hanya karena QRIS dipilih; sebelum konfirmasi kasir status belum lunas. |
| 06-SAVE — Transaksi dan stok | Ketika pembayaran terkonfirmasi, simpan nomor transaksi unik, item, diskon, metode, kasir, waktu, toko dan pergerakan stok sekali. | Ketuk tombol bayar dua kali tidak menciptakan dua transaksi atau mengurangi stok dua kali. |
| 06-VOID — Pembatalan dan refund | Hanya pengguna berwenang dapat membatalkan atau refund disertai alasan; koreksi stok dicatat sebagai pergerakan baru, bukan menghapus transaksi awal. | Kasir tanpa izin ditolak ketika refund; pengelola dapat melakukan refund dan riwayat awal tetap terlihat. |
| 06-RCPT — Struk | Sediakan nomor transaksi, toko, waktu, item, diskon, total dan metode; cetak mengikuti kompatibilitas perangkat. | Total struk sama dengan total transaksi yang tersimpan; kegagalan printer tidak menggandakan transaksi. |

### PRD 07 POS shift kas dan laporan harian

Sumber: [PRD_07_POS_shift_kas_dan_laporan_harian.md](../reference/Markdown/PRD_07_POS_shift_kas_dan_laporan_harian.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 07-OPEN — Pembukaan shift | Kasir membuka shift dengan jumlah uang awal dan identitas perangkat/toko; kebijakan lebih dari satu shift aktif perlu ditetapkan. | Penjualan ditautkan ke shift aktif; jika belum ada shift, POS menolak atau meminta pembukaan sesuai aturan yang disetujui. |
| 07-CASH — Kas masuk dan keluar | Catat penambahan dan pengeluaran kas beserta kategori, nilai, alasan dan otorisasi; bukan dianggap penjualan produk. | Pengeluaran kas Rp20.000 mengurangi kas yang diharapkan tetapi tidak mengurangi omzet. |
| 07-CLOSE — Penutupan dan selisih | Hitung kas seharusnya dari saldo awal, penjualan tunai, refund tunai serta kas masuk dan keluar; catat hitungan fisik dan selisih. | Saldo awal 100.000 + penjualan tunai 200.000 − kas keluar 20.000 = seharusnya 280.000; fisik 275.000 menghasilkan selisih −5.000. |
| 07-DAY — Rekap harian | Tampilkan penjualan kotor, diskon, refund, penjualan bersih dan rincian metode pembayaran berdasarkan rentang waktu dan toko. | Total bersih sama dengan penjualan selesai dikurangi diskon dan refund sesuai definisi laporan. |

### PRD 08 Voucher dan promosi dua tingkat

Sumber: [PRD_08_Voucher_dan_promosi_dua_tingkat.md](../reference/Markdown/PRD_08_Voucher_dan_promosi_dua_tingkat.md). Prioritas usulan: P1.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 08-B2B — Voucher pusat ke mitra | Pusat mengatur kode, nominal/persen, syarat minimal, mitra sasaran, SKU, kuota, tanggal, kombinasi dan batas diskon. | Voucher yang kedaluwarsa atau tidak mencapai minimum ditolak dengan alasan; pesanan menyimpan rincian diskon. |
| 08-B2C — Voucher toko ke pembeli | Mitra atau toko berwenang mengatur voucher POS untuk toko dan produk yang diizinkan; aturan berlaku dan masa aktif dapat ditentukan. | Voucher toko A tidak dapat dipakai di toko B jika cakupannya hanya toko A. |
| 08-FUND — Pendanaan dan klaim | Catat pihak penanggung pusat, mitra atau pembagian; cara penggantian dana dan otorisasi klaim menunggu keputusan bisnis. | Promo yang didanai pusat tercatat sebagai kewajiban promosi terpisah; tidak otomatis mengurangi tagihan tanpa aturan yang disetujui. |
| 08-LIFE — Pemakaian dan pembatalan | Simpan kuota dan riwayat pemakaian unik; penanganan penggunaan kembali setelah batal harus mengikuti aturan yang ditetapkan. | Dua perangkat mencoba memakai kuota terakhir: maksimal satu pemakaian berhasil setelah validasi pusat; untuk offline gunakan kebijakan yang disepakati. |

### PRD 09 Gudang pengiriman dan pengambilan

Sumber: [PRD_09_Gudang_pengiriman_dan_pengambilan.md](../reference/Markdown/PRD_09_Gudang_pengiriman_dan_pengambilan.md). Prioritas usulan: P0.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 09-PICK — Penyiapan | Gudang melihat pesanan yang siap dipenuhi dan mencatat SKU, jumlah dan batch yang dipilih; stok tidak boleh keluar dua kali. | Pesanan yang hanya dipenuhi sebagian menampilkan jumlah yang disiapkan dan sisa. |
| 09-SHIP — Antar atau ambil | Catat mode pengiriman, tujuan, jadwal, petugas/kurir, waktu keluar, bukti dan status; pengambilan mitra dicatat sebagai serah terima. | Pesanan mode ambil tidak membutuhkan alamat kurir; penerima tetap dikonfirmasi. |
| 09-COLD — Pemeriksaan kondisi | Catat kondisi kemasan, suhu dan waktu bila alat dan SOP tersedia, serta kejadian yang perlu ditinjau; batas mutu mengikuti SOP produk yang divalidasi. | Petugas dapat menandai kondisi tidak sesuai dan mencatat tindakan; sistem tidak menyatakan produk aman secara otomatis. |
| 09-PROB — Selisih dan retur | Penerima mencatat jumlah aktual, kerusakan, bukti dan alasan; pusat meninjau tindak lanjut pengganti, retur atau kredit. | Barang rusak tidak otomatis menjadi stok layak jual; penyelesaian tidak otomatis mengubah tagihan tanpa persetujuan. |

### PRD 10 Tagihan pembayaran dan rekonsiliasi

Sumber: [PRD_10_Tagihan_pembayaran_dan_rekonsiliasi.md](../reference/Markdown/PRD_10_Tagihan_pembayaran_dan_rekonsiliasi.md). Prioritas usulan: P1.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 10-INV — Tagihan mitra | Catat pihak yang ditagih, referensi pesanan, item, diskon, biaya kirim, total dan jatuh tempo bila disepakati. | Pimpinan hanya melihat tagihan mitranya; transfer ke toko sendiri tidak membuat tagihan mitra. |
| 10-COL — Pencatatan pembayaran | Admin mencatat metode, jumlah, tanggal dan bukti; pembayaran sebagian mengurangi saldo terutang setelah diverifikasi. | Tagihan 1.000.000 dibayar 400.000 menghasilkan sisa 600.000; bukti belum diverifikasi tidak dianggap lunas. |
| 10-CRED — Kredit retur dan promosi | Penyesuaian tagihan atau nota kredit harus merujuk alasan dan disetujui; tidak menghapus riwayat asal. | Kredit Rp50.000 yang disetujui mengurangi saldo sesuai aturan dan dapat ditelusuri. |
| 10-DUE — Tunggakan | Tampilkan saldo terbuka dan jatuh tempo serta notifikasi sesuai izin; penangguhan pesanan atau denda hanya bila diputuskan. | Tagihan melewati tanggal jatuh tempo muncul dalam daftar; sistem tidak menambahkan denda otomatis. |

### PRD 11 Dashboard dan laporan kinerja

Sumber: [PRD_11_Dashboard_dan_laporan_kinerja.md](../reference/Markdown/PRD_11_Dashboard_dan_laporan_kinerja.md). Prioritas usulan: P0 ringkasan; P1 lanjutan.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 11-CTR — Ringkasan pusat | Tampilkan penjualan pusat ke mitra, penjualan toko sendiri, stok, order terbuka, mitra aktif dan piutang sebagai metrik terpisah. | Transaksi transfer internal tidak muncul sebagai penjualan pusat kepada mitra. |
| 11-PART — Ringkasan mitra | Pimpinan dapat memfilter toko, periode, produk dan status serta membandingkan toko yang berada dalam organisasi miliknya. | Mitra A tidak dapat melihat laporan Mitra B melalui filter maupun tautan langsung. |
| 11-SELL — Pelaporan POS | Penjualan pembeli di toko mitra ditampilkan terpisah dari pendapatan pusat; cakupan data yang terlihat pusat mengikuti perjanjian. | Dashboard tidak menjumlahkan penjualan grosir dan eceran menjadi omzet tunggal tanpa label. |
| 11-KPI — Definisi indikator | Nilai bersih = penjualan selesai dikurangi diskon dan refund sesuai periode laporan; stok rendah memakai ambang per SKU/lokasi; mitra aktif berdasarkan definisi yang disepakati. | Angka pada rekap dapat ditelusuri ke transaksi sumber; tanggal dan zona waktu filter jelas. |
| 11-EXP — Ekspor dan kesegaran data | Ekspor laporan sesuai izin; tampilkan waktu pembaruan terakhir dan penanda transaksi offline belum sinkron bila tersedia. | Data tidak sinkron tidak disajikan sebagai data terkini tanpa penanda; ekspor mengikuti batas organisasi. |

### PRD 12 Freezer perangkat dan fasilitas

Sumber: [PRD_12_Freezer_perangkat_dan_fasilitas.md](../reference/Markdown/PRD_12_Freezer_perangkat_dan_fasilitas.md). Prioritas usulan: P1.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 12-AST — Daftar aset | Catat ID, jenis, merek, nomor seri, pemilik, status, kondisi dan lokasi; pisahkan freezer dan perangkat POS. | Nomor aset unik; aset tidak dapat dipinjamkan ke dua toko secara bersamaan. |
| 12-LOAN — Serah terima | Catat tanggal penyerahan, toko, penanggung jawab, kondisi, perjanjian dan bukti; riwayat tetap ada saat aset dipindah. | Pemindahan aset membuat riwayat lokasi baru tanpa menghapus lokasi sebelumnya. |
| 12-MAINT — Perawatan dan kerusakan | Catat laporan kerusakan, penanggung biaya sesuai perjanjian, perbaikan dan tanggal selesai; status tidak menyimpulkan kelayakan pangan. | Freezer rusak dapat ditandai tidak tersedia; laporan insiden tetap tersimpan. |
| 12-RETURN — Pengembalian | Akhir kerja sama memicu daftar periksa aset; kembalikan dengan tanggal dan kondisi, lalu ubah lokasi. | Pengembalian yang belum dikonfirmasi tidak otomatis mengubah status menjadi tersedia. |

### PRD 13 Keandalan keamanan offline dan notifikasi

Sumber: [PRD_13_Keandalan_keamanan_offline_dan_notifikasi.md](../reference/Markdown/PRD_13_Keandalan_keamanan_offline_dan_notifikasi.md). Prioritas usulan: P0 keamanan/notifikasi; lainnya keputusan.

| ID | Kebutuhan | Bukti penerimaan sumber |
| --- | --- | --- |
| 13-SEC — Pemisahan dan keamanan | Periksa hak akses di server untuk setiap permintaan; gunakan koneksi terenkripsi, kebijakan sandi dan pencadangan teruji. Rincian keamanan ditetapkan tim teknis. | Pengguna tanpa hak ditolak walau mengetahui ID objek; pemulihan cadangan diuji dan dicatat. |
| 13-OFF — Operasi offline POS | Jika diprioritaskan, simpan transaksi secara aman di perangkat dengan ID unik, status antrean dan sinkronisasi ulang; daftar fungsi offline dan batas stok diputuskan sebelum implementasi. | Transaksi offline tidak hilang saat aplikasi ditutup; setelah sinkronisasi berulang hanya satu transaksi tercatat di pusat. |
| 13-CON — Konflik dan pengecualian | Ketika data offline bertentangan dengan server, tandai untuk peninjauan; jangan diam-diam menimpa transaksi, stok atau pemakaian voucher. | Dua perangkat memakai kuota terakhir saat offline: sistem menandai konflik untuk penyelesaian sesuai kebijakan, bukan mengklaim keduanya valid otomatis. |
| 13-NOT — Notifikasi | Kirim notifikasi dalam aplikasi untuk stok rendah, persetujuan, pesanan, pengiriman dan tagihan; kanal lain bergantung integrasi dan persetujuan. | Pengajuan muncul kepada pimpinan yang relevan; pengguna mitra lain tidak menerima isi notifikasi. |
| 13-NFR — Kualitas layanan | Tetapkan target waktu respons, ketersediaan, retensi data, backup, pemulihan dan jenis perangkat setelah uji lapangan; catat waktu sinkron terakhir di POS. | Uji penerimaan mencakup pemulihan backup, gangguan jaringan, isolasi mitra dan sinkronisasi berulang; target angka belum disepakati. |

## Kriteria penerimaan MVP lintas modul

Skenario berikut adalah target uji ketika modul implementasi tersedia, bukan hasil uji saat ini.

| ID uji | Skenario dan hasil yang wajib | Sumber |
| --- | --- | --- |
| MVP-01 | Mitra B meminta langsung URL/API toko A1: ditolak; laporan/ekspor tidak membocorkan data A1 | 01-ORG/ACL, 11-PART, 13-SEC |
| MVP-02 | Kepala toko mengajukan 20 kemasan: pusat belum menerima order; pimpinan menyetujui: order baru terlihat pusat; kasir mencoba menyetujui: ditolak | 04-REQ/APR |
| MVP-03 | Pesan 20 kemasan, terima 18 kemasan isi 8: stok dasar bertambah 144 biji; 2 kemasan menjadi selisih, bukan penerimaan fiktif; ulang request tidak menambah lagi | 02-PAK, 05-RCV/MOV, 09-PROB |
| MVP-04 | Buka satu kemasan isi 10 lalu kukus 6: satu kemasan utuh berkurang, 4 biji beku terbuka dan 6 biji siap jual tercatat; ledger seimbang | 05-COOK |
| MVP-05 | Jual 4 biji @Rp3.000: subtotal Rp12.000; QRIS manual tetap belum lunas sampai konfirmasi; pengulangan bayar mengurangi stok sekali | 06-CART/PAY/SAVE |
| MVP-06 | Refund oleh kasir tanpa izin ditolak; pihak berwenang membuat koreksi dan alasan tanpa menghapus transaksi awal; printer gagal tidak menggandakan penjualan | 06-VOID/RCPT |
| MVP-07 | Kas awal Rp100.000 + tunai Rp200.000 − kas keluar Rp20.000 = ekspektasi Rp280.000; fisik Rp275.000 memberi selisih −Rp5.000; kas keluar bukan pengurang omzet | 07-CASH/CLOSE |
| MVP-08 | Transfer gudang ke toko sendiri menambah stok tujuan lewat penerimaan tanpa invoice mitra atau omzet grosir; dashboard memisahkan grosir/eceran | 04-OWN, 10-INV, 11-CTR/SELL |
| MVP-09 | Stok melintasi ambang: notifikasi hanya bagi penerima sah; tidak ada order otomatis | 05-MIN, 13-NOT |
| MVP-10 | Akun/toko nonaktif ditolak untuk transaksi baru; riwayat dan audit tetap terbaca sesuai hak; backup berhasil dipulihkan ke lingkungan uji | 01-USR, 03-STA, 13-SEC |

## Keputusan dan asumsi yang perlu ditutup

| ID | Keputusan | Pemilik usulan | Dampak sebelum aktivasi |
| --- | --- | --- | --- |
| D-01 | Putus beli/konsinyasi, momen penagihan, tempo/pajak/retur | Pemilik bisnis + keuangan | Jangan aktifkan perhitungan konsinyasi otomatis atau denda |
| D-02 | Cakupan data produk mitra, persetujuan kontrak, retensi dan akses pusat | Pemilik + mitra + produk | Tolak akses tanpa izin; jangan menganggap seluruh data POS milik pusat |
| D-03 | Harga, margin, diskon, biaya kirim dan pendanaan voucher | Pemilik + keuangan | Data contoh bukan harga produksi; promosi lintas pihak belum aktif |
| D-04 | Produk non-dimsum, satuan dasar dan SOP tiap kategori | Pemilik + operasional | Katalog extensible; aturan 8/10 hanya data produk yang sesuai |
| D-05 | Stok minus, FEFO/pemilihan batch, hasil kukus/susut, recall | Operasional | Usulan aman: tolak stok kurang saat online; keputusan belum final |
| D-06 | Offline prioritas, fungsi offline, konflik stok/voucher, login dan enkripsi perangkat | Produk + operasional + arsitek | Tidak mengklaim offline siap; keputusan hasil uji koneksi menentukan gate pilot |
| D-07 | Android/iOS/tablet, printer/barcode, koneksi dan banyak shift/perangkat | Produk + operasional | Uji perangkat nyata sebelum menjanjikan kompatibilitas |
| D-08 | QRIS/payment gateway, refund metode non-tunai dan webhook | Keuangan + produk | Mulai pencatatan manual yang diverifikasi; tidak menyebut integrasi otomatis |
| D-09 | Freezer/POS gratis, kepemilikan, biaya listrik/kerusakan dan kontrak | Pemilik + mitra | Aset dan kontrak dicatat; kewajiban tidak dibuat otomatis |
| D-10 | Kapasitas, latensi, availability, RPO/RTO, backup, retensi, target KPI | Produk + arsitek + pemilik | Benchmark pilot terlebih dahulu; belum ada angka/jadwal komitmen |

Asumsi teknis sementara: modular monolith API dengan kontrak HTTP versi; web memakai API tanpa mengakses database domain secara langsung; Flutter memakai satu basis kode adaptif mobile/tablet. Modul dapat didaftarkan dan diaktifkan sesuai kewenangan. Unduhan executable plugin Flutter saat runtime bukan asumsi produk; arti dynamic module harus mengikuti kemampuan platform dan keputusan arsitektur.

Di luar baseline awal: aplikasi konsumen, marketplace, akuntansi penuh, konsinyasi otomatis, waralaba otomatis, integrasi QRIS otomatis. Kebutuhan tersebut dapat masuk backlog setelah validasi.

## Ukuran keberhasilan

Mitra aktif, pembelian ulang, kesesuaian stok, ketersediaan produk, margin kontribusi, serta pesanan tepat/lengkap mengikuti BRD §6. Belum ada target numerik yang disetujui. Sebelum pilot, tetapkan pemilik metrik, periode, denominator, cakupan toko, zona waktu, serta cara menelusuri angka ke transaksi sumber. Jangan mencampur transfer internal dengan pendapatan atau menggandakan grosir dan eceran.
