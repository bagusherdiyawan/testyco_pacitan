# Pola pengembangan modul

Laravel sudah memiliki Identity, Catalog, Ordering, Inventory, Fulfillment dan Workspace; web memakai Operations untuk presentasi alur supply. Flutter menampilkan katalog, stok dan pesanan nyata; hanya Sales masih shell. Tiga belas domain pada ADR tetap target bertahap.

Contoh struktur fitur backend saat use case pertama dibangun:

```text
app/Modules/Ordering/
  Domain/              # state machine dan invariant approval
  Application/         # SubmitRequest, ApproveRequest
  Infrastructure/      # persistence, event/outbox adapter
  Presentation/        # HTTP controller, request, resource
  OrderingServiceProvider.php
```

Tes ditempatkan pada `tests/Feature/Ordering` untuk HTTP/tenant/persistence dan `tests/Unit/Ordering` untuk invariant murni. Jangan membuat empat folder kosong per fitur untuk memberi kesan implementasi lengkap. Provider modul didaftarkan eksplisit pada `config/modules.php`; config cache harus dibangun ulang setelah mengubah daftar provider. Provider hanya berasal dari kode yang direview, bukan nama kelas dari request.

Use case identitas, assignment toko dan katalog awal sudah tersedia. Katalog B2B dan ordering awal sudah tersedia; lihat docs/supply-workflow.md untuk batasnya. Buka/kukus dan opname/susut berapproval sudah tersedia dalam Inventory; berikutnya resolusi selisih pengiriman dan kontrol lanjutan sesuai roadmap. Tambahkan kontrak OpenAPI bersamaan endpoint nyata. Gunakan prefix `/api/v1`, JSON resource stabil, pagination, error validation 422, forbidden 403, unauthenticated 401. Setiap endpoint menulis data harus diuji dengan akun mitra lain dan permission yang tidak cukup. Health dan descriptor fondasi adalah endpoint publik; jangan menaruh informasi tenant di sana.

Web memakai modul presentasi sendiri; view tidak menggunakan Eloquent API dan tidak memanggil service domain backend. Client HTTP terpusat berada di modul Identity/Infrastructure/Backend dan meneruskan bearer token server-side. Web membersihkan sesi ketika API menolak token.

Flutter: setiap fitur memisahkan presentation, application, domain dan data ketika lapisannya dibutuhkan. Widget reusable berada di design system; halaman fitur memiliki state/use case. Untuk produksi, sebelum menambah transaksi, pilih state management (usulan Riverpod), client HTTP, secure storage dan SQLite sync berdasarkan kebutuhan pilot, lalu pin dependensi dan tes lifecycle. Adapter package:http sudah dibuat untuk login/katalog; Riverpod, secure storage dan SQLite sync belum dibuat.
