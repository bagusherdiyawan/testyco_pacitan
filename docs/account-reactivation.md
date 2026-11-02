# Reaktivasi akun melalui API dan web

Irisan siklus akun PRD 01-USR/ACL/AUD. Pada web **Organisasi → Pengguna**, akun nonaktif selain owner/akun sendiri mempunyai link **Tinjau reaktivasi akun**. Halaman khusus menampilkan identitas, organisasi, role, penugasan toko pada organisasi itu, jumlah membership global dan versi akses. Capability berasal dari API. Link bukan pemberian izin; jika status/hak tidak memenuhi, tombol konfirmasi tidak tersedia.

## Hak dan efek

Status active berada pada User secara global, bukan per membership. Pengelola organisasi dapat mereaktivasi anggota yang hanya mempunyai satu membership. Identitas lintas organisasi memerlukan owner/admin pusat yang masih aktif; data review tetap hanya memuat role/toko pada organisasi route dan jumlah membership, tanpa menyalin rincian organisasi lain. Owner pada membership mana pun dan akun sendiri dilindungi. Perlindungan owner global juga diterapkan pada deaktivasi, menutup celah memilih route membership nonowner untuk menonaktifkan identitas owner. Perbandingan ID akun sendiri pada perubahan membership dinormalisasi sebagai integer, termasuk ID dengan nol di depan.

Organisasi review harus aktif dan akun harus nonaktif saat commit. Organisasi/toko nonaktif lainnya tidak diaktifkan. Reactivation tidak mengubah password, role, membership, assignment atau histori bisnis. Semua token target dicabut; tidak ada token baru dalam respons. Pengguna harus login kembali dengan kredensialnya yang ada. Token yang dicabut saat deaktivasi tidak hidup kembali sesudah aktivasi. Tidak ada email reset, MFA, reaktivasi owner, pembuatan/penggabungan membership lintas organisasi atau layar admin pengguna Flutter baru.

## Versi, transaksi dan audit

Migrasi `2026_11_02_000007_add_user_access_version` menambah users.access_version default1 tanpa reset data. Deaktivasi, perubahan role/assignment dan reaktivasi mengunci row User yang sama dan menaikkan versi. Perubahan membership tetap dapat dilakukan pada akun nonaktif, tetapi membuat tinjauan reaktivasi lama stale. Versi tidak boleh dikirim sebagai perubahan atribut user umum.

GET `organizations/{organization}/users/{user}/reactivation` mengembalikan context/capability private/no-store. POST `.../reactivate` membutuhkan operation_id UUID, version dari review dan alasan1–2000 karakter. Izin dan exact membership diperiksa sebelum idempotency lookup serta ketika User terkunci. Versi/state/organisasi yang berubah menghasilkan409 sebelum write. Payload divalidasi; role/assignment/password/active dari client tidak digunakan.

Shared Operations menyimpan hasil dalam transaksi yang sama dengan active/version, pencabutan token dan audit. UUID/aktor/scope/body sama mengembalikan hasil historis; perbedaan ditolak409. Contoh: reaktivasi versi1→2, lalu deaktivasi→3; retry UUID reaktivasi lama mengembalikan ACK versi2 tetapi akun tetap nonaktif versi3. Selalu baca context terkini setelah ACK. Legacy deactivate tetap204 tanpa UUID/version wajib; setiap panggilan yang diterima menaikkan versi dan audit memakai keadaan sebelum yang sebenarnya, termasuk bila sudah nonaktif.

Audit user.reactivated menyimpan actor, organisasi pemanggilan, target, active/version sebelum/sesudah dan alasan. Tidak menulis password/token atau stok/kas. Aksi ini ditambahkan secara eksplisit pada allowlist onboarding pusat; audit operasional/penjualan mitra tetap tersembunyi. Perubahan status global tidak menggandakan event ke organisasi lain; endpoint ini tidak menjanjikan notifikasi lintas organisasi.

Rollback migrasi ditolak bila versi telah maju atau receipt reaktivasi ada. Gunakan perbaikan maju/restore yang ditinjau agar stale-review guard dan hasil idempoten tidak hilang. SQLite/row-lock code belum membuktikan concurrency PostgreSQL multi-koneksi.

## Frontend dan hasil belum pasti

Web BFF memakai Backend HTTP, tanpa query DB API. Envelope organisasi/user dan seluruh row organisasi penugasan diperiksa; snapshot ditampilkan melalui organisme reusable user-access-summary dengan Blade escaping. POST membaca context lagi dan memeriksa capability/versi sebelum membuat request. Alasan wajib dan form CSRF; field UUID tidak diputar pada retry.

Session menyimpan immutable path/body/review sebelum POST. Hilangnya respons,5xx,408/429 atau ACK yang salah menahan satu request per identitas User global, termasuk bila dibuka lewat organisasi lain. GET organisasi lain mengarahkan ke review asal tanpa API; retry tetap memakai path organisasi asal. GET pending tidak meminta context API lagi; POST pengganti ditolak dan retry mengabaikan input browser. Penolakan izin/konflik setelah unknown mempertahankan payload lama. Initial error definitif membebaskan request baru. ACK harus cocok organisasi/user/active=true/version+1, lalu redirect ke review terkini; ACK historis bukan jaminan status saat ini.

401/logout menghapus session dan successful login membersihkan pending akun sebelumnya. Tidak ada jaminan recovery request lintas session/perangkat/restart. Setelah session hilang, periksa status/riwayat sebelum mengirim operasi baru.

## Verifikasi

7 tes API baru: preserve assignment/password, revoke token, exact replay termasuk sesudah deaktivasi berikutnya, UUID collision/stale, deny role/tenant/wrong parent, owner/self/ID nol depan, multi-org/organisasi nonaktif, membership invalidates review, input/state, rollback used-history, onboarding audit dan login nyata dengan token lama tetap ditolak.8 tes web: entry organisasi, escaped scoped review/capability, foreign envelope/assignment, authoritative confirmation, unknown→403→retry identik, malformed ACK/401, validation/stale/422,429 dan account switch. HTTP terisolasi memakai form CSRF, deaktivasi/token lama401, reaktivasi/replay satu event, login baru serta assignment semula, tanpa ledger baru.

Kontrak OpenAPI0.21.0 mempunyai79 operasi. Verifikasi ini server/HTML/widget regresi; interaksi browser/perangkat fisik, PostgreSQL concurrency, deployment dan UAT tetap perlu pengujian tersendiri.
