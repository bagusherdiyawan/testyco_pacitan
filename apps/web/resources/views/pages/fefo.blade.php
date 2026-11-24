<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Keamanan Pangan</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Saran batch FEFO</h1>
            <p class="text-sm text-slate-600 mt-1">Saran menurut SOP organisasi dan saldo saat dibaca. Saran tidak mereservasi stok; batch recall/terblokir tidak disarankan.</p>
        </div>
        <a href="/app/stores/{{ $store }}/pos" class="button button--lite">← POS</a>
    </div>

    <section class="panel mb-6">
        <form method="get" class="space-y-3">
            <div class="form-grid">
                <label class="field">Produk
                    <select name="product_id" required>
                        <option value="">Pilih</option>
                        @foreach($products as $p)
                            <option value="{{ $p['id'] }}" @selected(request('product_id') === $p['id'])>{{ $p['sku'] }} — {{ $p['name'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">Bucket
                    <select name="bucket" required>
                        @foreach(['usable' => 'Kemasan utuh', 'opened' => 'Kemasan terbuka', 'ready' => 'Siap jual'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('bucket') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">Kondisi
                    <select name="condition" required>
                        @foreach(['frozen', 'ready'] as $value)
                            <option value="{{ $value }}" @selected(request('condition') === $value)>{{ $value }}</option>
                        @endforeach
                    </select>
                </label>
                <x-molecules.field name="pack_size" type="number" min="1" label="Biji per kemasan (wajib untuk kemasan utuh sesuai stok)" />
            </div>
            <x-atoms.button>Lihat urutan batch</x-atoms.button>
        </form>
    </section>

    @if($result)
        <section class="panel">
            <div class="mb-3">
                <h2 class="text-lg font-semibold text-slate-900">Urutan Batch Disarankan</h2>
                <p class="text-xs text-slate-600">Aturan SOP: <strong>{{ $result['mode'] }}</strong>. Urutan paling awal berakhir di atas.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Kode Batch</th>
                            <th class="py-2 pr-4">Saldo Tersedia</th>
                            <th class="py-2 pr-4">Isi Kemasan</th>
                            <th class="py-2">Batas Kedaluwarsa / Pakai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($result['data'] as $b)
                            <tr class="border-b border-slate-100">
                                <td class="py-2.5 pr-4 font-mono font-semibold">{{ $b['batch_code'] }}</td>
                                <td class="py-2.5 pr-4">{{ $b['available_pieces'] }} biji</td>
                                <td class="py-2.5 pr-4">{{ $b['pack_size'] }} biji/kemasan</td>
                                <td class="py-2.5 font-mono text-xs">{{ $b['deadline'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">Tidak ada saran: aturan nonaktif atau tidak ada batch layak pada kelompok yang dipilih.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-templates.console>
