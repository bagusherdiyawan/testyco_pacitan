<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Laporan Penjualan Tunai</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Laporan penjualan tunai · {{ $summary['organization']['name'] }}</h1>
            <p class="text-xs text-slate-500 mt-1">Data dimuat: {{ \Carbon\CarbonImmutable::parse($summary['observed_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB. Maksimal 31 hari kalender WIB (transaksi POS tunai selesai).</p>
        </div>
        <a href="/app/organizations/{{ $organization }}/reports/cash-sales/export?{{ http_build_query(array_diff_key($filters, ['page' => true])) }}" class="button button--lite">
            Unduh CSV transaksi (maksimal 10.000)
        </a>
    </div>

    <section class="panel mb-6">
        <form method="get" class="flex flex-wrap items-end gap-4">
            <x-molecules.field name="from_day" type="date" label="Tanggal awal WIB" :value="$filters['from_day']" required />
            <x-molecules.field name="to_day" type="date" label="Tanggal akhir WIB (termasuk)" :value="$filters['to_day']" required />
            <label class="field">Toko
                <select name="store_id">
                    <option value="">Semua toko berizin</option>
                    @foreach($summary['available_stores'] as $store)
                        <option value="{{ $store['id'] }}" @selected($filters['store_id'] === $store['id'])>{{ $store['name'] }}{{ $store['active'] ? '' : ' (nonaktif)' }}</option>
                    @endforeach
                </select>
            </label>
            <x-atoms.button>Tampilkan laporan</x-atoms.button>
        </form>
        <p class="text-xs text-slate-500 mt-3">Bukan kebijakan pergantian hari bisnis atau penutupan shift. Kas awal/masuk/keluar, grosir, transfer internal, piutang, dan refund/non-tunai tidak termasuk.</p>
    </section>

    <div class="mb-6">
        <x-organisms.cash-sales-metrics :totals="$summary['totals']" />
    </div>

    <section class="panel mb-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Perbandingan Toko</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Toko</th>
                        <th class="py-2 pr-4">Transaksi</th>
                        <th class="py-2 pr-4">Penjualan Kotor</th>
                        <th class="py-2 pr-4">Diskon</th>
                        <th class="py-2">Penjualan Bersih</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($summary['by_store'] as $row)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $row['store_name'] }}{{ $row['active'] ? '' : ' (nonaktif)' }}</td>
                            <td class="py-2.5 pr-4">Transaksi {{ $row['transaction_count'] }}</td>
                            <td class="py-2.5 pr-4">Kotor Rp {{ number_format($row['gross_sales'], 0, ',', '.') }}</td>
                            <td class="py-2.5 pr-4">Diskon Rp {{ number_format($row['discount_amount'], 0, ',', '.') }}</td>
                            <td class="py-2.5 font-semibold text-emerald-700">Bersih Rp {{ number_format($row['net_sales'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-500">Belum ada toko dalam cakupan laporan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2 class="text-lg font-semibold text-slate-900 mb-1">Produk Terjual</h2>
        <p class="text-xs text-slate-500 mb-3">Nama/SKU mengikuti snapshot transaksi. Nilai produk adalah kotor, belum alokasi diskon/refund.</p>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">SKU &amp; Nama Produk</th>
                        <th class="py-2 pr-4">Kondisi</th>
                        <th class="py-2 pr-4">Terjual</th>
                        <th class="py-2 pr-4">Frekuensi</th>
                        <th class="py-2">Penjualan Kotor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $row)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $row['sku'] }} · {{ $row['name'] }}</td>
                            <td class="py-2.5 pr-4">{{ $row['condition'] }}</td>
                            <td class="py-2.5 pr-4">{{ $row['sold_pieces'] }} biji</td>
                            <td class="py-2.5 pr-4">{{ $row['transaction_count'] }} transaksi</td>
                            <td class="py-2.5 font-semibold">Kotor Rp {{ number_format($row['gross_sales'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-500">Belum ada produk terjual dalam periode dan toko ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3"><x-molecules.pagination :items="$products" /></div>
    </section>
</x-templates.console>
