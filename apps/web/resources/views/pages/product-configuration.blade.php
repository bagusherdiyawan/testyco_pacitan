<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Konfigurasi Produk</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">{{ $c['product']['sku'] }} · {{ $c['product']['name'] }}</h1>
            <p class="text-sm text-slate-600 mt-1">Gambar privat, penjadwalan harga efektif (WIB), dan resep paket POS.</p>
        </div>
        <a href="/app/organizations/{{ $organization }}/catalog" class="button button--lite">← Kembali ke Katalog</a>
    </div>

    @if($pending)
        <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6">
            <h2 class="text-base font-semibold text-amber-900">Hasil Belum Pasti — Tindakan Semula Ditahan</h2>
            <p class="text-sm text-amber-800 mt-1">{{ $pending['action'] }} · {{ $pending['body']['reason'] ?? '' }}</p>
            @if(isset($pending['body']['price']))
                <p class="text-sm text-amber-800 mt-1">Harga Rp {{ $pending['body']['price'] }} · {{ $pending['body']['starts_on'] }} sampai {{ $pending['body']['ends_on'] }}</p>
            @endif
            <form method="post" action="/app/organizations/{{ $organization }}/products/{{ $product }}/configuration/retry" class="mt-3">
                @csrf
                <x-atoms.button>Ulangi request yang sama</x-atoms.button>
            </form>
        </section>
    @else
        <section class="panel mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Gambar Produk Privat</h2>
            <div class="flex flex-wrap items-start gap-6">
                @if($c['image'])
                    <img src="/app/organizations/{{ $organization }}/products/{{ $product }}/image" alt="{{ $c['product']['name'] }}" class="rounded-lg border border-slate-200 max-h-48 object-contain">
                @endif
                <form method="post" enctype="multipart/form-data" action="/app/organizations/{{ $organization }}/products/{{ $product }}/configuration/image" class="space-y-3">
                    @csrf
                    <x-molecules.operation-key :remember="false" />
                    <input type="hidden" name="version" value="{{ $c['image']['version'] ?? 0 }}">
                    <label class="block text-sm text-slate-700">
                        <span class="block font-medium mb-1">PNG/JPEG/WebP, maksimal 5 MiB dan 4096 px</span>
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp" required class="block text-sm">
                    </label>
                    <x-atoms.button>Simpan gambar privat</x-atoms.button>
                </form>
            </div>
        </section>

        <section class="panel mb-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Harga dengan masa berlaku (WIB)</h2>
                    <p class="text-xs text-slate-600 mt-0.5">Tanggal kalender WIB inklusif. Harga khusus mitra mengungguli harga umum grosir; di luar periode memakai harga dasar.</p>
                </div>
            </div>

            <details class="mb-4 p-3 rounded-lg bg-slate-50 border border-slate-200">
                <summary class="font-semibold text-sm text-slate-900 cursor-pointer">+ Jadwalkan periode harga baru</summary>
                <form method="post" action="/app/organizations/{{ $organization }}/products/{{ $product }}/configuration/prices" class="mt-4 space-y-3">
                    @csrf
                    <x-molecules.operation-key :remember="false" />
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="field">Jenis harga
                            <select name="channel">
                                <option value="retail">Eceran per biji / satu paket resep</option>
                                <option value="wholesale">Grosir per kemasan</option>
                            </select>
                        </label>
                        <label class="field">Mitra khusus (grosir pusat saja)
                            <select name="partner_id">
                                <option value="">Umum organisasi</option>
                                @foreach($c['partners'] as $p)
                                    <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <x-molecules.field name="starts_on" label="Tanggal mulai WIB" type="date" :remember="false" required />
                        <x-molecules.field name="ends_on" label="Tanggal akhir WIB" type="date" :remember="false" required />
                        <x-molecules.field name="price" label="Harga integer rupiah" type="number" min="0" max="1000000000" :remember="false" required />
                        <x-molecules.field name="reason" label="Alasan penetapan" maxlength="2000" :remember="false" required />
                    </div>
                    <x-atoms.button>Jadwalkan harga</x-atoms.button>
                </form>
            </details>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Kanal</th>
                            <th class="py-2 pr-4">Mitra</th>
                            <th class="py-2 pr-4">Periode WIB</th>
                            <th class="py-2 pr-4">Harga</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($c['prices'] as $p)
                            <tr class="border-b border-slate-100 align-top">
                                <td class="py-2.5 pr-4 font-medium">{{ $p['channel'] }}</td>
                                <td class="py-2.5 pr-4">{{ $p['partner_id'] ?? 'Umum' }}</td>
                                <td class="py-2.5 pr-4 font-mono text-xs">{{ $p['starts_on'] }} – {{ $p['ends_on'] }}</td>
                                <td class="py-2.5 pr-4 font-medium">Rp {{ number_format((int) $p['price'], 0, ',', '.') }}</td>
                                <td class="py-2.5 pr-4"><x-atoms.badge>{{ $p['active'] ? 'Aktif' : 'Dibatalkan' }}</x-atoms.badge></td>
                                <td class="py-2.5">
                                    @if($p['active'])
                                        <details>
                                            <summary class="text-xs text-rose-700 cursor-pointer hover:underline">Batalkan</summary>
                                            <form method="post" action="/app/organizations/{{ $organization }}/products/{{ $product }}/prices/{{ $p['id'] }}/cancel" class="mt-2 space-y-2 min-w-[220px]">
                                                @csrf
                                                <x-molecules.operation-key :remember="false" />
                                                <input type="hidden" name="version" value="{{ $p['version'] }}">
                                                <x-molecules.field name="reason" label="Alasan pembatalan harga efektif" maxlength="2000" :remember="false" required />
                                                <x-atoms.button>Batalkan periode harga</x-atoms.button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-slate-500">Belum ada jadwal harga efektif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2 class="text-lg font-semibold text-slate-900 mb-1">Resep Paket untuk POS</h2>
            <p class="text-xs text-slate-600 mb-3">SKU paket wajib berisi 1 / porsi 1. Komponen memakai biji stok terbuka/siap jual. Saat paket dibayar, semua stok komponen dipotong atomik sesuai resep.</p>

            @if($c['recipe'])
                <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 mb-4 text-sm">
                    <p class="font-medium text-slate-900">Versi {{ $c['recipe']['version'] }} · {{ $c['recipe']['active'] ? 'Aktif' : 'Nonaktif' }} · Padanan porsi kemasan: {{ $c['recipe']['portion_equivalent'] ?? 0 }}</p>
                    <ul class="mt-2 list-disc list-inside text-slate-700">
                        @foreach($c['recipe']['components'] as $x)
                            <li>{{ $x['sku'] }} · {{ $x['pieces'] }} biji per paket</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="post" action="/app/organizations/{{ $organization }}/products/{{ $product }}/configuration/recipe" class="space-y-4">
                @csrf
                <x-molecules.operation-key :remember="false" />
                <input type="hidden" name="version" value="{{ $c['recipe']['version'] ?? 0 }}">
                <div class="flex flex-wrap items-center gap-6">
                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-800">
                        <input type="checkbox" name="active" value="1" @checked($c['recipe']['active'] ?? true)>
                        <span>Aktifkan paket</span>
                    </label>
                    <x-molecules.field name="portion_equivalent" label="Padanan porsi kemasan (0 = tidak dihitung per porsi)" type="number" min="0" max="100" :value="$c['recipe']['portion_equivalent'] ?? 0" :remember="false" />
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-500">
                                <th class="py-2 pr-4">Slot</th>
                                <th class="py-2 pr-4">Produk Komponen</th>
                                <th class="py-2">Biji per Paket (kosong dilewati)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @for($i = 0; $i < 5; $i++)
                                @php($old = $c['recipe']['components'][$i] ?? null)
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 pr-4 font-medium text-slate-600">Komponen {{ $i + 1 }}</td>
                                    <td class="py-2 pr-4">
                                        <select name="components[{{ $i }}][product_id]" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                                            <option value="">Pilih komponen</option>
                                            @foreach($c['components'] as $p)
                                                <option value="{{ $p['id'] }}" @selected(($old['product_id'] ?? null) === $p['id'])>{{ $p['sku'] }} · {{ $p['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="py-2">
                                        <input type="number" name="components[{{ $i }}][pieces]" min="1" max="10000" value="{{ $old['pieces'] ?? '' }}" class="w-40 rounded border border-slate-300 px-2 py-1.5 text-sm">
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                <x-atoms.button>Simpan versi resep paket</x-atoms.button>
            </form>
        </section>
    @endif
</x-templates.console>
