# Operator produksi dan buku besar manual

Delivery lanjutan9 November2026 menutup dua bagian setelah ERP produksi awal: izin operator per gudang dan buku besar debit/kredit manual. Ini memperluas ADR050 melalui ADR051, tanpa menjadikan seluruh pegawai admin atau otomatis mengimpor ulang transaksi ERP/POS lama.

## Izin operator

Owner/admin pusat membuka **Administrasi organisasi → Bahan baku dan produksi → Izin operator ERP** di web/Flutter. Pilih pengguna dan gudang yang **sudah** ditugaskan pada Identity, pilih berikan/cabut dan isi alasan. Izin menyebut eksplisit akses resep, pemakaian bahan dan biaya proses di gudang tersebut. Tidak membuat akun, mengganti role atau menambah penugasan toko secara tersembunyi.

| Pengguna | Akses ERP |
| --- | --- |
| Owner/admin dengan keanggotaan nyata pusat aktif | Pengelolaan ERP, izin operator, keuangan dan keputusan QC |
| Gudang/kepala toko dengan penugasan aktif **dan grant produksi aktif** | Resep pusat; lot dan proses di gudang yang diizinkan; membuat/membatalkan rencana dan mencatat pemakaian/hasil/biaya proses |
| Gudang/kepala toko tanpa grant atau tanpa penugasan gudang | Ditolak |
| Operator yang diberi grant | Tetap ditolak dari QC, receipt/retur/pembayaran supplier, konfigurasi bahan/resep, opname, izin pengguna dan buku besar |
| Kasir, pimpinan mitra, atau admin pusat tanpa membership organisasi yang diminta | Ditolak dari ERP tersebut |

Di web, operator membuka **toko/gudang → Produksi ERP gudang**. Di Flutter, pilih gudang, lalu modul **Produksi ERP**. Tautan bukan izin: backend memeriksa lagi role/organisasi/gudang/penugasan/grant aktif pada setiap read dan sebelum maupun di dalam transaksi command. Navigasi dan schema hanya memuat aksi operator. Field hasil dari gudang/periode yang berbeda ditolak client sebelum ditampilkan.

Pencabutan berlaku pada permintaan berikutnya, termasuk retry yang sebelumnya belum pasti. Pengelola dapat mencabut grant lama melalui baris riwayat walaupun pengguna/gudang nonaktif atau penugasannya sudah dihapus. Riwayat grant/versi/alasan/aktor dipertahankan. Reaktivasi akun tidak mengganti grant yang sudah dicabut. Mengaktifkan kembali grant tetap mensyaratkan membership/penugasan aktif.

Hasil produksi operator tetap karantina sampai owner/admin melakukan QC. Tidak ada izin operator untuk meloloskan hasilnya sendiri. Pending native tetap hanya di memori halaman; bila akses dicabut saat hasil belum pasti, pengelola perlu memeriksa riwayat sebelum membuat pengganti, bukan memutar UUID.

## Buku besar manual

Owner/admin pusat menggunakan bagian **Akun buku besar**, **Periode pembukuan**, **Jurnal debit/kredit**, dan **Neraca saldo dan ringkasan akun**.

1. Buat akun dengan kode, nama, kelompok aset/liabilitas/ekuitas/pendapatan/beban dan saldo normal debit/kredit yang dipilih eksplisit. Tidak ada bagan akun, saldo atau pajak bawaan. Jenis/saldo normal akun yang sudah dibuat tidak ditimpa lewat layar ini.
2. Buat periode dengan tanggal mulai/akhir inklusif; periode tidak boleh bertumpang tindih. Tanggal jurnal menggunakan kalender WIB, tidak menerima tanggal masa depan atau di luar periode terbuka.
3. Buat draft jurnal: nomor unik organisasi, periode/tanggal, debit dan kredit per akun, bukti dan catatan. Nominal integer IDR; setidaknya dua akun, total positif dan debit=kredit. Satu akun tidak sekaligus debit dan kredit pada satu jurnal. Draft **belum** mempengaruhi buku besar.
4. Owner/admin lain meninjau dan memilih bukukan/tolak. Pembuat tidak dapat menyetujui jurnal sendiri. Setelah dibukukan, isi tidak diedit/dihapus. UUID replay mengembalikan receipt historis tanpa menambah jurnal lagi.
5. Koreksi jurnal posted memakai **Balik jurnal dengan jejak**, dilakukan pengelola berbeda dari pembuat aslinya: pilih periode terbuka, nomor/tanggal pembalikan, bukti dan alasan. Sistem membuat tepat satu lawan debit/kredit dari snapshot asli, menyimpan reversal_of/reversed_by dan mempertahankan jurnal asli. Pembalikan tidak boleh diduplikasi, mendahului tanggal asli atau membalik pembalikan lagi. Koreksi berikutnya dibuat sebagai jurnal baru yang ditinjau, bukan menulis ulang sejarah.
6. Tutup periode setelah semua draft selesai. Aktivitas jurnal (pembuatan/review/pembalikan dalam periode target) menaikkan versi periode sehingga review penutupan lama ditolak. Buka kembali memerlukan versi dan alasan. Jurnal di periode tertutup tidak dapat dibuat/dibukukan. Pembalikan jurnal periode lama yang sudah ditutup boleh dibukukan di periode baru yang terbuka; saldo historis periode lama tidak ditulis ulang.

