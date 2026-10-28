# Panduan Dokumen BRD dan PRD

Daftar isi paket dan urutan penggunaan oleh pemilik bisnis, product manager dan programmer

Status: Draf untuk validasi pemilik bisnis dan tim pengembang. Keputusan yang belum disepakati ditandai sebagai usulan atau perlu keputusan.

## Cara menggunakan paket

Mulai dari BRD untuk menyetujui model bisnis. Kemudian tinjau PRD secara terpisah menurut sistem dan fitur. Setiap PRD berisi tujuan, aktor, kebutuhan, contoh kriteria penerimaan, dependensi dan uji kesiapan. Semua dokumen merupakan draf yang dapat diedit.

| Dokumen | Cakupan |
| --- | --- |
| 00 BRD | Model bisnis, kebutuhan, aturan, risiko, indikator dan keputusan |
| PRD 01 | Akses organisasi dan pengguna — Layanan bersama |
| PRD 02 | Katalog produk satuan dan harga — Dashboard pusat dan POS |
| PRD 03 | Pendaftaran mitra dan toko — Dashboard pusat dan mitra |
| PRD 04 | Pengajuan persetujuan dan pesanan — Aplikasi mitra dan dashboard pusat |
| PRD 05 | Stok batch dan pemrosesan dimsum — Gudang pusat dan toko |
| PRD 06 | POS transaksi dan pembayaran pembeli — POS toko |
| PRD 07 | POS shift kas dan laporan harian — POS dan dashboard toko |
| PRD 08 | Voucher dan promosi dua tingkat — Dashboard pusat mitra dan POS |
| PRD 09 | Gudang pengiriman dan pengambilan — Dashboard pusat dan penerimaan toko |
| PRD 10 | Tagihan pembayaran dan rekonsiliasi — Dashboard pusat dan mitra |
| PRD 11 | Dashboard dan laporan kinerja — Dashboard pusat mitra dan toko |
| PRD 12 | Freezer perangkat dan fasilitas — Dashboard pusat |
| PRD 13 | Keandalan keamanan offline dan notifikasi — Seluruh sistem |

## Urutan pembahasan yang disarankan

Bahas BRD dan PRD 01–05 dahulu untuk memastikan struktur pengguna, produk, mitra, persetujuan dan stok. Lanjutkan PRD 06–07 untuk POS, PRD 08–12 untuk promosi dan operasional, lalu PRD 13 untuk kebutuhan lintas sistem. Urutan implementasi tetap disesuaikan hasil pilot.

## Keputusan yang harus ditutup

- Model putus beli atau konsinyasi; harga, pembayaran, retur dan cara pendanaan promosi.

- Batas akses pusat terhadap transaksi produk mitra lain; mekanisme persetujuan mitra dan masa retensi data.

- Pembiayaan POS, freezer, listrik, dukungan dan syarat kemitraan.

- Aturan stok minus, pemilihan batch, konversi produk matang dan penanganan susut.

- Prioritas POS offline, perangkat, fungsi saat offline, konflik stok dan voucher.

- Definisi target kinerja, ketersediaan sistem, kapasitas dan jadwal setelah validasi.
