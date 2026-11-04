# DL-18–DL-20: operasi, keamanan dan pelaporan

Implementasi development 4 November 2026. Keputusan pengguna di chat: offline **tunai saja dengan review konflik**, SOP diaktifkan **pimpinan masing-masing organisasi**, kanal email **SMTP dahulu**. Push ditunda sesuai pilihan pengguna. Tidak ada nilai umur simpan makanan, kebijakan negatif stok atau kewajiban biaya aset yang dibuat otomatis.

## DL-18: inbox, SMTP dan SOP

Inbox web `/app/inbox` dan ikon Inbox Flutter menyaring ulang pengguna, organisasi aktif, role dan penugasan toko saat dibaca/dikirim. Event disimpan dalam transaksi domain, deduplikasi per pengguna/event; tandai baca idempoten. Cakupan event: pengajuan/keputusan pesanan, pengiriman/penerimaan, usulan tagihan/kredit/pembayaran, hitung stok, aset, SOP/recall. Peringatan stok rendah mengikuti ambang yang dikonfigurasi dan satu notifikasi per episode turun di bawah ambang; tidak membuat pesanan otomatis. Ini bukan sistem eskalasi semua exception bisnis.

Email inbox harus diaktifkan pengguna dan administrator deployment. Email berisi pemberitahuan umum, bukan harga, detail transaksi atau tautan kredensial. Outbox memiliki lease dua menit, batas lima percobaan dan backoff; pengiriman SMTP bersifat **at least once**, sehingga email dapat terulang bila proses berhenti setelah SMTP menerima pesan tetapi sebelum ACK database. Isi inbox tetap satu. Kegagalan transport dicatat sebagai kode generik. Pengiriman yang kehabisan percobaan/pernah terhenti perlu ditinjau operator; tidak dinyatakan sukses otomatis.

Organisasi → SOP stok hanya untuk owner/admin/partner_lead organisasi sendiri; hak onboarding pusat tidak memberi hak menyalakan SOP mitra. Isi referensi persetujuan, pilihan blok tanggal kemasan, FEFO off/suggest/enforce dan menit masa simpan siap jual atau null secara eksplisit. Tanggal kemasan berlaku inklusif hingga akhir hari WIB. Stok siap yang diwajibkan masa simpannya tetapi belum mempunyai waktu sah diblokir. Pimpinan dapat mencatat waktu pengolahan aktual dengan revisi stok dan bukti; tidak boleh waktu masa depan atau memperpanjang batas lama. Hasil kukus baru memakai menit SOP, campuran memakai batas paling awal; stok lama yang waktunya tidak diketahui tetap memerlukan pemeriksaan.

Recall memakai produk dan kode lot yang benar-benar ada di organisasi tersebut, langsung memblokir penjualan/pengolahan/pengiriman tanpa mengurangi saldo. Pencabutan memerlukan alasan dan versi. Recall ini **berlaku pada organisasi penerbit**; tidak otomatis mengubah kebijakan/lot milik organisasi lain atau menyatakan karantina aman. Koordinasi recall antarorganisasi mengikuti komunikasi operasional terpisah.

POS web → Saran FEFO membaca batch layak berdasarkan batas paling awal, kondisi sama dan isi kemasan sama untuk bucket usable. Tidak mereservasi atau memilih batch otomatis. FEFO enforce diperiksa kembali di server pada penjualan online/offline, pengolahan dan pengiriman asli/pengganti. Konflik tidak menimbulkan posting sebagian. Pencarian kedaluwarsa yang lama tetap mempunyai urutan/pagination/filter sendiri; menyalakan SOP adalah tindakan terpisah.

## DL-19: MFA, sesi dan offline tunai

