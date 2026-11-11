# Valuasi fisik konsinyasi dan HPP pemasok

ADR061,11 November2026. Permintaan pengguna: selesaikan valuasi fisik dan HPP barang milik pemasok dalam konsinyasi. Implementasi development tersedia pada API, web dan Flutter native melalui **Pembukuan dan jurnal → Stok konsinyasi milik pemasok / Jejak HPP aktual konsinyasi**. Kontrak persentase dan biaya formula bagi hasil tetap terpisah dari biaya aktual barang.

## Aktivasi dan bukti

Siapkan pemetaan jurnal dasar yang disetujui pengelola berbeda, akun persediaan produk/HPP/susut serta periode yang tepat pada organisasi pemasok. Nilai batch berasal dari produksi atau proposal nilai awal dengan pemeriksaan independen; tidak ada HPP bawaan. Persetujuan perjanjian konsinyasi dua pihak mengaktifkan valuasi otomatis bila seluruh dispatch sudah mempunyai bukti sumber keuangan prospektif. Perjanjian dapat diterima sebelum dispatch pertama; kiriman asli/pengganti berikutnya ikut dilacak.

Untuk perjanjian lama yang belum aktif, pemasok dapat mengajukan aktivasi dari layar valuasi, sebelum penjualan pertama. Tutup semua izin offline toko dan selesaikan antrean terlebih dahulu. Sistem memeriksa bukti biaya, revisi, penerimaan, ownership dan seluruh gerakan batch. Batch harus dedicated satu perjanjian/baris order, tanpa nilai awal milik penerima atau pergerakan deplesi terdahulu. Tidak mengimpor HPP penjualan sebelum aktivasi, mengubah jurnal lama, atau membuka kembali sumber yang telah direkonsiliasi manual. Kegagalan bukti menjadi `review_required`; retry memakai ID/versi/alasan asli dan diperiksa ulang server, tanpa input biaya klien. Aktivasi gagal secara domain tidak meninggalkan sebagian konversi; kegagalan database membatalkan transaksi penerimaan perjanjian seluruhnya.

## Persediaan dan HPP

- Dispatch memindahkan lokasi aset pemasok ke perjalanan. Receipt memindahkan biaya proporsional ke barang titipan, termasuk karantina. Tidak ada penjualan grosir, HPP dispatch atau pembelian penerima hanya karena barang berpindah lokasi.
- Kuantitas fisik tetap pada ledger toko penerima. Kuantitas/nilai **milik penerima** nol; nilai aktual tersimpan di subledger privat pemasok. Binding ownership meningkatkan revisi dengan bukti tersendiri, tanpa gerakan fisik palsu.
- POS mengurangi pool aktual pemasok dan menyiapkan Dr HPP / Cr persediaan pemasok pada tanggal penjualan WIB. Tambahan receipt menggunakan biaya aktual masing-masing dan rata-rata pool tersisa; HPP penjualan lama tidak ditulis ulang.
- Refund fisik menambah karantina dan Dr persediaan / Cr HPP dari alokasi penjualan asal, dengan offset unit untuk pembulatan tepat. Refund tanpa barang tidak memulihkan persediaan/HPP. Sumber HPP yang sudah direkonsiliasi manual tetap diperiksa.
- Nilai tidak diketahui tetap `null` dan antrean pemeriksaan; nol hanya bila biaya sumber terbukti nol. Nol tidak menghasilkan jurnal nominal palsu. `accounting:post` membukukan outbox tepat sekali; biaya diterapkan dan jurnal posted adalah dua status berbeda.

Contoh:100biji/Rp100.001 dititipkan; 40terjual menghasilkan HPPRp40.000 dan persediaan pemasokRp60.001. Refund fisik10biji mengembalikan Rp10.000, saldoRp70.001. Angka `cost_per_piece` pada kontrak margin tidak digunakan sebagai HPP ini. Penerima hanya melihat custody dan nilai aset sendiriRp0; biaya aktual pemasok tidak dikirim ke bukunya.

## Selisih, susut dan retur

Barang belum diterima tetap aset perjalanan/selisih pemasok. Untuk nilai hilang yang diketahui, sistem membuat kasus biaya pada tanggal penerimaan aktual: pusat mengusulkan pembagian beban, pimpinan mitra mengonfirmasi. Proses yang sama pada [settlement distribusi](distribution-cost-settlement.md) membukukan loss/claim tanpa transfer dana dan menutup residual yang disepakati; tanggal dispatch lama tidak diubah.

Susut/shortage opname fisik membuat pemeriksaan pemasok, tanpa otomatis menagih mitra. Pengelola keuangan pemasok yang berbeda dari pencatat menyetujui beban biaya yang diketahui pada periode sumber terbuka. Biaya tidak diketahui harus direkonsiliasi, bukan diisi tebakan.

Retur karantina melalui alur fisik yang sudah tersedia memindahkan aset titipan ke perjalanan pulang lalu gudang pemasok dengan biaya aktual. Karena sejak awal milik pemasok, tidak menunggu nota kredit buyout dan tidak membalik HPP barang yang belum terjual. Selisih perjalanan pulang memakai persetujuan beban dua pihak. Cakupan fisik tetap retur karantina/barang rusak yang berhak menurut order; ini bukan perluasan izin retur seluruh barang usable.

## Akses, batas dan pemeriksaan

Pemasok melihat nilai held/transit/disposisi dan ledger biaya privat. Pimpinan penerima hanya melihat kuantitas custody/status dan aset sendiri nol, tanpa nilai aktual/unit cost pemasok; pusat tanpa keanggotaan tidak dapat membuka buku pihak lain. Penyesuaian melalui API dengan UUID, versi, alasan dan server authority; web/native mempertahankan payload persis setelah respons tidak pasti atau penolakan berikutnya.

Campuran pemilik/perjanjian/baris, bukti historis/cutover hilang, jurnal sudah direkonsiliasi manual, izin offline terbuka, periode tertutup dan pemetaan berubah tidak dilewati diam-diam. Tidak ada auto gross/net revenue principal-agent: review pendapatan POS konsinyasi pada buku penerima tetap terpisah dari HPP pemasok ini. Tidak menambah reimbursement HPP dalam kontrak, pencairan rekening, gateway, push atau penahanan recall otomatis.

Aktivasi dibatasi1000kiriman/8MiB; bukti custody10.000entri/8MiB, halaman30baris dan100kasus susut per perjanjian. Semua pergerakan fisik dan koreksi ownership melalui StockLedger dengan transaksi atomik. SQLite development telah diuji; PostgreSQL/concurrency, perangkat printer nyata dan UAT tetap gate produksi. Bukti uji terbaru: [verification](verification.md).
