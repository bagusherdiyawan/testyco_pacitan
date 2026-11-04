# Retur, tagihan dan penyesuaian POS

Delivery DL-13/DL-15/DL-16, 4 November 2026. API dan Laravel BFF terpisah; BFF hanya memakai HTTP terautentikasi. Web responsif menyediakan administrasi keuangan/konfigurasi voucher. Flutter mobile/tablet menyediakan retur, pemakaian voucher, pembayaran dan refund. Status ini implementasi development dengan verifikasi manual; bukan aktivasi payment gateway atau persetujuan aturan bisnis global.

## Retur dan karantina — DL-13

Dari kasus dengan rencana `return` yang diusulkan owner/admin pusat dan dikonfirmasi `partner_lead`, buka **Retur karantina**. Petugas penerima mengirim kemasan rusak fisik dari batch lokal yang cocok dengan SKU/kemasan/kondisi/tanggal/batch penerimaan awal. Kuota hanya `damaged_packs`, dikurangi seluruh kemasan yang pernah dikirim retur. Barang hilang tidak dapat diretur dan kekurangan kiriman retur tidak otomatis membuat kuota baru.

Pengiriman mendebit `quarantined`; penerimaan seluruh baris secara eksplisit, termasuk nol dan alasan kekurangan, mengkredit `quarantined` di gudang asal. `fulfilled` berarti seluruh kuota sudah diterima karantina, bukan pernyataan barang layak jual. Penerimaan/order asli tidak ditulis ulang. Disposisi susut memakai proposal waste/review terpisah yang sudah tersedia pada kontrol stok; pelepasan ke stok layak jual tidak dilakukan otomatis dan tetap memerlukan SOP mutu yang belum ditetapkan.

Ledger menggunakan `return_dispatch`/`return_receipt`; case version, audit kedua organisasi, stok dan UUID operasi berada dalam transaksi yang sama. BFF dan Flutter mempertahankan request/review yang sama pada hasil unknown, 408/429 atau respons salah. Keputusan kredit tidak tersirat dari penerimaan retur.

## Tagihan dan saldo — DL-15

Dari pesanan grosir yang disetujui, buka **Tagihan, pembayaran dan konsinyasi**. Hanya owner/admin organisasi pemasok dan pimpinan organisasi mitra yang dapat mengaksesnya; petugas gudang/kasir/kepala toko tidak mendapat hak keuangan dari akses fisik pesanan.

Owner/admin mengusulkan tagihan dengan model, dasar kuantitas, diskon, ongkir, tarif pajak, pembulatan dan jatuh tempo yang diisi eksplisit. Nol harus tetap diisi bila tidak ada diskon/ongkir/pajak. Dasar putus beli dapat berupa kemasan dikirim atau diterima baik, sesuai kesepakatan dokumen. Setiap baris kiriman hanya dapat masuk satu invoice aktif, termasuk saat proposal masih menunggu. Rejected/withdrawn melepaskan klaim untuk dokumen baru; histori tetap ada. Pimpinan mitra harus memeriksa rincian dan menyetujui sebelum koleksi dapat dicatat. Transfer internal tidak membuat invoice.

Semua harga/konversi memakai snapshot historis order/kiriman. Rumus: `net = subtotal − diskon + ongkir`; `pajak = round_half_up(net × tax_basis_points / 10000)` ke satu rupiah. Total IDR integer dibatasi Rp1 triliun. Tarif 1100 berarti 11%; sistem tidak memilih tarif atau menentukan status perpajakan badan usaha.

Pembayaran parsial hanya mengurangi piutang setelah owner/admin mencentang verifikasi pembayaran aktual, mengisi metode, nominal, tanggal, referensi unik dan catatan bukti. Mendukung tunai di luar laci POS, transfer dan QRIS manual. Tidak mengirim uang, memanggil provider, atau mengubah shift POS. Koreksi salah pencocokan menghasilkan reversal dengan aktor/waktu/alasan; tidak menghapus bukti awal. Versi invoice mencegah pemakaian saldo lama.

Nota kredit harus merujuk kasus dengan rencana `credit` atau `return` yang sudah dikonfirmasi. Pusat mengusulkan nominal dasar dan pajak, pimpinan mitra mengonfirmasi lagi. Batas dasar berasal dari harga historis barang bermasalah yang memang ditagih; retur memerlukan jumlah fisik yang telah diterima gudang. Barang rusak/kurang yang dikecualikan pada basis `good_received` tidak dikreditkan lagi. Kredit tidak dapat melebihi sisa nilai barang setelah diskon, pajak historis dan tarif pada nominal yang dikreditkan. Rencana pengganti tidak membuka kredit.

Saldo bertanda = total − kredit disetujui − penerimaan terverifikasi + refund kredit. Nilai positif adalah piutang; nilai negatif disajikan sebagai kredit milik pelanggan, dengan piutang nol. Pengembalian kredit pelanggan harus dicatat eksplisit sebagai pembayaran keluar terverifikasi, maksimal saldo kredit. Tidak ada transfer dana otomatis. Reversal koleksi setelah refund kredit ditahan sampai refund direkonsiliasi.

