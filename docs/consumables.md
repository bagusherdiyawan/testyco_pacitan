# Kemasan dan alat makan booth

Irisan untuk booth: barang non-makanan (kotak, kantong, sendok, piring) dihitung terpisah dari stok dimsum, berkurang otomatis saat pembayaran menurut **jenis penjualan**, dan tidak pernah menolak penjualan saat stok habis.

## Keputusan bisnis yang diterapkan

| Keputusan | Penerapan |
| --- | --- |
| Jenis penjualan | Kasir memilih **Makan di tempat** atau **Bawa pulang** pada keranjang POS. Tiap jenis punya daftar barang dan jumlah sendiri. |
| Satuan aturan | Per **transaksi** (sekali per struk, misalnya 1 sendok) atau per **porsi terjual** (jumlah porsi pada baris satuan porsi). Baris per biji, kemasan dan paket tidak dihitung sebagai porsi. |
| Stok habis | Penjualan tetap selesai. Saldo menjadi negatif, struk dan halaman stok menandai **stok kurang**, dan kekurangan diperbaiki lewat belanja atau hitung fisik. |
| Sumber stok | Dibeli booth sendiri, atau **dikirim langsung dari gudang pusat** (lihat di bawah). |
| Refund | Barang terpakai tidak dikembalikan ke stok. |
| Opname | Hitung fisik oleh pengelola toko; saldo disesuaikan dan selisih dicatat dengan alasan. Tidak ada peninjau kedua. |

Penjualan tanpa jenis penjualan tidak mengurangi apa pun. Pilihan jenis penjualan hanya tampil di POS web bila organisasi sudah punya barang non-makanan aktif.

## Cara memakai (web)

1. **Pengelola organisasi** (owner, admin, pimpinan mitra): buka organisasi → **Kemasan dan alat makan**. Tambah barang, lalu atur aturan per jenis penjualan. Jumlah 0 menghapus aturan.
2. **Pengelola toko**: buka toko → **Stok kemasan dan alat makan**. Catat belanja (menambah stok; bila shift sedang buka dapat sekaligus dicatat sebagai kas keluar kategori `belanja_operasional`) dan hitung fisik harian.
3. **Kasir**: pilih jenis penjualan di keranjang. Setelah bayar, struk menampilkan barang yang terpakai dan peringatan bila stok kurang (peringatan tidak ikut tercetak).

## Kiriman dari pusat ke booth

Pusat mengirim langsung dari gudangnya tanpa menunggu pesanan, memakai alur terpisah dari pesanan dimsum.

1. **Pusat** (owner, admin, atau gudang): buka gudang → **Stok kemasan dan alat makan** → **Kiriman dari pusat dan ke booth**. Pilih booth, barang pusat, dan jumlah. Stok gudang berkurang saat dikirim, dan pengiriman ditolak bila melebihi saldo gudang.
2. **Booth** (pimpinan mitra atau kepala toko): halaman yang sama menampilkan kiriman masuk. Periksa jumlah yang datang dan pilih barang milik booth yang menerimanya (nama yang sama terpilih otomatis). Stok booth bertambah sebesar jumlah diterima. Jumlah kurang dari yang dikirim wajib disertai alasan, dan selisihnya tetap terlihat pada kiriman.

Keputusan yang diterapkan, dan mudah diubah bila pengguna berbeda pendapat:

| Keputusan | Penerapan |
| --- | --- |
| Harga dan tagihan | Tidak ada. Kiriman hanya jumlah, tanpa invoice, piutang atau pembukuan. |
| Katalog | Tiap organisasi punya katalog sendiri; pusat tidak melihat katalog booth dan sebaliknya. Pemetaan dilakukan booth saat menerima. |
| Stok pusat kurang | Pengiriman ditolak (barang fisik tidak bisa dikirim melebihi catatan). Isi stok pusat lewat belanja atau hitung fisik dulu. |
| Selisih | Pusat tidak dikurangi ulang; selisih dicatat sebagai catatan penerima dan jumlah kurang pada kiriman. Retur atau penggantian belum ada. |
| Permintaan dari booth | Belum ada. Booth belum bisa meminta kiriman lewat sistem. |

## API

Lihat `contracts/openapi.yaml` 0.42.0. Semua penulisan memakai `operation_id` UUID; ulangan yang sama tidak menambah stok, kas keluar atau pengurangan dua kali, dan ID yang sama untuk isi berbeda ditolak.

| Endpoint | Akses |
| --- | --- |
| `GET/POST organizations/{org}/consumables…` (barang, status, aturan) | owner, admin, pimpinan mitra organisasi tersebut |
| `GET stores/{store}/consumables` | staf yang ditugaskan ke toko |
| `POST stores/{store}/consumables/purchases`, `…/counts` | owner, admin, pimpinan mitra, kepala toko yang ditugaskan |
| `POST stores/{store}/sales/intents` dengan `service_type` | kasir yang ditugaskan |
| `GET stores/{store}/consumable-shipments` | staf yang ditugaskan; pusat melihat kiriman keluar, booth kiriman masuk |
| `POST stores/{store}/consumable-shipments` | owner, admin, gudang pada organisasi pusat |
| `POST consumable-shipments/{shipment}/receive` | pimpinan mitra atau kepala toko tujuan |

Aturan dibekukan pada struk: `sales.consumption` menyimpan nama, satuan, jumlah, kekurangan dan saldo sesudahnya, sehingga mengubah aturan tidak mengubah struk lama.

## Batas cakupan

- **POS offline** dan **Flutter** belum membawa jenis penjualan, sehingga penjualan dari jalur itu belum mengurangi barang non-makanan.
- Kiriman dari pusat tidak masuk alur **pesanan** dimsum dan tidak punya harga atau penagihan. Permintaan dari booth, retur dan penggantian selisih belum ada.
- Belum ada laporan pemakaian per hari atau penyesuaian harga pokok untuk barang non-makanan; pembukuan otomatis tidak berubah.
- Saldo dihitung dari jumlah ledger; PostgreSQL/concurrency tinggi dan UAT bisnis belum diverifikasi.
