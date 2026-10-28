# BRD Jaringan Bisnis Dimsum

Kebutuhan bisnis untuk distribusi produk beku, jaringan toko, dashboard dan POS

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## 1 Ringkasan dan tujuan

Pemilik menjual dimsum beku dalam kemasan berisi 8 atau 10 biji kepada mitra, dan dapat mengelola toko sendiri. Mitra dapat memiliki banyak toko; toko dapat menjual produk per biji. Pusat menyediakan POS gratis sebagai fasilitas kerja sama dan membutuhkan visibilitas penjualan serta stok sesuai perjanjian. Tahap wilayah awal: Pacitan, Ponorogo dan Yogyakarta.

Tujuan bisnis adalah mempermudah pembelian ulang, mengurangi kekosongan dan kehilangan stok, meningkatkan kemampuan operasional mitra, serta memungkinkan pemilik memantau jaringan. Dokumen ini belum menjadi perjanjian hukum maupun keputusan harga.

## 2 Kondisi yang disampaikan dan keputusan terbuka

- Fakta: produk dibeli beku per bungkus isi 8 atau 10; toko dapat menjual per biji dan mengukus produk.

- Fakta: mitra dapat memiliki beberapa toko; pimpinan mitra harus berwenang atas pembelian.

- Fakta: toko bisa milik sendiri atau mitra; merek toko bisa milik pusat atau mitra; pengiriman bisa diantar atau diambil.

- Fakta: pusat ingin POS gratis, data stok dan penjualan toko, notifikasi stok serta voucher B2B dan B2C.

- Belum diputuskan: putus beli atau konsinyasi, cakupan data produk lain, harga dan margin, biaya perangkat, syarat freezer, masa pembayaran, aturan retur, daftar fitur offline, dan integrasi pembayaran.

## 3 Model bisnis dan batas lingkup

Aktor: pemilik pusat, admin pusat, gudang/pengiriman, pimpinan mitra, kepala toko, kasir dan pembeli akhir. Tipe toko: milik sendiri dan milik mitra. Pusat menjual ke mitra; perpindahan ke toko sendiri adalah transfer internal. Penjualan mitra ke pembeli dicatat sebagai transaksi terpisah.

Cakupan: katalog, identitas dan hak akses, organisasi dan toko, persetujuan pembelian, pemenuhan, stok, POS, voucher, tagihan, dashboard dan aset freezer. Di luar cakupan awal yang belum disetujui: aplikasi pembeli akhir, marketplace, konsinyasi otomatis, integrasi QRIS otomatis, akuntansi penuh dan waralaba otomatis.

## 4 Alur bisnis utama

- 1. Pusat mengaktifkan mitra, toko, pengguna, produk dan harga yang berlaku.

- 2. Kepala toko mitra mengajukan kebutuhan; pimpinan meninjau dan menyetujui atau membuat pesanan langsung.

- 3. Pusat memproses pesanan; produk diantar atau diambil. Toko mencatat penerimaan aktual dan selisih.

- 4. Toko mencatat pembukaan kemasan dan pengukusan, menjual melalui POS, serta mencatat susut dan stok opname.

- 5. Stok rendah memberi peringatan; pesanan ulang tetap melalui pimpinan. Pusat dan mitra dapat membuat promosi untuk sasaran masing-masing.

- 6. Pusat memantau penjualan ke mitra dan toko sendiri; informasi eceran mitra terlihat sesuai perjanjian.

## 5 Kebutuhan bisnis terukur

| ID | Kebutuhan | Prioritas usulan |
| --- | --- | --- |
| BR-01 | Organisasi multi mitra dan multi toko dengan pembatasan akses | P0 |
| BR-02 | Persetujuan pimpinan sebelum order toko mitra dikirim ke pusat | P0 |
| BR-03 | Stok kemasan 8/10 dan penjualan biji/porsi dengan jejak perubahan | P0 |
| BR-04 | POS mencatat transaksi, pembayaran, struk dan shift | P0 |
| BR-05 | Pusat melihat stok serta penjualan yang disepakati | P0 |
| BR-06 | Peringatan stok minimum tanpa order otomatis | P0 |
| BR-07 | Voucher pusat untuk mitra dan voucher toko untuk pembeli | P1 |
| BR-08 | Pengiriman, pengambilan, selisih dan batch | P0 |
| BR-09 | Tagihan, pembayaran parsial, aset freezer dan laporan | P1 |
| BR-10 | Offline POS dan integrasi QRIS sesuai keputusan uji lapangan | P1 / keputusan |

## 6 Ukuran keberhasilan dan cara mengukur

| Ukuran | Definisi / sumber | Target |
| --- | --- | --- |
| Mitra aktif | Mitra yang memesan dalam periode; definisi periode dikunci kemudian. Sumber pesanan. | Belum ditetapkan |
| Kesesuaian stok | Perbandingan jumlah sistem dan stok opname per SKU/toko. | Baseline saat pilot |
| Pembelian ulang | Mitra dengan pembelian ulang dibagi mitra yang memenuhi periode observasi. | Baseline saat pilot |
| Ketersediaan produk | Persentase waktu/kunjungan tanpa stok kosong; metode pencatatan disepakati. | Baseline saat pilot |
| Margin kontribusi | Pendapatan dikurangi biaya produk, distribusi, promosi dan biaya langsung teridentifikasi. | Belum ditetapkan |
| Pesanan tepat dan lengkap | Pesanan diterima sesuai kuantitas dan waktu yang disepakati dibagi total yang jatuh tempo. | Baseline saat pilot |

