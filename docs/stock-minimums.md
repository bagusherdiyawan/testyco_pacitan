# Ambang stok minimum dan peringatan

Irisan awal PRD 05-MIN, terkait PRD 13-NOT/11-KPI. Fitur menyediakan pengaturan ambang dan peringatan di dalam aplikasi saat halaman dimuat. Belum ada event penyeberangan ambang yang disimpan, inbox notifikasi, push/email, scheduler, status baca atau saran pengadaan otomatis. Tidak ada pesan yang dikirim ke orang lain.

## Operasional

Jalankan `scripts/setup` untuk migrasi non-resetting `inventory_minimums`. Dari toko pada web, buka **Pesanan dan stok → Ambang stok minimum dan peringatan**. Owner/admin organisasi, pimpinan mitra dan kepala toko yang ditugaskan boleh mengatur ambang. Kasir/gudang yang ditugaskan dapat membaca, tanpa hak mengubah. Akses admin pusat untuk onboarding tidak membuka data mitra.

Ambang memakai **biji/satuan dasar**, per SKU dan toko. Tidak ada nilai bawaan atau turunan dari isi kemasan. SKU tanpa pengaturan tidak dipantau, termasuk saat belum ada stok. Pengelola mengisi ambang dan alasan, lalu menyimpan. Kosongkan ambang untuk menonaktifkan; `0` adalah ambang aktif, bukan perintah menghapus. Versi tersimpan meningkat; bila pengelola lain sudah mengubahnya, sistem menolak versi lama dan meminta muat ulang. UUID operasi menjaga retry tidak menggandakan perubahan/audit. Histori konfigurasi tercatat dalam audit berizin.

Peringatan menggunakan `stok tersedia < ambang`. Sama dengan ambang tidak rendah. Stok tersedia = jumlah utuh + terbuka + siap dari seluruh batch SKU di toko yang sama; karantina dan susut dikecualikan. SKU aktif tanpa batch dihitung nol. SKU nonaktif disembunyikan dan konfigurasinya tetap disimpan. Toko nonaktif tidak bisa membuka/mengubah laporan operasional ini.

Ini agregat stok fisik seperti angka available pada batch, bukan jumlah siap saji, reservasi, penerapan FEFO, pemeriksaan kedaluwarsa atau sertifikasi mutu. Stok beku dan hasil olahan SKU yang sama tetap dijumlahkan; rincian keadaan ditampilkan agar petugas tidak mengira semuanya siap saji. SOP masa simpan hasil olahan masih keputusan operasional tersendiri.

Web menyediakan pencarian SKU/nama, filter hanya stok rendah, pagination dan waktu observasi server. Flutter: tab **Stok → Stok minimum dan peringatan**, default hanya stok rendah; matikan filter untuk melihat SKU yang belum dipantau. Pengaturan ambang saat ini melalui web. Kedua UI dapat dimuat ulang; saldo lama tidak diberi label realtime. Kegagalan jaringan ditampilkan sebagai error, bukan dianggap semua stok mencukupi.

## Contoh dan batas

Ambang 140 biji, saldo 136 utuh + 0 terbuka + 6 siap = 142: tidak ada peringatan. Penjualan 4 biji membuat total 138, lalu muat ulang menampilkan kekurangan 2 biji dari ambang. Karantina 16 biji tidak menutup kekurangan tersebut. Setelah restock mengembalikan jumlah sampai 140 atau lebih, peringatan hilang saat dimuat ulang. Tidak ada purchase order, permintaan pembayaran atau pergerakan stok yang dibuat oleh peringatan.

API: `GET /api/v1/stores/{store}/inventory/minimums` dengan `q`, `low_only=0|1`, `page`; `POST .../minimums/{product}` dengan UUID, version, minimum_pieces nullable dan reason. Laporan dipaginasi 50 SKU; tiap halaman adalah observasi baru, bukan snapshot stok konsisten lintas halaman. Harga grosir/eceran tidak dimuat. Flutter mengikuti path pagination berizin, tidak mempercayai next_page_url eksternal.

Threshold ditulis hanya oleh Inventory; query proyeksi membaca identitas produk tanpa mengubah Catalog/Identity. Tidak ada modul Reliability baru yang dinyatakan selesai. Tes development memakai SQLite; concurrency PostgreSQL dan UAT stok minimum belum diverifikasi. Ambang bisnis aktual harus diisi pengelola, bukan fixture atau nilai contoh. Rollback skema yang sudah berisi konfigurasi ditolak; gunakan migrasi maju yang ditinjau.
