<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Pengadaan &amp; Distribusi</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">{{ $order ? 'Perbarui pengajuan' : 'Pengajuan stok' }}</h1>
            <p class="text-sm text-slate-600 mt-1">Pilih SKU pusat dan SKU toko yang sesuai. Isi kemasan dan kondisi harus sama. Harga grosir berasal dari pusat.</p>
        </div>
        <a href="/app/stores/{{ $store['id'] }}/operations" class="button button--lite">← Operasional toko</a>
    </div>

    <section class="panel">
        <form method="post" action="{{ $order ? '/app/orders/'.$order['id'].'/amend' : '/app/stores/'.$store['id'].'/orders' }}" class="space-y-4">
            @csrf
            <x-molecules.operation-key />
            @if($order)
                <input type="hidden" name="version" value="{{ $order['version'] }}">
            @endif
            <div class="form-grid">
                <label class="field">Metode
                    <select name="delivery_mode">
                        <option value="delivery" @selected(($order['delivery_mode'] ?? '') === 'delivery')>Diantar (alamat toko wajib terisi)</option>
                        <option value="pickup" @selected(($order['delivery_mode'] ?? '') === 'pickup')>Diambil</option>
                    </select>
                </label>
                <x-molecules.field name="note" label="Catatan" :value="$order['note'] ?? ''" />
            </div>

            <div id="order-lines" class="space-y-3">
                @foreach(old('lines', $order['lines'] ?? [['supplier_product_id' => '', 'local_product_id' => '', 'quantity_packs' => 1]]) as $i => $line)
                    <fieldset class="order-row p-3 rounded-lg border border-slate-200">
                        <legend class="text-xs font-semibold text-slate-500 px-1">Item {{ $i + 1 }}</legend>
                        <div class="form-grid">
                            <label class="field">SKU pusat
                                <select name="lines[{{ $i }}][supplier_product_id]" required>
                                    @foreach($supply as $p)
                                        <option value="{{ $p['id'] }}" @selected($p['id'] === $line['supplier_product_id'])>{{ $p['sku'] }} · {{ $p['name'] }} · {{ $p['pack_size'] }} biji · Rp {{ $p['wholesale_price'] }}/kemasan</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field">SKU tujuan di toko
                                <select name="lines[{{ $i }}][local_product_id]" required>
                                    @foreach($local as $p)
                                        <option value="{{ $p['id'] }}" @selected($p['id'] === $line['local_product_id'])>{{ $p['sku'] }} · {{ $p['name'] }} · {{ $p['pack_size'] }} biji · {{ $p['condition'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field">Jumlah kemasan
                                <input name="lines[{{ $i }}][quantity_packs]" type="number" min="1" max="10000" value="{{ $line['quantity_packs'] }}" required>
                            </label>
                        </div>
                        <button type="button" class="button button--lite mt-2" data-remove-row>Hapus item</button>
                    </fieldset>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3 pt-2">
                <button type="button" class="button button--lite" id="add-order-line">+ Tambah item</button>
                <x-atoms.button>{{ $order ? 'Perbarui dan tinjau ulang' : 'Kirim untuk persetujuan' }}</x-atoms.button>
            </div>
        </form>
    </section>

    <script src="/order-form.js" defer></script>
</x-templates.console>
