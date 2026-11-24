<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Master Kemasan &amp; Alat Makan</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Kemasan dan alat makan</h1>
            <p class="text-sm text-slate-600 mt-1">Atur barang non-makanan yang dipakai booth dan pengurangan otomatis per jenis penjualan.</p>
        </div>
        <a href="/app/organizations/{{ $organization }}" class="button button--lite">Ringkasan Organisasi</a>
    </div>

    <details class="panel mb-6">
        <summary class="font-semibold text-slate-900 cursor-pointer">+ Tambah barang kemasan / alat makan</summary>
        <form class="mt-4 pt-4 border-t border-slate-200 space-y-3" method="post" action="/app/organizations/{{ $organization }}/consumables/items">
            @csrf
            <x-molecules.operation-key :remember="false" />
            <div class="form-grid">
                <x-molecules.field name="name" label="Nama barang (misalnya Kotak, Sendok)" maxlength="120" :remember="false" required />
                <x-molecules.field name="unit" label="Satuan (misalnya pcs)" maxlength="30" :remember="false" required />
            </div>
            <x-atoms.button>Simpan barang</x-atoms.button>
        </form>
    </details>

    <section class="panel mb-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Daftar Barang</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Nama Barang</th>
                        <th class="py-2 pr-4">Satuan</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $item['name'] }}</td>
                            <td class="py-2.5 pr-4">{{ $item['unit'] }}</td>
                            <td class="py-2.5 pr-4">
                                <span class="status-chip {{ $item['active'] ? 'is-available' : '' }}">{{ $item['active'] ? 'Aktif' : 'Nonaktif' }}</span>
                            </td>
                            <td class="py-2.5">
                                <form method="post" action="/app/organizations/{{ $organization }}/consumables/items/{{ $item['id'] }}/status" class="inline">
                                    @csrf
                                    <x-molecules.operation-key :remember="false" />
                                    <input type="hidden" name="version" value="{{ $item['version'] }}">
                                    <input type="hidden" name="active" value="{{ $item['active'] ? 0 : 1 }}">
                                    <input type="hidden" name="reason" value="{{ $item['active'] ? 'Dinonaktifkan pengelola' : 'Diaktifkan pengelola' }}">
                                    <x-atoms.button class="button--lite">{{ $item['active'] ? 'Nonaktifkan' : 'Aktifkan' }}</x-atoms.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-slate-500">Belum ada barang. Klik <strong>+ Tambah barang</strong> di atas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2 class="text-lg font-semibold text-slate-900 mb-1">Aturan Pengurangan per Jenis Penjualan</h2>
        <p class="text-xs text-slate-600 mb-4">Pilih jenis penjualan, barang, dan cara menghitung. Isi jumlah 0 untuk menghapus aturan.</p>

        <div class="overflow-x-auto mb-6">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Jenis Penjualan</th>
                        <th class="py-2">Aturan Terpasang</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($serviceTypes as $type => $label)
                        @php($typeRules = collect($rules)->where('service_type', $type))
                        <tr class="border-b border-slate-100 align-top">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $label }}</td>
                            <td class="py-2.5">
                                @forelse($typeRules as $rule)
                                    <div>{{ collect($items)->firstWhere('id', $rule['consumable_item_id'])['name'] ?? 'Barang dihapus' }} · {{ $rule['quantity'] }} {{ $bases[$rule['basis']] ?? $rule['basis'] }}</div>
                                @empty
                                    <span class="text-slate-400">Belum ada aturan.</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <details class="p-3 rounded-lg bg-slate-50 border border-slate-200" open>
            <summary class="font-semibold text-sm text-slate-900 cursor-pointer">+ Atur / Ubah Aturan Pengurangan</summary>
            <form method="post" action="/app/organizations/{{ $organization }}/consumables/rules" class="mt-4 space-y-3">
                @csrf
                <x-molecules.operation-key :remember="false" />
                <div class="form-grid">
                    <label>Jenis penjualan
                        <select name="service_type" required>
                            <option value="">Pilih</option>
                            @foreach($serviceTypes as $type => $label)
                                <option value="{{ $type }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Barang
                        <select name="consumable_item_id" required>
                            <option value="">Pilih</option>
                            @foreach($items as $item)
                                @if($item['active'])
                                    <option value="{{ $item['id'] }}">{{ $item['name'] }} ({{ $item['unit'] }})</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label>Cara menghitung
                        <select name="basis" required>
                            @foreach($bases as $basis => $label)
                                <option value="{{ $basis }}">{{ ucfirst($label) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-molecules.field name="quantity" label="Jumlah (0 untuk menghapus)" type="number" min="0" max="1000" value="1" :remember="false" required />
                </div>
                <x-atoms.button>Simpan aturan</x-atoms.button>
            </form>
        </details>
    </section>
</x-templates.console>
