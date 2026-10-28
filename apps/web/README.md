# Testyco web

Laravel Blade frontend terpisah dari API. Token pengguna disimpan pada session server. Modul Operations menyediakan formulir pengajuan/approval, gudang/pengiriman dan penerimaan stok; mutasi dilindungi CSRF, UUID operasi dan authorization API. Lihat panduan pada docs/supply-workflow.md di root.

Halaman stock-control menyediakan pembukaan/pengukusan, hitung fisik dan review independen dengan CSRF, UUID operasi dan snapshot revision.
