# Penutupan buku formal dan laporan keuangan

Implementasi ADR055 menambah menu **Penutupan buku formal**, **Laporan laba rugi**, dan **Posisi keuangan** pada web dan Flutter. API menjadi otoritas perhitungan dan izin. Owner/admin pusat atau pimpinan mitra hanya mengakses buku organisasinya sendiri; kewenangan onboarding pusat tidak membuka buku mitra.

## Alur penutupan

1. Buat COA/periode dan catat/review saldo awal secara eksplisit. Selesaikan jurnal draft, antrean sumber dan penyesuaian invoice sampai akhir periode, termasuk antrean lama. Tutup periode sebelumnya. Tanggal akhir closing tidak boleh di masa depan.
2. Pilih akun ekuitas laba ditahan aktif, saldo normal kredit, serta catatan rekonsiliasi. Tidak ada nomor akun atau akun laba ditahan bawaan.
3. Sistem membekukan snapshot buku posted, hash bukti, versi periode, semua baris jurnal penutup dan jumlah laba kumulatif belum ditutup. Proposal tidak membukukan jurnal.
4. Pengelola berbeda memeriksa snapshot lalu menyetujui atau menolak. Perubahan buku/akun atau aktivitas periode menyebabkan penolakan persetujuan; proposal harus ditolak dan dibuat ulang. Proposal aktif unik per periode.
5. Persetujuan atomik memindahkan saldo seluruh akun pendapatan/beban yang belum ditutup ke ekuitas yang dipilih dan mengunci periode. Laba negatif mendebit laba ditahan. Saldo nominal nol tidak membuat jurnal kosong. Zero net profit dengan akun pendapatan/beban yang masih bersaldo tetap menghasilkan jurnal penutup berimbang.

Laba yang dipindahkan dapat mencakup hasil periode sebelumnya yang hanya dikunci melalui **Tutup periode**, belum ditutup formal. Snapshot menjelaskan laba periode dan laba kumulatif secara terpisah. Tombol lama tetap berfungsi sebagai penguncian operasional dan tidak diklaim memindahkan laba ke ekuitas. Draft proposal closing aktif harus diselesaikan sebelum penguncian biasa.

## Koreksi dan pembukaan kembali

Gunakan **Periode pembukuan → Buka kembali**, dengan versi terkini dan alasan. Sistem menyimpan jurnal lawan persis untuk transfer closing pada tanggal akhir periode asal, menandai snapshot reopened, dan mempertahankan semua transaksi usaha. Jurnal penutup tidak bisa dibalik melalui journal_reverse atau dipakai sebagai jurnal rekonsiliasi sumber lain. Jika periode setelahnya telah ditutup formal, buka kembali dari periode paling akhir dahulu. Setelah koreksi sumber selesai, ajukan snapshot baru dan minta persetujuan pengelola lain.

Web dan Flutter memakai form/review/ACK generik yang sama dengan ERP: unknown/later-denial mempertahankan UUID/path/body/versi yang sudah ditinjau; tidak membuat operasi baru diam-diam. Audit perintah dicatat seperti operasi ERP lain, termasuk alasan pembukaan kembali. Replay tidak menggandakan jurnal.

## Laporan

**Laba rugi** menjumlah jurnal posted di tanggal periode terpilih dan mengecualikan jurnal transfer penutupan serta pembukaannya. Pendapatan/beban usaha tetap terlihat setelah closing. **Posisi keuangan** memakai saldo kumulatif sampai akhir periode, termasuk closing: aset = liabilitas + ekuitas + hasil belum ditutup. Saldo kredit/debit yang berlawanan ditampilkan bertanda, tidak dipaksa nol atau dipindahkan kelompok tanpa bukti.

Laporan menyertakan jumlah antrean/draft/periode terdahulu terbuka dan menandai laporan sementara bila masih ada yang tertahan. Pembalikan transaksi usaha tetap memasukkan jurnal asli dan lawannya menurut tanggal masing-masing. Nilai hanya berasal dari buku posted; laporan bukan bukti semua transaksi usaha sudah dimasukkan. Pajak legal, klasifikasi principal/agent, rekonsiliasi bank eksternal dan pengesahan akuntan tidak ditetapkan oleh fitur ini.

Batas pembacaan:1000akun,10.000jurnal/8MiB sampai akhir periode serta10.000riwayat closing. Sumber berlebih atau buku tidak seimbang ditolak sebelum mengirim laporan parsial. Laporan menggunakan integer rupiah, tanpa floating point. Tidak ada reset jurnal, saldo awal otomatis atau penghapusan riwayat saat pergantian periode.