Web Keamanan akun dan ikon Keamanan Flutter menyediakan enrollment authenticator SHA1/6 digit/30 detik dan sepuluh kode recovery sekali pakai. Secret disimpan terenkripsi APP_KEY; recovery disimpan hash. Enrollment pending berlaku sepuluh menit. Kode TOTP memakai toleransi satu langkah dan menolak replay counter. Password benar pada akun MFA hanya menghasilkan tantangan dua menit/lima percobaan, belum bearer token. Enable/disable/reset/ganti sandi mencabut sesi dan tantangan; perubahan akses juga membatalkan izin offline melalui access_version. Daftar/revoke sesi hanya milik sendiri.

Reset email melalui halaman web atau permintaan dari login Flutter, dengan SMTP dan URL web HTTPS yang dikonfigurasi. Job reset terenkripsi di queue. Token tunggal ter-hash/berbatas waktu dan dikirim sebagai fragment URL; JS memindahkan ke form dan membersihkan fragment. Akun MFA tetap memerlukan faktor saat reset. Jika seluruh faktor hilang, pemulihan organisasi di luar aplikasi diperlukan; tidak ada bypass MFA lewat email. Backup APP_KEY dengan aman agar secret dan job lama dapat dipulihkan.

Flutter menyimpan sesi dan outbox AES-256-GCM dengan nonce, MAC dan AAD per key; kunci AES berada pada OS Keychain/Keystore melalui flutter_secure_storage. Berkas ditulis flush/temp/rename; Android backup aplikasi dimatikan. Sesi terikat API origin/pengguna dan expiry delapan jam. Restore online memeriksa `/auth/me`; saat jaringan tidak tersedia hanya layar offline dengan izin tersimpan. Data rusak, kunci hilang atau penulisan terputus menahan operasi dan mempertahankan berkas. Jangan hapus data/uninstall selama antrean belum selesai. Tidak ada fallback plaintext. Tes filesystem/enkripsi tersedia; Keystore serta terminasi/restart **perangkat fisik** tetap release gate.

Alur kasir:

1. Online, buka shift lalu POS → Offline tunai. Terbitkan izin durasi eksplisit 5–480 menit. Katalog harga/resep dan batch hanya milik toko tersebut; maksimal1000 produk/2000 batch. Ini snapshot, bukan reservasi atau jaminan stok lintas perangkat.
2. Pilih izin, produk/unit/batch/komponen, jumlah serta uang diterima aktual. Review dan konfirmasi fisik; UUID dan payload disimpan terenkripsi sebelum bukti lokal ditampilkan. Bukti `OFF…` **sementara**, bukan struk server. Voucher dan non-tunai ditolak.
3. Setelah online, sinkronkan. UUID sama mengembalikan hasil sama; outbox tidak dihapus saat logout/restart atau respons gagal. Setelah ACK selalu baca status terbaru karena replay dapat mengembalikan konflik historis.
4. Server memeriksa waktu/akses, harga/resep, shift, stok, SOP dan FEFO. Jika sesuai, sale/ledger/kas diposting atomik sekali. Jika berubah/kurang, catatan immutable menjadi konflik tanpa posting keuangan/stok.
5. Pengelola membuka POS web → Antrean dan konflik offline. Terima hanya setelah verifikasi uang/kejadian fisik, referensi, versi dan shift terbuka **kasir asal yang sama**. Harga/jumlah asli tidak boleh diganti; API dapat mengalihkan batch layak SKU/unit sama. UI web memakai alokasi asli. Jika tidak dapat dibukukan, void setelah rekonsiliasi/refund nyata; tidak menciptakan kas keluar fiktif untuk sale yang belum pernah dibukukan.
6. Kasir memastikan semua status diterima/voided, mengakhiri izin dengan deklarasi antrean kosong, lalu menutup shift. Izin terbuka/konflik memblokir penutupan. Server tidak mengetahui berkas perangkat yang belum pernah dikirim; deklarasi kasir wajib setelah pemeriksaan fisik semua terminal.

Antrean durable ini khusus POS offline tunai. Mutasi native online lain tetap mempunyai batas pending memori halaman sesuai dokumentasinya; tidak otomatis masuk antrean lintas restart. Outbox satu akun tidak ditampilkan kepada akun lain. Jam perangkat dan integritas perangkat tidak dianggap bukti anti-fraud; review serta rekonsiliasi fisik tetap diperlukan.

