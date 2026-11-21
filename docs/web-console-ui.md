# Tampilan konsol web (Soft UI) dan dashboard

Konsol web memakai aset frontend **Soft UI Dashboard** (Creative Tim, lisensi MIT) sebagai cangkang: sidebar, bilah atas, kartu, ikon, dan grafik. Warna dasarnya diganti ke merah dan kuning bergradasi.

## Apa yang dipakai dan apa yang tidak

| Dipakai (`apps/web/public/soft-ui/`) | Sengaja tidak dipakai |
| --- | --- |
| `soft-ui-dashboard.min.css`, ikon Nucleo, Bootstrap 5 bundle, Chart.js 3, perfect-scrollbar, `soft-ui-dashboard.min.js` | Aplikasi Laravel bawaan template (login, tabel `users`, migrasi, seeder) |
| Berkas disalin apa adanya dari paket gratis `soft-ui-dashboard-laravel` v1.0.3 | Font Awesome kit, Google Fonts, dan skrip tombol GitHub yang dimuat layout aslinya |

- Template ini **bukan versi Livewire**; tidak ada dependensi Livewire. Web tetap Laravel + Blade, tanpa akses database, dengan token API di sesi server.
- Seluruh halaman konsol hanya memuat berkas dari origin sendiri, tanpa pihak ketiga (dijaga oleh tes `DashboardWebTest`). Font memakai font sistem.
- Pemberitahuan hak cipta dan lisensi ada di `apps/web/public/soft-ui/LICENSE.md`. Pertahankan saat memperbarui aset.

## Lapisan proyek di atasnya

| Berkas | Fungsi |
| --- | --- |
| `public/tokens.css` | Palet merah/kuning dari `packages/design-tokens/tokens.json` (dibangkitkan `scripts/generate-tokens.py`). |
| `public/soft-brand.css` | Menimpa warna ungu/merah muda template menjadi merah/kuning, tanpa mengubah berkas template. |
| `public/console.css` | Gaya isi halaman lama (panel, form, tabel, badge, notifikasi, struk), dibatasi di `.console-content` agar tidak mengganggu cangkang. Termasuk aturan cetak struk. |
| `resources/views/components/templates/console.blade.php` | Cangkang: sidebar, bilah atas, breadcrumb. Judul diambil otomatis dari `<h1>` halaman. |
| `public/app.css` | Tetap dipakai halaman publik (beranda dan login) yang tidak memakai cangkang konsol. |

## Pola tampilan halaman isi

Aturan di `console.css` berlaku untuk semua halaman konsol tanpa mengubah tiap halaman:

- **Menu tautan**: tautan berdiri sendiri (misalnya "← Organisasi") dan blok `<nav class="menu-chips">` tampil sebagai chip. Chip dengan class `is-active` menandai bagian yang sedang dibuka (dipakai ERP). Jangan memisahkan tautan dengan teks " · "; gunakan `menu-chips`.
- **Form**: field otomatis disusun dalam kolom (satu kolom di ponsel), tombol rata kiri, grup checkbox dan radio menjadi chip, dan baris berulang (`fieldset.order-row`, `fieldset.pos-row`) memakai grid dengan tombol hapus sejajar.
- **Warna**: palet cerah (koral, oranye, kuning). Gradasi hanya untuk aksen dan tombol. Teks di atas gradasi memakai warna gelap atau merah tua agar kontrasnya terjaga.
- **Ponsel (di bawah 600 px)**: input 16 px dan tinggi 46 px (iOS tidak memperbesar layar saat mengetik), tombol aksi selebar layar, baris POS menempatkan Satuan dan Jumlah berdampingan, dan aksi sekunder (tambah atau hapus baris) tampil ringan. Form pembayaran POS menyembunyikan kolom non-tunai saat metode Tunai dipilih (`pos-payment.js`, kolom tetap dikirim kosong dan tanpa JavaScript semuanya tampil). Panel "Paket resep" hanya tampil bila ada paket.
- Tautan yang hanya berlaku untuk pusat (misalnya bahan baku dan produksi) disembunyikan di halaman organisasi mitra.

## Dashboard

Halaman **Beranda** menampilkan ringkasan 7 hari (hari ini sampai enam hari sebelumnya, kalender WIB) untuk organisasi pertama yang boleh dilaporkan pengguna (owner, admin, pimpinan mitra, kepala toko). Pengguna dengan beberapa organisasi memilihnya lewat tombol di bagian atas (`/app?org=...`).

- Kartu: omzet hari ini, omzet 7 hari (tunai dan transfer/QRIS), jumlah transaksi, refund dan omzet bersih.
- Grafik: omzet harian tunai versus non-tunai, dan lima produk terlaris menurut nilai penjualan.
- Sumber: `GET organizations/{org}/reports/sales-trend` (OpenAPI 0.43.0). Aturan akses, batas 31 hari, dan penyaringan toko sama dengan laporan penjualan tunai. Refund dihitung pada hari refund dilakukan, tidak dikurangkan dari hari penjualan.
- Bila pengguna tidak punya hak laporan, API gagal, atau jawaban API milik organisasi lain, dashboard disembunyikan dan halaman organisasi tetap tampil.
- Data grafik dikirim lewat atribut HTML dan digambar oleh `public/dashboard-charts.js`; tidak ada skrip inline.

## Batas cakupan

- Cabang PostgreSQL untuk pengelompokan hari WIB belum diuji (hanya SQLite); verifikasi sebelum produksi.
- Dashboard per organisasi; belum ada gabungan seluruh mitra untuk pusat.
- Halaman publik (beranda, login) dan halaman error belum memakai cangkang Soft UI.
- Menu geser pada mobile diverifikasi lewat DOM; tangkapan layar emulasi tidak menampilkannya.
- Aplikasi Flutter tidak terpengaruh.
