# Persetujuan ulang pesanan pada Flutter

Irisan mobile/tablet PRD 04-EXC menggunakan workflow [pengurangan pascapersetujuan](order-reapproval.md) yang telah tersedia. Layar ini terpisah dari composer perubahan sebelum persetujuan; PUT amendment lama tidak diperluas.

## Cara memakai

Penerima membuka **Pesanan → Detail → Persetujuan ulang dan riwayat**. Pemasok membuka **Gudang → Pesanan → Persetujuan ulang dan riwayat**. Link gudang mengikuti FEATURE_ORDERING; flag hanya mengatur presentasi. API GET `orders/{order}/changes?page=n` menentukan capability, status, versi, snapshot dan riwayat. Petugas gudang dapat membaca pesanan berizin, tetapi assignment tidak memberi hak mengusulkan; owner/admin pemasok mengikuti capability propose/withdraw. Pimpinan mitra atau approver transfer internal mengikuti capability approve/reject yang sama dengan web.

Pengusul mengisi seluruh jumlah pada baris existing dan alasan, lalu meninjau perbandingan sebelum/sesudah. Nilai awal ditampilkan sesuai pesanan; pengajuan tanpa pengurangan ditolak. Setidaknya satu baris berkurang, setiap baris minimal1 dan tidak boleh meningkat. Review menunjukkan SKU pusat/lokal, isi kemasan, harga historis, jumlah, total semula/usulan, alasan, tujuan dan versi. Total usulan dihitung untuk tampilan dari harga yang sudah disetujui; request hanya membawa UUID, versi, alasan dan ID baris/jumlah. Server tetap menghitung dan mengotorisasi keputusan. Tidak ada harga/total client, reservasi stok atau pembayaran pada request ini.

Penerima meninjau snapshot alasan pemasok dan jumlah sebelum menerima/menolak. Pemasok dapat menarik usulan pending. Penolakan/penarikan memerlukan alasan; catatan approval opsional. Semua tindakan melalui dialog tinjauan dan konfirmasi terpisah. Riwayat memakai ID/aktor/catatan serta waktu keputusan WIB; tolak/tarik mempertahankan pesanan semula. Setelah kembali, detail pesanan/gudang dimuat ulang agar capability dan kuota kirim terkini ditampilkan.

## Batas state dan retry

OrderChangesRepository memeriksa ID pesanan/parent setiap row dan pagination lokal; URL next_page_url dari API tidak diikuti. Snapshot bertipe dan immutable; baris unik dan pasangan ID/SKU/harga/isi sebelum/sesudah harus konsisten. Data malformed/denial tidak menjadi empty success. Tidak ada inferensi hak dari role/status lokal.

OrderChangesController memisahkan context baca, candidate review dan pending operation. Candidate hanya boleh dikonfirmasi sekali saat context/versinya masih sama; refresh membuang candidate dan isian sebelumnya. Mutasi membekukan nested body, review, UUID dan path sebelum POST. Sukses memerlukan ACK dengan parent/change/status yang cocok dan kenaikan versi tepat1. Sesudah ACK, selalu GET halaman1 karena hasil replay dapat historis; bila reload gagal, pencatatan tetap ditandai tersimpan tetapi tindakan baru diblokir sampai detail dimuat.

Transport error, 5xx, malformed ACK, 408 atau 429 menahan request yang sama. Selama busy/unknown, form, navigasi keluar, refresh, pagination dan tindakan pengganti ditahan. Retry tidak merekonstruksi body/UUID/versi. Penolakan izin/konflik setelah unknown tidak membuang request lama. Respons definitif pada upaya pertama membebaskan pending tetapi mewajibkan context baru. ApiClient 401 tetap memicu reset session/route aplikasi; pending halaman tidak diwariskan ke akun berikutnya.

Request hanya berada dalam memori halaman. Terminasi aplikasi, logout atau sesi berakhir memerlukan tinjauan ulang riwayat sebelum membuat request baru; belum ada penyimpanan operasi lintas restart, rekonsiliasi UUID otomatis atau offline. PopScope dan refresh guard bukan jaminan terhadap terminasi OS. Pesan hasil/unknown dan context terbaru mengembalikan gulir ke atas agar terlihat pada mobile.

## Struktur dan verifikasi

Fitur tetap di modul Ordering existing. Fulfillment hanya menautkan halaman, tanpa mengambil alih aturan domain. Molekul OrderChangeLine dan organisme OrderChangeCard/OrderChangeReview dipakai bersama pada history, review dan pending retry; layout memakai ListView/Wrap serta lebar maksimum960, tanpa fixed row yang memaksa desktop.

22 tes native baru mencakup payload/review immutable, capability/tenant/invalid snapshot, semua baris, batas jumlah/alasan, original version, stale candidate, approve/reject/withdraw, unknown→403→retry identik, malformed ACK, status definitif/429, ACK dengan reload gagal, internal total0, pagination lokal dan 401. Widget menjalankan entry gudang/pesanan pada390px text scale1,5 (termasuk inset keyboard280) dan1200px, preview belum menulis, retry/pop/refresh guard serta approval/rejection.

Kontrak OpenAPI0.20.0, endpoint/schema database, migrasi, dependensi dan izin server tidak berubah pada irisan native ini. PostgreSQL concurrency, browser/Android/iOS/perangkat fisik, UAT dan printer belum diverifikasi. Substitusi/penambahan/penghapusan baris, revisi setelah kirim, retur/pengganti/kredit/refund tetap backlog.
