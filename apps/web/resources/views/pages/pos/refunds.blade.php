<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Penyesuaian POS</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Refund penjualan</h1>
        </div>
        <a href="/app/pos/sales/{{ $sale }}" class="button button--lite">← Struk</a>
    </div>

    @if($pending)
        <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6 space-y-3">
            <p class="text-sm font-semibold text-amber-900">Hasil belum pasti. Jangan lakukan refund fisik dua kali.</p>
            <x-organisms.refund-review :review="$pending['review']" />
            <form method="post" action="/app/pos/sales/{{ $sale }}/refunds/retry">
                @csrf
                <x-atoms.button>Ulangi request yang sama</x-atoms.button>
            </form>
        </section>
    @elseif($review)
        <section class="panel mb-6 space-y-4">
            <x-organisms.refund-review :review="$review['review']" />
            <div class="flex flex-wrap items-center gap-4 pt-2">
                <form method="post" action="/app/pos/sales/{{ $sale }}/refunds/confirm" class="space-y-3">
                    @csrf
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="verified" value="1" required class="mt-0.5">
                        <span>Saya telah memeriksa uang yang dikembalikan melalui metode asal dan jumlah fisik sesuai review ini</span>
                    </label>
                    <x-atoms.button>Konfirmasi refund dan posting sekali</x-atoms.button>
                </form>
                <form method="post" action="/app/pos/sales/{{ $sale }}/refunds/discard">
                    @csrf
                    <x-atoms.button class="button--lite">Kembali tanpa posting</x-atoms.button>
                </form>
            </div>
        </section>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 items-start">
            <section class="panel">
                <x-organisms.sale-receipt :sale="$c['sale']" />
            </section>

            <form class="panel space-y-3" method="post" action="/app/pos/sales/{{ $sale }}/refunds/preview">
                @csrf
                <h2 class="text-lg font-semibold text-slate-900">Ajukan Tinjauan Refund</h2>
                <input type="hidden" name="version" value="{{ $c['sale']['version'] }}">
                <label class="field">Shift terbuka untuk rekonsiliasi
                    <select name="shift" required>
                        <option value="">Pilih shift</option>
                        @foreach($c['open_shifts'] as $s)
                            <option value="{{ $s['id'] }}:{{ $s['version'] }}">{{ $s['device_name'] }} · {{ $s['cashier_name'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">Penanganan fisik
                    <select name="stock_disposition" required>
                        <option value="">Pilih sesuai keadaan nyata</option>
                        <option value="quarantine">Barang fisik dikembalikan ke karantina batch historis</option>
                        <option value="no_stock">Tanpa pengembalian stok, jelaskan alasan</option>
                    </select>
                </label>
                @foreach($c['sale']['lines'] as $i => $line)
                    <input type="hidden" name="lines[{{ $i }}][sale_line_id]" value="{{ $line['id'] }}">
                    <x-molecules.field :name="'lines['.$i.'][quantity]'" :label="$line['sku'].' · Unit refund (kosong/0 dilewati), satuan '.$line['unit']" type="number" min="0" :max="$line['quantity']" :remember="false" />
                @endforeach
                <x-molecules.field name="reference" label="Referensi pengembalian unik" maxlength="160" :remember="false" required />
                <x-molecules.field name="reason" label="Alasan dan hasil pemeriksaan fisik" maxlength="1800" :remember="false" required />
                <x-atoms.button>Tinjau nominal dan perubahan stok</x-atoms.button>
            </form>
        </div>

        <section class="panel">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Riwayat Refund</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Referensi</th>
                            <th class="py-2 pr-4">Nominal</th>
                            <th class="py-2 pr-4">Metode</th>
                            <th class="py-2">Alasan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($c['refunds'] as $refund)
                            <tr class="border-b border-slate-100">
                                <td class="py-2 pr-4 font-mono text-xs">{{ $refund['reference'] }}</td>
                                <td class="py-2 pr-4 font-medium">Rp{{ number_format($refund['amount'], 0, ',', '.') }}</td>
                                <td class="py-2 pr-4">{{ $refund['payment_method'] }}</td>
                                <td class="py-2">{{ $refund['reason'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-slate-500">Belum ada refund tercatat.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-templates.console>
