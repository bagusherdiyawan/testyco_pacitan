<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Master Produk &amp; Harga</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Katalog dan Harga</h1>
            <p class="text-sm text-slate-600 mt-1">Harga grosir per kemasan dan harga eceran per biji dipisahkan. Mata uang IDR.</p>
        </div>
        <a href="/app/organizations/{{ $organization }}" class="button button--lite">Ringkasan Organisasi</a>
    </div>

    <details class="panel mb-6">
        <summary class="font-semibold text-slate-900 cursor-pointer">+ Tambah produk baru</summary>
        <div class="mt-4 pt-4 border-t border-slate-200">
            <x-organisms.product-form :organization="$organization" />
        </div>
    </details>

    <section class="panel">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">SKU</th>
                        <th class="py-2 pr-4">Nama Produk</th>
                        <th class="py-2 pr-4">Kondisi</th>
                        <th class="py-2 pr-4">Isi Kemasan / Porsi</th>
                        <th class="py-2 pr-4">Grosir / Kemasan</th>
                        <th class="py-2 pr-4">Eceran / Biji</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr class="border-b border-slate-100 align-top">
                            <td class="py-2.5 pr-4 font-mono text-xs font-semibold text-slate-800">{{ $product['sku'] }}</td>
                            <td class="py-2.5 pr-4">
                                <div class="font-semibold text-slate-900">{{ $product['name'] }}</div>
                                @if(!empty($product['variant']))
                                    <div class="text-xs text-slate-500">{{ $product['variant'] }}</div>
                                @endif
                            </td>
                            <td class="py-2.5 pr-4">{{ ($product['condition'] ?? 'frozen') === 'ready' ? 'Siap jual' : 'Beku' }}</td>
                            <td class="py-2.5 pr-4">{{ $product['pack_size'] }} biji / {{ $product['portion_size'] ?? 1 }} porsi</td>
                            <td class="py-2.5 pr-4 font-medium">Rp {{ number_format((int) $product['wholesale_price'], 0, ',', '.') }}</td>
                            <td class="py-2.5 pr-4 font-medium">Rp {{ number_format((int) $product['retail_price'], 0, ',', '.') }}</td>
                            <td class="py-2.5 pr-4">
                                <x-atoms.badge>{{ $product['active'] ? 'Aktif' : 'Nonaktif' }}</x-atoms.badge>
                            </td>
                            <td class="py-2.5">
                                <div class="flex flex-wrap items-center gap-2 mb-1.5">
                                    <a href="/app/organizations/{{ $organization }}/products/{{ $product['id'] }}/configuration" class="button button--lite">Gambar, harga &amp; resep</a>
                                </div>
                                <details>
                                    <summary class="text-xs font-medium text-emerald-700 cursor-pointer hover:underline">Edit produk</summary>
                                    <div class="mt-3 p-3 rounded-lg bg-slate-50 border border-slate-200 min-w-[280px]">
                                        <x-organisms.product-form :organization="$organization" :product="$product" />
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-slate-500">Belum ada produk. Klik <strong>+ Tambah produk baru</strong> di atas untuk menambahkan produk pertama.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            <x-molecules.pagination :items="$products" />
        </div>
    </section>
</x-templates.console>