Konsinyasi memerlukan kontrak konsinyasi yang diterima kedua pihak. Mitra melaporkan biji benar-benar terjual per baris order dalam periode kontrak, maksimal penerimaan baik yang belum dilaporkan. Laporan pending ikut memakai batas agar dua laporan tidak menagih unit sama. Pusat menerima/menolak; hanya laporan diterima yang dapat ditagih sekali. Harga adalah harga grosir historis per kemasan, dikonversi ke biji dengan pembulatan setengah ke atas. Pesanan yang sudah memiliki tagihan putus beli aktif tidak dapat sekaligus dilaporkan sebagai konsinyasi, dan sebaliknya. Tidak membaca POS mitra, menghitung bagi hasil, atau menetapkan kalender setoran otomatis.

## Voucher, non-tunai dan refund — DL-16

Pengelola organisasi membuat voucher melalui **Organisasi → Voucher penjualan eceran**. Pendanaan wajib dipilih eksplisit oleh organisasi penerbit sendiri. Lingkup toko wajib; daftar produk kosong berarti semua produk retail. Nominal tetap/persentase basis point, batas diskon, minimum subtotal eligible, tanggal inklusif WIB, kuota dan ketentuan wajib diisi. Kebijakan kuota setelah refund dipilih `never` atau `on_full_refund`; tidak ada kebijakan tersirat, klaim pusat/mitra, voucher grosir atau pendanaan lintas organisasi pada irisan ini.

Kasir memasukkan kode sebelum tinjau keranjang, termasuk paket resep. Server menentukan diskon dan membaginya deterministik ke baris eligible; perhitungan proporsi integer tidak memakai floating point dan tidak overflow pada batas nominal. Draft tidak mereservasi kuota/stok. Pembayaran memeriksa harga, versi, periode, lingkup dan sisa kuota lagi di bawah lock. Pemakaian kuota, redemption unik, struk, alokasi stok, shift dan audit commit bersama.

Pemilihan QRIS/transfer saja tidak melunasi. Kasir mengisi referensi unik, nominal aktual yang persis total, dan konfirmasi telah memeriksa bukti/pembayaran nyata. Struk menyimpan metode/referensi/pemeriksa/waktu. Penjualan non-tunai menambah `noncash_sales`, bukan `cash_sales` atau `expected_cash`. Referensi metode/organisasi tidak dapat dipakai pada penjualan lain. Tunai tetap memakai uang diterima dan kembalian. Request pembayaran web/Flutter unknown mempertahankan UUID/metode/referensi/nominal walaupun percobaan berikutnya ditolak; tidak membuat pembayaran pengganti.

Pengelola berizin toko membuka **Tinjau refund** pada struk. Pilih shift yang sedang terbuka, jumlah unit asal per baris, alasan/referensi dan penanganan fisik: tanpa stok kembali atau masuk karantina batch historis. Server menampilkan nominal setelah diskon historis serta biji komponen/batch yang akan kembali; konfirmasi memerlukan review hash, versi sale/shift dan verifikasi pengembalian uang aktual melalui metode asal. Kasir biasa tidak dapat refund.

Refund parsial tidak melebihi unit tersisa. Pembulatan kumulatif memastikan seluruh refund tidak melebihi total setelah diskon dan refund penuh tepat sama dengan total. Paket mengembalikan komponen aktual dari alokasi historis, bukan menciptakan stok produk paket. Barang yang kembali hanya karantina; tidak menambah ready/usable. Refund tunai mencatat kas keluar pada shift terbuka pilihan, tidak mengubah shift asal yang telah ditutup. Refund non-tunai hanya menambah `noncash_refunds`. Histori penjualan/struk asli dipertahankan dan ditampilkan bersama refund terpisah. Pemulihan kuota, bila dipilih, terjadi sekali setelah seluruh unit selesai direfund.

## Batas dan verifikasi

Keputusan D-01/D-03/D-08 tentang kebijakan global belum dijawab; pilihan chat yang disarankan tidak dianggap persetujuan. Implementasi di atas mewajibkan input per dokumen, persetujuan pihak yang berwenang dan bukti manual, sehingga tidak bergantung pada default tersebut. Tax invoice resmi, gateway/webhook/settlement otomatis, bagi hasil/klaim promo lintas organisasi, offline, serta aktivasi produksi tetap di luar cakupan ini.

Laporan **penjualan tunai** tetap membaca struk tunai asli; tidak memasukkan B2B/non-tunai atau mengurangi refund dari metriknya. Gunakan histori refund dan rekonsiliasi shift untuk pengembalian. Laporan komersial piutang/non-tunai/margin kini tersedia pada [DL-20](reliability-and-offline.md), dengan kohor periode dan biaya berbukti terpisah.

Uji `ReturnTest`, `BillingTest`, `PosAdjustmentsTest`, `ReturnWebTest`, `FinancialWebTest` dan `financial_native_test.dart` mencakup tenant/role, stale review, retry/replay, pembayaran parsial, liabilitas kredit, batas kiriman/laporan, promo tanpa reservasi, refund paket multi-batch, rollback semua posting saat ledger gagal, serta lebar390/1200px. Migrasi yang sudah memiliki histori menolak rollback destruktif. PostgreSQL multi-koneksi, perangkat/printer, browser interaktif dan UAT belum diverifikasi.


[Addendum otomatis dan sponsor lintas organisasi](printing-commercial-and-recalls.md) kini menyediakan jalur terpisah dengan dua persetujuan. Larangan pendanaan asing berlaku pada pembuatan voucher biasa tanpa addendum; akses POS umum mitra tidak dibuka. Invoice/laporan manual ditolak pada pesanan yang telah ditautkan ke otomatisasi agar tidak terjadi tagihan ganda. Pembayaran aktual tetap diverifikasi, tanpa gateway/transfer otomatis.
