# Roadmap dan release gate

Status: usulan urutan delivery; tidak menetapkan tanggal, anggaran, atau mengklaim seluruh fitur selesai. Baca [baseline kebutuhan](requirements.md) untuk penelusuran detail ke BRD/PRD sumber.

## Tahap 0 — Penemuan dan fondasi

Tujuan: menyepakati batas bisnis dan memastikan fondasi bisa dikembangkan mandiri oleh tim.

- Inventaris dokumen sumber; pisahkan fakta, keputusan terbuka, dan asumsi teknis.
- ADR pemisahan web/API/Flutter, kontrak API versi, modul dan dependensi, isolasi organisasi/toko, uang dan satuan historis.
- Atomic design dengan token bersama secara semantik serta implementasi komponen masing-masing platform; breakpoint berdasarkan ruang tersedia dan pengujian perangkat.
- Setup reproducible, health check, CI dan pengujian kontrak; modul scaffold diberi label belum implementasi.
- Discovery D-01 s.d. D-10, uji koneksi/perangkat/printer di lokasi pilot.

Gate: aplikasi fondasi dapat dijalankan dan diuji; batas implementasi tercatat; kebijakan yang memengaruhi transaksi diputuskan sebelum fitur terkait diaktifkan. Fondasi bukan produk POS siap operasional.

## Tahap 1 — Identitas, mitra, katalog

Status implementasi: irisan identitas dan katalog per organisasi tersedia. Kontrak/consent mitra, paket/resep, gambar, price list efektif dan gate pilot belum selesai; katalog B2B pusat awal sudah tersedia. Tahap 2 telah mempunyai irisan awal; tahap 3–5 belum diimplementasikan.

Cakupan: PRD 01–03, 13-SEC dan fondasi audit. Aktivasi mitra/toko, akun/peran/penugasan, katalog dengan konversi dan harga historis. Owner pusat, pimpinan mitra, kepala toko, gudang dan kasir diuji dengan izin positif/negatif.

Gate: MVP-01 dan bagian nonaktif/audit MVP-10 lulus, isolasi di query/filter/ekspor terbukti. Data demo terpisah dari produksi. Definisi skema kepemilikan dan cakupan data disepakati (D-02/D-04).

## Tahap 2 — Order, pemenuhan dan ledger stok

Status: pengajuan/approval, snapshot, pemilihan gudang dan batch, dispatch parsial, actual receipt, karantina, ledger idempoten, transfer internal, buka/kukus dengan hasil aktual serta opname/susut berapproval independen sudah diimplementasikan. Gate penuh belum selesai: reapproval pascapersetujuan, retur/pengganti/kredit, kebijakan SOP/masa simpan hasil olahan, notifikasi dan UAT masih tertunda. Web memiliki aksi; Flutter masih berupa tampilan status dan stok.

Cakupan: PRD 04–05 dan 09. Pengajuan → persetujuan → pusat → picking → antar/ambil → penerimaan aktual/selisih. Transfer toko sendiri dipisah dari penjualan mitra. Batch, pembukaan kemasan, pengukusan, opname dan notifikasi stok minimum.

Gate: MVP-02/03/04/08/09 lulus; retry tidak menggandakan pergerakan; stok fisik pilot direkonsiliasi; perubahan sesudah approval memerlukan persetujuan ulang. SOP batch/susut/kurang stok ditetapkan (D-05).

## Tahap 3 — POS mobile/tablet dan shift, pilot terbatas

Cakupan: PRD 06–07, ringkasan PRD 11, notifikasi 13-NOT. Keranjang, satuan/paket, tunai dan pencatatan QRIS manual, transaksi idempoten, refund berizin, struk, kas masuk/keluar dan tutup shift. Layout tablet dapat memperlihatkan katalog dan keranjang bersamaan; mobile mempertahankan alur transaksi yang sama dengan navigasi sesuai ruang.

Gate: MVP-05/06/07 lulus pada perangkat nyata; total struk sama dengan transaksi; printer gagal tidak menggandakan transaksi. Semua MVP-01 s.d. MVP-10 yang relevan lulus termasuk restore backup. Pemilik produk, operasional dan wakil mitra menandatangani UAT. Jika koneksi lokasi tidak memadai, pilot ditahan sampai offline/prosedur cadangan yang disepakati terbukti; offline tidak otomatis ditunda demi jadwal.

## Tahap 4 — Penguatan komersial dan operasional

Cakupan: PRD 08, 10, 12 dan pelaporan lanjutan PRD 11. Voucher B2B/B2C, kuota atomik, pembiayaan promo, invoice dan pembayaran parsial terverifikasi, kredit berizin, aset/serah-terima/perawatan, ekspor berizin dan indikator data terakhir diperbarui.

Gate: voucher terakhir hanya berhasil sekali, invoice Rp1.000.000 dibayar terverifikasi Rp400.000 menghasilkan saldo Rp600.000, transfer sendiri tidak membuat piutang, aset tidak dipinjamkan ke dua toko, ekspor menegakkan isolasi. D-01/D-03/D-09 selesai untuk kemampuan yang diaktifkan.

## Tahap 5 — Offline dan integrasi sesuai hasil pilot

Cakupan bersyarat: PRD 13-OFF/CON/NFR, payment gateway/QRIS otomatis dan integrasi lainnya yang disetujui. Tahap ini dapat didahulukan bila hasil discovery menyatakan offline wajib untuk pilot.

Gate offline: transaksi tetap ada setelah aplikasi ditutup; sinkronisasi berulang hanya membuat satu transaksi; status antrean/konflik dapat ditinjau; konflik voucher/stok tidak ditandai sukses diam-diam. Uji loss of connection, expiry sesi, restart perangkat, replay dan penanganan perangkat hilang.

Gate pembayaran: signature webhook diverifikasi, replay idempoten, status provider direkonsiliasi, nominal/mata uang cocok, refund terotorisasi. Klaim provider sukses hanya setelah integrasi sandbox dan alur gagal diuji.

## Definition of Done per irisan fitur

1. Feature ID dan acceptance source tercantum pada pekerjaan; keputusan yang menghalangi tercatat, bukan ditebak menjadi aturan final.
2. API mempunyai validasi, authorization tenant/store/action, kontrak error, pagination yang diperlukan, audit dan transaksi atomik/idempotensi untuk mutasi finansial/stok.
3. UI memakai token dan komponen reusable, menangani loading/empty/error/forbidden serta ukuran mobile/tablet yang relevan; logika bisnis tetap di domain/API.
4. Tes positif/negatif, lintas mitra, status ilegal, retry, dan concurrency tersedia bila relevan; migrasi dan rollback/forward-recovery ditinjau.
5. Tidak ada secret di repository/log; backup/restore, observabilitas, manual operasional dan pelatihan diperbarui sesuai dampak.
6. Bukti build/test/UAT dicatat dengan tanggal, lingkungan dan batasannya. Scaffold atau demo tidak diberi label production-ready.

## Pengendalian prioritas

P0/P1 tetap usulan hingga validasi bisnis. Estimasi baru dibuat sesudah tim, platform target, integrasi, volume dan keputusan kritis jelas. Tidak ada tanggal peluncuran atau target KPI yang dianggap sudah disetujui dari dokumen sumber. Setiap perubahan scope multi-produk harus memperbarui acceptance, SOP satuan/batch, dan kontrak data sebelum implementasi.