## DL-20: aset dan laporan komersial

Organisasi → Freezer/perangkat: nomor aset unik, merek/seri, kondisi dan bukti kepemilikan privat pemilik. Penempatan lintas organisasi memerlukan kontrak diterima yang mencakup tanggal serah terima; pemilik mengusulkan, pimpinan penerima menyetujui fisik. Hanya satu penempatan pending/aktif. Insiden/perawatan menyimpan bukti, hasil dan pembagian biaya aktual eksplisit tanpa membuat invoice otomatis. Pengajuan kembali belum melepaskan aset; pemilik memeriksa penerimaan fisik dan memilih available/maintenance. Insiden terbuka menahan available. Mutasi/move melalui kembali lalu penempatan baru, riwayat dan nama toko snapshot tetap tersimpan. Penerima hanya melihat penempatan yang masih berhak; bukti kepemilikan serta lokasi penerima berikutnya tidak dibuka.

Organisasi → Laporan B2B/piutang/margin memisahkan retail organisasi sendiri, grosir pemasok dan pembelian mitra; tidak membaca POS mitra tanpa keanggotaan. Periode eksplisit <=31 hari memilih sale sold_at dan invoice accepted decided_at. Refund/kredit/pembayaran/biaya dihitung **kumulatif saat dibaca terhadap dokumen terpilih**. Ini posisi kohor dokumen, bukan arus kas periode atau seluruh saldo piutang sepanjang waktu. Saldo, overdue WIB dan kredit pelanggan hanya invoice terpilih. B2B net goods tidak memasukkan pajak/ongkir. Laporan tunai lama tetap memakai struk tunai asli.

HPP/biaya operasional tidak ditebak dari harga beli/master: pengelola memasukkan biaya bersih aktual dokumen dengan bukti dan versi append-only. Tanpa bukti, margin null/belum diketahui, bukan nol. Setelah retur/refund, biaya bersih perlu ditinjau kembali menurut bukti. Pembelian bukan laba pemasok. CSV privat memakai pelindung formula dan batas10000 dokumen/8MiB; jika terlampaui harus mempersempit periode, tidak memotong data diam-diam. Pelaporan ini bukan jurnal akuntansi umum, valuasi otomatis persediaan atau bagi hasil otomatis.

## Menjalankan SMTP dan worker

Pada API, set secrets SMTP melalui konfigurasi deployment: MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_SCHEME sesuai provider, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS dan WEB_PUBLIC_URL HTTPS. MAIL_TIMEOUT default10 detik, maksimum60. Aktifkan RELIABILITY_SMTP_ENABLED=true dan SECURITY_EMAIL_RESET_ENABLED=true hanya setelah transport/URL diverifikasi; default keduanya false. Jangan masukkan kredensial ke Git.

Jalankan worker `php artisan queue:work --tries=3 --timeout=60` untuk reset sandi dan scheduler `php artisan schedule:work` (atau cron schedule:run per menit) untuk inbox. Untuk pengiriman manual terukur gunakan `php artisan reliability:deliver --limit=100`. Kelola worker dengan supervisor, pantau failed_jobs/notification_deliveries dan backup APP_KEY. Compose development API/web tidak memulai worker/scheduler otomatis.

Uji `python3 scripts/smoke-smtp.py` hanya memakai penerima fixture dan SMTP lokal tanpa kredensial eksternal; memverifikasi job reset terenkripsi, dua pesan diterima dan tidak ada detail transaksi pada email inbox. Pengiriman provider SMTP produksi belum diuji karena kredensial belum tersedia. Push tidak diaktifkan sesuai keputusan pengguna.

Bukti tes dan batas rilis: [verification](verification.md). PostgreSQL/concurrency, backup/restore, device/printer, deployment HTTPS serta UAT tetap gate produksi; tidak membuka kembali DL-18–DL-20 yang selesai pada cakupan development ini.
