<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Pusat &amp; Gudang</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Pesanan masuk pusat</h1>
            <p class="text-sm text-slate-600 mt-1">Halaman untuk owner, admin, dan petugas gudang pusat. Pesanan muncul setelah disetujui pimpinan mitra.</p>
        </div>
        <a href="/app" class="button button--lite">← Workspace</a>
    </div>

    <section class="panel mb-6">
        <p class="text-xs text-slate-600 mb-4">Akun mitra atau toko melihat pesanannya lewat menu toko → <strong>Pesanan dan stok</strong>.</p>
        <x-organisms.order-list :orders="$orders" />
    </section>
</x-templates.console>