## 7 Aturan bisnis dan pengukuran uang

- BRR-01: toko mitra boleh mengajukan tetapi pimpinan berwenang menyetujui atau membuat order langsung.

- BRR-02: pusat dan mitra memiliki penjualan yang terpisah; angka grosir dan eceran tidak dijumlahkan menjadi satu omzet.

- BRR-03: stok bertambah berdasarkan penerimaan aktual, bukan jumlah pesanan.

- BRR-04: SKU menyimpan konversi kemasan sesuai isi sebenarnya dan transaksi menyimpan harga serta konversi historis.

- BRR-05: voucher pembelian mitra berbeda dari voucher pembeli akhir; penanggung biaya dicatat.

- BRR-06: fasilitas POS gratis dan hak pusat melihat data harus dituangkan dalam perjanjian yang disepakati.

## 8 Risiko dan mitigasi

| Risiko | Mitigasi usulan |
| --- | --- |
| Data stok salah karena transaksi tidak tercatat | Pelatihan, shift, stok opname dan audit selisih. |
| Produk beku rusak saat distribusi | SOP rantai dingin, pemeriksaan penerimaan dan pencatatan insiden. |
| Biaya freezer dan POS melebihi margin | Hitung kontribusi per toko dan batasi fasilitas berdasarkan perjanjian. |
| Perselisihan data mitra | Tentukan data yang dibagikan, tujuan, akses dan masa penyimpanan dalam perjanjian. |
| Internet terputus | Uji lokasi; bangun fitur offline atau prosedur cadangan sebelum peluncuran. |
| Mitra tidak membeli sesuai rencana | Uji nilai usaha dan perjanjian; sistem tidak dapat menjamin kepatuhan di luar transaksi tercatat. |

## 9 Tahapan dan keputusan

Tahap 0 validasi harga, mitra dan SOP; tahap 1 pilot alur penjualan, stok dan pesanan; tahap 2 promosi, aset dan kontrol lanjutan; tahap 3 integrasi dan pengembangan. Prioritas offline ditentukan oleh hasil uji koneksi. Tidak ada tanggal peluncuran, anggaran atau target bisnis yang telah disetujui.

- Pemilik menetapkan model stok dan penagihan; cakupan data POS mitra; daftar produk lain; harga dan biaya; kebijakan retur; hak atas aset dan penggunaan merek.

- Tim operasional menetapkan alur beku-ke-siap jual, batch dan pelatihan. Tim produk bersama mitra menetapkan perangkat, offline, laporan dan otorisasi.

- Persetujuan draf: pemilik bisnis, perwakilan mitra, operasional dan penanggung jawab produk meninjau dan menyetujui sebelum estimasi pembangunan final.

## 10 Penelusuran ke PRD

PRD 01 — Akses organisasi dan pengguna: Menjamin pemisahan data antara pusat, mitra, toko dan pengguna.

PRD 02 — Katalog produk satuan dan harga: Menyediakan produk yang konsisten di seluruh kanal dan konversi satuan yang benar.

PRD 03 — Pendaftaran mitra dan toko: Mengaktifkan kemitraan dan toko tanpa mencampur kepemilikan atau kebijakan.

PRD 04 — Pengajuan persetujuan dan pesanan: Memastikan pembelian toko mitra hanya dikirim kepada pusat oleh pimpinan yang berwenang.

PRD 05 — Stok batch dan pemrosesan dimsum: Mengelola kuantitas beku, siap jual, kerusakan dan jejak batch.

PRD 06 — POS transaksi dan pembayaran pembeli: Mencatat transaksi pembeli secara cepat dan mengurangi stok sesuai produk.

PRD 07 — POS shift kas dan laporan harian: Mencocokkan transaksi dan uang kas setiap shift.

PRD 08 — Voucher dan promosi dua tingkat: Mengelola promosi pembelian mitra dan promosi pembeli akhir secara terpisah.

PRD 09 — Gudang pengiriman dan pengambilan: Mencatat pemenuhan pesanan secara terukur serta kondisi produk beku.

PRD 10 — Tagihan pembayaran dan rekonsiliasi: Memisahkan status tagihan mitra dari status pesanan dan pembayaran pembeli POS.

PRD 11 — Dashboard dan laporan kinerja: Menyajikan angka yang konsisten dengan batas akses dan definisi yang jelas.

PRD 12 — Freezer perangkat dan fasilitas: Mengelola fasilitas yang dipinjamkan kepada toko dan tanggung jawabnya.

PRD 13 — Keandalan keamanan offline dan notifikasi: Menjaga keamanan, ketersediaan operasional dan komunikasi perubahan status.
