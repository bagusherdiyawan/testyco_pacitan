<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Kasir Paket Resep</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Paket dengan resep komponen</h1>
        </div>
        <a href="/app/stores/{{ $store }}/pos" class="button button--lite">← POS</a>
    </div>

    @if($pending)
        <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6 space-y-3">
            <h2 class="text-base font-semibold text-amber-900">Hasil pembuatan draft belum pasti. Jangan membuat draft pengganti.</h2>
            <p class="text-sm text-amber-800">{{ $pending['review']['product']['name'] }} · {{ $pending['body']['lines'][0]['quantity'] }} paket</p>
            <form method="post" action="/app/stores/{{ $store }}/pos/bundles/{{ $product }}/retry">
                @csrf
                <x-atoms.button>Ulangi pembuatan draft yang sama</x-atoms.button>
            </form>
        </section>
    @else
        <section class="panel">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-900">{{ $c['product']['name'] }} · Resep versi {{ $c['recipe']['version'] }}</h2>
                <p class="text-xs text-slate-600 mt-1">Pilih stok terbuka/siap jual setiap komponen. Jumlah biji dikalikan jumlah paket; stok dipotong saat pembayaran, bukan saat draft dibuat.</p>
            </div>

            @if($pos['shift'])
                <form class="space-y-4" method="post" action="/app/stores/{{ $store }}/pos/bundles/{{ $product }}">
                    @csrf
                    <x-molecules.operation-key :remember="false" />
                    <input type="hidden" name="shift_id" value="{{ $pos['shift']['id'] }}">
                    <div class="max-w-xs">
                        <x-molecules.field name="quantity" label="Jumlah paket" type="number" min="1" max="10000" :remember="false" required />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($c['recipe']['components'] as $i => $component)
                            <fieldset class="p-3 rounded-lg border border-slate-200">
                                <legend class="text-xs font-semibold text-slate-600 px-1">{{ $component['sku'] }} · {{ $component['pieces'] }} biji per paket</legend>
                                <input type="hidden" name="components[{{ $i }}][product_id]" value="{{ $component['product_id'] }}">
                                <label class="field">Batch / bucket
                                    <select name="components[{{ $i }}][stock]" required>
                                        <option value="">Pilih stok komponen</option>
                                        @foreach($c['batches'] as $batch)
                                            @if($batch['product_id'] === $component['product_id'])
                                                @foreach(['opened' => 'Terbuka', 'ready' => 'Siap jual'] as $bucket => $label)
                                                    @if($batch[$bucket.'_pieces'] > 0)
                                                        <option value="{{ $batch['id'] }}:{{ $bucket }}">{{ $batch['batch_code'] }} · {{ $label }} · {{ $batch[$bucket.'_pieces'] }} biji</option>
                                                    @endif
                                                @endforeach
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                            </fieldset>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-end gap-4 pt-2">
                        <x-molecules.field name="voucher_code" label="Kode voucher (opsional)" maxlength="60" :remember="false" />
                        <x-atoms.button>Tinjau total paket dari server</x-atoms.button>
                    </div>
                </form>
            @else
                <p class="text-sm text-amber-800">Buka shift POS terlebih dahulu.</p>
            @endif
        </section>
    @endif
</x-templates.console>
