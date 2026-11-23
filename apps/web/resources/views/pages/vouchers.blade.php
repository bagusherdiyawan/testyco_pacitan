<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Promosi &amp; Diskon</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Voucher penjualan eceran</h1>
            <p class="text-sm text-slate-600 mt-1">Voucher berlaku online pada toko dan produk pilihan. Penerbit membiayai diskon sendiri.</p>
        </div>
        <a href="/app/organizations/{{ $organization }}" class="button button--lite">Ringkasan Organisasi</a>
    </div>

    @if($pending)
        <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6">
            <h2 class="text-base font-semibold text-amber-900">Hasil Belum Pasti</h2>
            @foreach($pending['body'] as $key => $value)
                @if(is_scalar($value) && $key !== 'operation_id')
                    <p class="text-sm text-amber-800">{{ $key }}: {{ $value }}</p>
                @endif
            @endforeach
            <form method="post" action="/app/organizations/{{ $organization }}/vouchers/retry" class="mt-3">
                @csrf
                <x-atoms.button>Ulangi request yang sama</x-atoms.button>
            </form>
        </section>
    @else
        <details class="panel mb-6">
            <summary class="font-semibold text-slate-900 cursor-pointer">+ Buat ketentuan voucher baru</summary>
            <form class="mt-4 pt-4 border-t border-slate-200 space-y-4" method="post" action="/app/organizations/{{ $organization }}/vouchers">
                @csrf
                <x-molecules.operation-key :remember="false" />
                <div class="form-grid">
                    <x-molecules.field name="code" label="Kode unik (huruf besar, angka, garis bawah atau minus)" maxlength="60" :remember="false" required />
                    <label>Pendanaan
                        <select name="funding_organization_id" required>
                            <option value="">Pilih setelah meninjau biaya diskon</option>
                            <option value="{{ $organization }}">Organisasi penerbit ini menanggung seluruh diskon</option>
                        </select>
                    </label>
                    <label>Jenis diskon
                        <select name="kind" required>
                            <option value="">Pilih</option>
                            <option value="fixed">Nominal tetap IDR</option>
                            <option value="percent">Persentase dalam basis point (100 = 1%)</option>
                        </select>
                    </label>
                    @foreach(['value' => 'Nilai nominal IDR / basis point', 'maximum_discount' => 'Batas diskon IDR per transaksi', 'minimum_subtotal' => 'Minimum subtotal produk yang memenuhi (isi 0 jika tidak ada)', 'usage_limit' => 'Batas jumlah penggunaan'] as $key => $label)
                        <x-molecules.field :name="$key" :label="$label" type="number" min="{{ $key === 'minimum_subtotal' ? 0 : 1 }}" :remember="false" required />
                    @endforeach
                    <x-molecules.field name="starts_on" label="Mulai berlaku WIB (inklusif)" type="date" :remember="false" required />
                    <x-molecules.field name="ends_on" label="Akhir berlaku WIB (inklusif)" type="date" :remember="false" required />
                </div>
                <fieldset class="p-3 rounded-lg border border-slate-200">
                    <legend class="text-xs font-semibold text-slate-600 px-1">Toko yang berhak (wajib pilih)</legend>
                    <div class="flex flex-wrap gap-3 text-sm">
                        @foreach($stores as $store)
                            @if($store['active'])
                                <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="store_ids[]" value="{{ $store['id'] }}">{{ $store['name'] }}</label>
                            @endif
                        @endforeach
                    </div>
                </fieldset>
                <fieldset class="p-3 rounded-lg border border-slate-200">
                    <legend class="text-xs font-semibold text-slate-600 px-1">Produk yang memenuhi (kosong berarti seluruh produk)</legend>
                    <div class="flex flex-wrap gap-3 text-sm">
                        @foreach($products as $product)
                            @if($product['active'])
                                <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="product_ids[]" value="{{ $product['id'] }}">{{ $product['sku'] }} — {{ $product['name'] }}</label>
                            @endif
                        @endforeach
                    </div>
                </fieldset>
                <div class="form-grid">
                    <label>Kuota setelah refund
                        <select name="restore_policy" required>
                            <option value="">Pilih kebijakan voucher ini</option>
                            <option value="never">Kuota tetap terpakai</option>
                            <option value="on_full_refund">Kembalikan kuota sekali setelah seluruh unit direfund</option>
                        </select>
                    </label>
                    <x-molecules.field name="terms" label="Ketentuan dan dasar pembiayaan" maxlength="2000" :remember="false" required />
                </div>
                <x-atoms.button>Konfirmasi ketentuan dan biaya voucher</x-atoms.button>
            </form>
        </details>

        <section class="panel">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Daftar Voucher</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Kode</th>
                            <th class="py-2 pr-4">Nilai &amp; Batas</th>
                            <th class="py-2 pr-4">Pemakaian</th>
                            <th class="py-2 pr-4">Periode WIB</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $v)
                            <tr class="border-b border-slate-100 align-top">
                                <td class="py-2.5 pr-4">
                                    <div class="font-mono font-bold text-slate-900">{{ $v['code'] }}</div>
                                    <div class="text-xs text-slate-500 mt-0.5">{{ $v['terms'] }}</div>
                                </td>
                                <td class="py-2.5 pr-4">
                                    <div>{{ $v['kind'] }} {{ $v['value'] }}</div>
                                    <div class="text-xs text-slate-500">Maksimal diskon Rp {{ number_format($v['maximum_discount'], 0, ',', '.') }} · {{ $v['restore_policy'] }}</div>
                                </td>
                                <td class="py-2.5 pr-4">{{ $v['used_count'] }} / {{ $v['usage_limit'] }}</td>
                                <td class="py-2.5 pr-4 font-mono text-xs">{{ substr($v['starts_on'], 0, 10) }} — {{ substr($v['ends_on'], 0, 10) }} WIB</td>
                                <td class="py-2.5 pr-4"><x-atoms.badge>{{ $v['active'] ? 'Aktif' : 'Nonaktif' }}</x-atoms.badge></td>
                                <td class="py-2.5">
                                    <details>
                                        <summary class="text-xs font-medium text-emerald-700 cursor-pointer hover:underline">{{ $v['active'] ? 'Nonaktifkan' : 'Aktifkan' }}</summary>
                                        <form method="post" action="/app/organizations/{{ $organization }}/vouchers/{{ $v['id'] }}/status" class="mt-2 space-y-2 min-w-[200px]">
                                            @csrf
                                            <x-molecules.operation-key :remember="false" />
                                            <input type="hidden" name="version" value="{{ $v['version'] }}">
                                            <input type="hidden" name="active" value="{{ $v['active'] ? 0 : 1 }}">
                                            <x-molecules.field name="reason" label="Alasan perubahan status" :remember="false" required />
                                            <x-atoms.button>{{ $v['active'] ? 'Nonaktifkan' : 'Aktifkan' }} voucher</x-atoms.button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-4 text-slate-500">Belum ada voucher terdaftar.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3"><x-molecules.pagination :items="$items" /></div>
        </section>
    @endif
</x-templates.console>
