<x-templates.console>
    @if($dashboard)
        <x-organisms.sales-dashboard :dashboard="$dashboard" />
        <div class="section-heading"><h2>Workspace organisasi</h2></div>
    @else
        <div class="mb-6">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Beranda Workspace</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Workspace organisasi</h1>
        </div>
    @endif

    <section class="panel mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="text-lg font-semibold text-slate-900">Toko &amp; Booth Saya</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Nama Toko / Booth</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Aksi Cepat</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stores as $store)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $store['name'] }}</td>
                            <td class="py-2.5 pr-4">
                                <x-atoms.badge>{{ $store['active'] ? 'Toko Aktif' : 'Nonaktif' }}</x-atoms.badge>
                            </td>
                            <td class="py-2.5">
                                <div class="pill-actions">
                                    <a class="button" href="/app/stores/{{ $store['id'] }}/operations">Operasional &amp; Stok</a>
                                    @if($store['active'])
                                        <a class="button button--lite" href="/app/stores/{{ $store['id'] }}/pos">POS Kasir</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-slate-500">Belum ada toko yang ditugaskan ke akun ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3"><x-molecules.pagination :items="$stores" /></div>
    </section>

    <section class="panel mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="text-lg font-semibold text-slate-900">Daftar Organisasi</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Nama Organisasi</th>
                        <th class="py-2 pr-4">Tipe</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($organizations as $org)
                        <tr class="border-b border-slate-100 align-top">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $org['name'] }}</td>
                            <td class="py-2.5 pr-4">{{ $org['type'] === 'center' ? 'Pusat' : 'Mitra' }}</td>
                            <td class="py-2.5 pr-4"><x-atoms.badge>{{ $org['active'] ? 'Aktif' : 'Nonaktif' }}</x-atoms.badge></td>
                            <td class="py-2.5">
                                <div class="pill-actions">
                                    @if($org['can_manage'])
                                        <a class="button" href="/app/organizations/{{ $org['id'] }}">Kelola toko dan pengguna</a>
                                    @endif
                                    @if($org['can_catalog'])
                                        <a class="button button--lite" href="/app/organizations/{{ $org['id'] }}/catalog">Katalog dan harga</a>
                                    @endif
                                    @if(app()->getProvider(\App\Modules\Reporting\ReportingServiceProvider::class) && ($org['active'] ?? false) && in_array($org['role'] ?? null, ['owner', 'admin', 'partner_lead', 'store_manager'], true))
                                        <a class="button button--lite" href="/app/organizations/{{ $org['id'] }}/reports/cash-sales">Laporan penjualan tunai</a>
                                    @endif
                                    @if(($org['can_activate'] ?? false) && $org['type'] === 'partner')
                                        <form method="post" action="/app/organizations/{{ $org['id'] }}/status" class="inline">
                                            @csrf
                                            <input type="hidden" name="active" value="{{ $org['active'] ? '0' : '1' }}">
                                            <x-atoms.button>{{ $org['active'] ? 'Nonaktifkan mitra' : 'Aktifkan mitra' }}</x-atoms.button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-slate-500">Belum ada organisasi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3"><x-molecules.pagination :items="$organizations" /></div>
    </section>

    @if($organizations->getCollection()->contains(fn($org) => $org['type'] === 'center' && $org['can_manage']))
        <details class="panel">
            <summary class="font-semibold text-slate-900 cursor-pointer">+ Daftarkan mitra baru</summary>
            <form method="post" action="/app/organizations" class="mt-4 pt-4 border-t border-slate-200 space-y-3">
                @csrf
                <div class="form-grid">
                    <x-molecules.field name="name" label="Nama mitra" required maxlength="120" />
                    <x-molecules.field name="contact" label="Kontak" />
                    <x-molecules.field name="address" label="Alamat" />
                </div>
                <x-atoms.button>Simpan mitra</x-atoms.button>
            </form>
        </details>
    @endif
</x-templates.console>