## Laporan dan rekonsiliasi

Pilih periode secara eksplisit. Neraca saldo menghitung **saldo awal sebelum periode, mutasi debit/kredit dalam periode, dan saldo akhir bertanda** dari jurnal posted saja. Debit positif, kredit negatif; draft/rejected tidak masuk. Jurnal asli yang dibalik tetap masuk sesuai tanggal aslinya, dan jurnal lawannya masuk pada tanggal pembalikan. Uji dua periode membuktikan saldo awal berpindah dan pembalikan tidak mengubah laporan periode lama.

Ringkasan menyajikan mutasi neto akun pendapatan/beban dan selisih nominal periode, aset/liabilitas/ekuitas sampai akhir periode serta hasil kumulatif akun nominal yang belum ditutup. Jurnal penutupan manual ikut dalam mutasi akun tersebut; belum ada klasifikasi jurnal penutupan untuk laporan laba-rugi formal. Ini proyeksi dari akun dan jurnal yang dimasukkan, bukan klaim kelengkapan kondisi keuangan nyata atau penutupan laba otomatis. Saldo awal harus dimasukkan sebagai jurnal berimbang berdasarkan bukti. Pembacaan akun/periode/jurnal memakai transaksi dan kunci organisasi yang sama dengan penulisan agar laporan tidak mencampur dua keadaan buku besar. Laporan ditolak jika debit/kredit atau saldo penutup tidak seimbang. Batas1000akun/periode pilihan dan10.000jurnal/8MiB sampai akhir periode mencegah pemotongan diam-diam; laporan ini belum ekspor/jadwal pelaporan besar.

**Tidak ada posting ERP/POS otomatis pada delivery ini.** Tidak memilih akun kas/persediaan/utang/HPP/pajak atau membukukan saldo awal ERP secara diam-diam. Gunakan bukti/referensi transaksi asal dan rekonsiliasi oleh pengelola/akuntan; jangan mencatat pergerakan yang sama dua kali atau menjumlahkan HPP proses antara dengan proses akhir sebagai beban perusahaan. Pemetaan akun, kebijakan akrual/pajak, impor historis, jurnal otomatis event bisnis, arus kas akuntansi dan penutupan laba otomatis masih perlu delivery khusus. Pembayaran supplier/laci POS tetap workflow tersendiri dan tidak dipanggil oleh jurnal manual. Auto bank/gateway/push/hold recall lintas organisasi tetap dilewati.

## Implementasi dan batas verifikasi

`Accounting/Application/GeneralLedger` mengelola akun/periode/jurnal, dan `AccountingReadModel` memakai scope/filter serta projection tersendiri; provider Manufacturing mengoordinasikan rute ERP yang sama. `OperatorPermissions` dan `ErpAccess` membatasi gudang/aksi. Org lock, operasi UUID/actor/body, versi, audit dan transaksi yang sama mencegah posting parsial; grant serta jurnal tidak mengubah stok atau laci kas. Schema baru pada migrasi26; rollback riwayat yang telah digunakan ditolak.

Web hanya memakai Backend HTTP terautentikasi; native memakai ErpRepository dan form/review atomic bersama. Capabilities can_manage/can_operate bukan pengganti otorisasi server, pagination dibangun lokal, unknown ACK/5xx/408/429 mempertahankan request beku dan auth boundary membersihkan state akun. API/web/Flutter serta HTTP terisolasi diuji; build APK/perangkat fisik, PostgreSQL concurrency, audit akuntan dan UAT belum dinyatakan selesai.
