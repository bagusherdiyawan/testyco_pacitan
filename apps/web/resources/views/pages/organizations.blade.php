<x-templates.console>
    <h1>Workspace organisasi</h1>
    <section class="module-grid">
        @foreach($organizations as $org)
            <article class="module-card">
                <x-atoms.badge>{{ $org['type'] === 'center' ? 'Pusat' : 'Mitra' }} · {{ $org['active'] ? 'Aktif' : 'Nonaktif' }}</x-atoms.badge>
                <h2>{{ $org['name'] }}</h2>
                @if(($org['can_activate'] ?? false) && $org['type'] === 'partner')
                    <form method="post" action="/app/organizations/{{ $org['id'] }}/status">
                        @csrf
                        <input type="hidden" name="active" value="{{ $org['active'] ? '0' : '1' }}">
                        <x-atoms.button>{{ $org['active'] ? 'Nonaktifkan mitra' : 'Aktifkan mitra' }}</x-atoms.button>
                    </form>
                @endif
                @if($org['can_manage'])
                    <p><a href="/app/organizations/{{ $org['id'] }}">Kelola toko dan pengguna</a></p>
                @endif
                @if(app()->getProvider(\App\Modules\Reporting\ReportingServiceProvider::class) && ($org['active'] ?? false) && in_array($org['role'] ?? null, ['owner','admin','partner_lead','store_manager'], true))
                    <p><a href="/app/organizations/{{ $org['id'] }}/reports/cash-sales">Laporan penjualan tunai</a></p>
                @endif
                @if($org['can_catalog'])
                    <p><a href="/app/organizations/{{ $org['id'] }}/catalog">Katalog dan harga</a></p>
                @endif
            </article>
        @endforeach
    </section>
    <x-molecules.pagination :items="$organizations" />
    <h2>Toko yang ditugaskan</h2>
    <ul>
        @forelse($stores as $store)
            <li>{{ $store['name'] }} · {{ $store['active'] ? 'Aktif' : 'Nonaktif' }} · <a href="/app/stores/{{ $store['id'] }}/operations">Pesanan dan stok</a></li>
        @empty
            <li>Belum ada toko yang dapat diakses.</li>
        @endforelse
    </ul>
    <x-molecules.pagination :items="$stores" />
    @if($organizations->getCollection()->contains(fn($org) => $org['type'] === 'center' && $org['can_manage']))
        <section class="panel">
            <h2>Daftarkan mitra</h2>
            <form method="post" action="/app/organizations">
                @csrf
                <x-molecules.field name="name" label="Nama mitra" required maxlength="120" />
                <x-molecules.field name="contact" label="Kontak" />
                <x-molecules.field name="address" label="Alamat" />
                <x-atoms.button>Simpan mitra</x-atoms.button>
            </form>
        </section>
    @endif
</x-templates.console>
