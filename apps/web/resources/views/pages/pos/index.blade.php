<x-templates.console>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <a href="/app/stores/{{ $context['store']['id'] }}/operations" class="text-slate-600 hover:text-slate-900">← Operasional toko</a>
            <span class="text-slate-300">|</span>
            <a href="/app/stores/{{ $context['store']['id'] }}/pos/offline" class="text-emerald-700 hover:underline">Antrean &amp; konflik offline tunai</a>
            <span class="text-slate-300">|</span>
            <a href="/app/stores/{{ $context['store']['id'] }}/food-safety/fefo" class="text-slate-700 hover:underline">Saran FEFO SOP</a>
        </div>
        @if($context['can_manage'] ?? false)
            <a href="/app/stores/{{ $context['store']['id'] }}/pos/report" class="text-sm font-medium text-emerald-700 hover:underline">Ringkasan penjualan tunai →</a>
        @endif
    </div>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">POS · {{ $context['store']['name'] }}</h1>
        <p class="text-sm text-slate-600 mt-1">Online · Tunai / non-tunai diverifikasi manual · IDR. Wajib online &amp; tutup shift sebelum berganti kasir.</p>
    </div>

    @if(!$context['store']['active'])
        <aside class="p-4 rounded-xl border border-amber-300 bg-amber-50 text-amber-900 mb-6">
            Toko nonaktif. Tidak dapat membuat transaksi baru atau membuka shift.
        </aside>
    @elseif(!$context['shift'])
        @php($blocking = $context['blocking_open_shift'] ?? null)
        @php($handover = $context['handover'] ?? null)

        @if($blocking)
            <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6">
                <h2 class="text-base font-semibold text-amber-900">Pergantian Shift Tertahan (1 Laci = 1 Shift Aktif)</h2>
                <p class="text-sm text-amber-800 mt-1">
                    Shift milik <strong>{{ $blocking['cashier_name'] }}</strong> ({{ $blocking['device_name'] }}) masih berstatus <strong>open</strong>.
                    Tutup shift tersebut secara online dan sinkronkan seluruh transaksi offline terlebih dahulu sebelum membuka shift baru.
                </p>
                @if($context['can_manage'] ?? false)
                    <p class="mt-3">
                        <a href="/app/pos/shifts/{{ $blocking['id'] }}" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-amber-700 text-white text-sm font-medium hover:bg-amber-800">
                            Periksa / Tutup Paksa Shift {{ $blocking['cashier_name'] }} →
                        </a>
                    </p>
                @endif
            </section>
        @endif

        @if($handover)
            <section class="panel bg-slate-50 border border-slate-200 mb-6">
                <h2 class="text-base font-semibold text-slate-900">Info Serah Terima Shift Sebelumnya</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3 text-sm">
                    <div>
                        <span class="text-slate-500 block">Kasir Sebelumnya</span>
                        <strong class="text-slate-900">{{ $handover['previous_cashier_name'] }} ({{ $handover['previous_device_name'] }})</strong>
                    </div>
                    <div>
                        <span class="text-slate-500 block">Sisa Modal Ditinggal di Laci</span>
                        <strong class="text-emerald-700 text-base">Rp {{ number_format((int) ($handover['expected_opening_cash'] ?? 0), 0, ',', '.') }}</strong>
                    </div>
                    <div>
                        <span class="text-slate-500 block">Setoran Shift Sebelumnya</span>
                        <strong class="text-slate-900">Rp {{ number_format((int) ($handover['previous_deposit_cash'] ?? 0), 0, ',', '.') }}</strong>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">
                    Hitung fisik uang di laci saat menerima serah terima. Jika kas awal berbeda dari sisa modal di atas, isi catatan selisih serah terima.
                </p>
            </section>
        @endif

        <section class="panel mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Buka shift kas</h2>
            <form method="post" action="/app/stores/{{ $context['store']['id'] }}/pos/shifts" class="space-y-4">
                @csrf
                <x-molecules.operation-key />
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-molecules.field name="device_name" label="Nama terminal/perangkat" required maxlength="80" />
                    <x-molecules.field name="opening_cash" label="Kas awal (Rp)" type="number" min="0" max="1000000000000" :value="$handover['expected_opening_cash'] ?? null" required />
                </div>
                <x-molecules.field name="opening_note" label="Catatan serah terima kas awal (wajib bila berbeda dari shift sebelumnya)" maxlength="2000" />
                <x-atoms.button>Buka shift</x-atoms.button>
            </form>
        </section>
    @else
        @php($activeShift = $context['shift'])
        <section class="panel mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Shift aktif · {{ $activeShift['device_name'] }}</h2>
                    @if(($context['can_manage'] ?? false) && $activeShift['expected_cash'] !== null)
                        <p class="text-sm text-slate-700 mt-1">Kas seharusnya: <strong>Rp {{ number_format((int) $activeShift['expected_cash'], 0, ',', '.') }}</strong></p>
                    @else
                        <p class="text-sm text-emerald-700 font-medium mt-1">Mode hitung buta (Blind Close) aktif · Saldo sistem tersembunyi sampai tutup shift.</p>
                    @endif
                </div>
                <a href="/app/pos/shifts/{{ $activeShift['id'] }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:bg-slate-800">
                    Rincian kas / ajukan mutasi / tutup shift
                </a>
            </div>
            @if(!empty($context['pending_cash_requests']))
                <p class="text-xs text-amber-700 mt-3">
                    Ada {{ count($context['pending_cash_requests']) }} pengajuan kas masuk/keluar menunggu keputusan pengelola. Pengajuan pending harus diputuskan atau dibatalkan sebelum tutup shift.
                </p>
            @endif
        </section>

        @if(count($context['pending_intents']))
            <section class="panel mb-6">
                <h2 class="text-base font-semibold text-slate-900 mb-2">Pulihkan draft pembayaran</h2>
                <p class="text-sm text-slate-600 mb-3">Periksa draft/struk sebelum memasukkan transaksi ulang setelah koneksi terputus.</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-500">
                                <th class="py-2 pr-4">ID Draft</th>
                                <th class="py-2 pr-4">Total</th>
                                <th class="py-2">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($context['pending_intents'] as $intent)
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $intent['id'] }}</td>
                                    <td class="py-2 pr-4">Rp {{ number_format((int) $intent['total_amount'], 0, ',', '.') }}</td>
                                    <td class="py-2"><a href="/app/pos/intents/{{ $intent['id'] }}" class="text-emerald-700 hover:underline">Buka draft</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @php($bundleProducts = collect($products)->filter(fn($p) => $p['is_bundle'] ?? false))
        @if($bundleProducts->isNotEmpty())
            <section class="panel mb-6">
                <h2 class="text-base font-semibold text-slate-900 mb-2">Paket resep</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach($bundleProducts as $product)
                        <a href="/app/stores/{{ $context['store']['id'] }}/pos/bundles/{{ $product['id'] }}" class="px-3 py-1.5 rounded-lg border border-slate-300 text-sm hover:bg-slate-50">
                            {{ $product['name'] }} — pilih stok komponen
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @php($singleProducts = collect($products)->reject(fn($p) => $p['is_bundle'] ?? false))
        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 mb-6 items-start">
            <section class="panel xl:col-span-5">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h2 class="text-lg font-semibold text-slate-900">Pilih Cepat Produk</h2>
                    <span class="text-xs text-slate-500">Klik kartu untuk tambah ke keranjang</span>
                </div>
                <input id="pos-quick-search" type="search" placeholder="Cari SKU atau nama produk..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-2">
                <div id="pos-quick-filters" class="flex flex-wrap gap-1.5 mb-3">
                    <button type="button" data-filter-cond="all" class="px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-700 text-white">Semua</button>
                    <button type="button" data-filter-cond="frozen" class="px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200">Beku</button>
                    <button type="button" data-filter-cond="ready" class="px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200">Siap jual</button>
                </div>
                <div id="pos-quick-grid" class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 max-h-[520px] overflow-y-auto pr-1">
                    @forelse($singleProducts as $product)
                        <button type="button"
                            data-quick-product="{{ $product['id'] }}"
                            data-search="{{ $product['sku'] }} {{ $product['name'] }}"
                            data-condition="{{ $product['condition'] ?? 'frozen' }}"
                            class="text-left p-2.5 rounded-xl border border-slate-200 bg-white hover:border-rose-500 hover:bg-rose-50/30 transition shadow-sm flex flex-col gap-2">
                            @if($product['has_image'] ?? false)
                                <img src="/app/stores/{{ $context['store']['id'] }}/catalog/{{ $product['id'] }}/image" alt="{{ $product['name'] }}" class="w-full h-24 object-cover rounded-lg bg-slate-100">
                            @else
                                <div class="w-full h-20 rounded-lg bg-rose-50 border border-rose-100 flex items-center justify-center font-mono text-xs font-bold text-rose-700">
                                    {{ $product['sku'] }}
                                </div>
                            @endif
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-xs font-semibold text-slate-500">{{ $product['sku'] }}</span>
                                <span class="text-xs font-bold text-rose-700">Rp {{ number_format((int) $product['retail_price'], 0, ',', '.') }}/biji</span>
                            </div>
                            <div class="font-semibold text-slate-900 text-sm leading-snug">{{ $product['name'] }}</div>
                            <div class="text-xs text-slate-500">{{ ($product['condition'] ?? 'frozen') === 'ready' ? 'Siap jual' : 'Beku' }} · {{ $product['pack_size'] }} biji/pack</div>
                        </button>
                    @empty
                        <p class="text-sm text-slate-500 col-span-2">Belum ada produk aktif di toko ini.</p>
                    @endforelse
                </div>
            </section>

            <section class="panel xl:col-span-7">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h2 class="text-lg font-semibold text-slate-900">Keranjang baru</h2>
                    <div class="text-sm text-slate-600">
                        Perkiraan awal: <strong id="pos-live-estimate" class="text-slate-900">Rp 0</strong>
                    </div>
                </div>
                <form method="post" action="/app/stores/{{ $context['store']['id'] }}/pos/intents" class="space-y-4">
                    @csrf
                    <x-molecules.operation-key />
                    <input type="hidden" name="shift_id" value="{{ $activeShift['id'] }}">
                    <div id="pos-lines" class="space-y-3">
                        <fieldset class="pos-row p-3 rounded-lg border border-slate-200">
                            <legend class="text-xs font-semibold text-slate-500 px-1">Item 1</legend>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <label class="field">Produk
                                    <select name="lines[0][product_id]" data-product required>
                                        @foreach($singleProducts as $product)
                                            <option value="{{ $product['id'] }}" data-pack="{{ $product['pack_size'] }}" data-portion="{{ $product['portion_size'] ?? 1 }}" data-price="{{ $product['retail_price'] }}">{{ $product['sku'] }} · {{ $product['name'] }} · Rp {{ $product['retail_price'] }}/biji</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="field">Satuan
                                    <select name="lines[0][unit]" data-unit>
                                        <option value="piece">Biji</option>
                                        <option value="portion">Porsi</option>
                                        <option value="pack">Kemasan</option>
                                    </select>
                                </label>
                                <label class="field">Jumlah unit
                                    <input name="lines[0][quantity]" type="number" min="1" max="10000" value="1" required>
                                </label>
                                <label class="field">Batch / keadaan stok
                                    <select name="lines[0][stock]" data-stock required>
                                        <option value="">Pilih sumber stok</option>
                                        @foreach($batches as $batch)
                                            @foreach(['usable' => 'Utuh', 'opened' => 'Terbuka', 'ready' => 'Siap jual'] as $bucket => $label)
                                                @if(($batch[$bucket.'_pieces'] ?? 0) > 0)
                                                    <option value="{{ $batch['id'] }}:{{ $bucket }}" data-product-id="{{ $batch['product_id'] }}" data-bucket="{{ $bucket }}" data-pack="{{ $batch['pack_size'] }}">{{ $batch['sku'] }} · {{ $batch['batch_code'] }} · {{ $label }} {{ $batch[$bucket.'_pieces'] }} biji · expiry kemasan {{ $batch['expires_on'] }}</option>
                                                @endif
                                            @endforeach
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <button type="button" class="button button--lite mt-2" data-remove-pos>Hapus item</button>
                        </fieldset>
                    </div>
                    <button type="button" class="button button--lite" id="add-pos-line">+ Tambah SKU</button>
                    @if($supplies)
                        <label class="field block max-w-md">Jenis penjualan (menentukan kemasan dan alat makan yang berkurang)
                            <select name="service_type" required>
                                <option value="">Pilih jenis penjualan</option>
                                <option value="dine_in">Makan di tempat</option>
                                <option value="takeaway">Bawa pulang</option>
                            </select>
                        </label>
                    @endif
                    <div class="flex flex-wrap items-end gap-4 pt-2 border-t border-slate-200">
                        <x-molecules.field name="voucher_code" label="Kode voucher (opsional)" maxlength="60" :remember="false" />
                        <x-atoms.button>Tinjau total dari server</x-atoms.button>
                    </div>
                </form>
            </section>
        </div>
    @endif

    @if($context['can_manage'] ?? false)
        <section class="panel mb-6 bg-slate-50">
            <h2 class="text-base font-semibold text-slate-900">Kebijakan Laci &amp; Pergantian Shift Toko</h2>
            <form method="post" action="/app/stores/{{ $context['store']['id'] }}/pos/policy" class="mt-3 flex flex-wrap items-center gap-4">
                @csrf
                <x-molecules.operation-key />
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="single_active_shift" value="1" @checked(($context['store']['single_active_shift'] ?? true) === true)>
                    <span>Kunci 1 shift aktif per toko (kasir baru wajib tunggu shift lama ditutup online)</span>
                </label>
                <x-atoms.button>Simpan kebijakan</x-atoms.button>
            </form>
        </section>
    @endif

    <section class="panel mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-slate-900">Struk terbaru milik Anda</h2>
            <a href="/app/stores/{{ $context['store']['id'] }}/pos/sales" class="text-sm text-emerald-700 hover:underline">Semua struk sesuai akses →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Nomor Struk</th>
                        <th class="py-2 pr-4">Total</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($context['recent_sales'] as $sale)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pr-4 font-medium">{{ $sale['number'] }}</td>
                            <td class="py-2 pr-4">Rp {{ number_format((int) $sale['total_amount'], 0, ',', '.') }}</td>
                            <td class="py-2"><a href="/app/pos/sales/{{ $sale['id'] }}" class="text-emerald-700 hover:underline">Detail struk</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-slate-500">Belum ada penjualan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Riwayat shift sesuai akses</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Kasir</th>
                        <th class="py-2 pr-4">Perangkat</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Kas Awal</th>
                        <th class="py-2 pr-4">Selisih Akhir</th>
                        <th class="py-2">Rincian</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($shifts as $shift)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pr-4 font-medium">{{ $shift['cashier_name'] }}</td>
                            <td class="py-2 pr-4">{{ $shift['device_name'] }}</td>
                            <td class="py-2 pr-4">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $shift['status'] === 'open' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                    {{ $shift['status'] }}
                                </span>
                            </td>
                            <td class="py-2 pr-4">Rp {{ number_format((int) $shift['opening_cash'], 0, ',', '.') }}</td>
                            <td class="py-2 pr-4">
                                @if($shift['status'] === 'closed')
                                    Rp {{ number_format((int) ($shift['difference_cash'] ?? 0), 0, ',', '.') }}
                                @else
                                    <span class="text-slate-400">Hitung buta</span>
                                @endif
                            </td>
                            <td class="py-2"><a href="/app/pos/shifts/{{ $shift['id'] }}" class="text-emerald-700 hover:underline">Buka</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-slate-500">Belum ada riwayat shift.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3"><x-molecules.pagination :items="$shifts" /></div>
    </section>

    <script src="/pos-cart.js?v={{ @filemtime(public_path('pos-cart.js')) }}" defer></script>
</x-templates.console>
