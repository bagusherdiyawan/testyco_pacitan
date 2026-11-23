<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Rekonsiliasi Offline POS</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Antrean dan konflik offline tunai</h1>
            <p class="text-sm text-slate-600 mt-1">Catatan belum diterima server bukan penjualan terposting. Rekonsiliasi uang dan barang aktual sebelum keputusan.</p>
        </div>
        <a href="/app/stores/{{ $store }}/pos" class="button button--lite">← POS</a>
    </div>

    @if($pending)
        <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6">
            <h2 class="text-base font-semibold text-amber-900">Hasil keputusan belum pasti</h2>
            <p class="text-sm text-amber-800 mt-1">{{ $pending['review']['id'] }} · {{ $pending['action'] }} · {{ $pending['body']['reason'] }} · {{ $pending['body']['reconciliation_reference'] }}</p>
            <form method="post" action="/app/stores/{{ $store }}/pos/offline/retry" class="mt-3">
                @csrf
                <x-atoms.button>Ulangi request yang sama</x-atoms.button>
            </form>
        </section>
    @else
        <section class="panel">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">ID &amp; Status</th>
                            <th class="py-2 pr-4">Waktu &amp; Kasir</th>
                            <th class="py-2 pr-4">Nominal &amp; Item</th>
                            <th class="py-2">Keputusan Rekonsiliasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="border-b border-slate-100 align-top">
                                <td class="py-3 pr-4">
                                    <div class="font-mono text-xs font-semibold">{{ $row['id'] }}</div>
                                    <div class="mt-1"><x-atoms.badge>{{ $row['status'] }}</x-atoms.badge></div>
                                    @if(!empty($row['conflict_message']))
                                        <div class="text-xs text-rose-700 font-medium mt-1">{{ $row['conflict_message'] }}</div>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-xs text-slate-600">
                                    <div>Dicatat: {{ $row['captured_at'] }}</div>
                                    <div>Diterima: {{ $row['received_at'] }}</div>
                                    <div>Kasir #{{ $row['cashier_id'] }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="font-medium">Total perangkat Rp{{ $row['payload']['client_total'] }} · Tunai Rp{{ $row['payload']['cash_received'] }}</div>
                                    <div class="text-xs text-slate-500">Total quote izin Rp{{ $row['snapshot']['total_amount'] ?? 'Belum valid' }}</div>
                                    <div class="text-xs text-slate-700 mt-1 space-y-0.5">
                                        @foreach($row['snapshot']['items'] ?? [] as $line)
                                            <div>{{ $line['sku'] }} · {{ $line['quantity'] }} {{ $line['unit'] }} × Rp{{ $line['unit_price'] }}</div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="py-3">
                                    @if($c['can_review'] && $row['status'] === 'conflict')
                                        <div class="space-y-3 min-w-[260px]">
                                            @foreach(['accept' => 'Terima quote historis setelah rekonsiliasi', 'void' => 'Batalkan pencatatan setelah uang dikembalikan/diverifikasi'] as $action => $label)
                                                <details class="p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                                                    <summary class="text-xs font-semibold cursor-pointer">{{ $label }}</summary>
                                                    <form method="post" action="/app/stores/{{ $store }}/pos/offline/{{ $row['id'] }}/{{ $action }}" class="mt-3 space-y-2">
                                                        @csrf
                                                        <x-molecules.operation-key :remember="false" />
                                                        <input type="hidden" name="version" value="{{ $row['version'] }}">
                                                        @if($action === 'accept')
                                                            <label class="field">Shift terbuka milik kasir asal untuk uang belum dibukukan
                                                                <select name="shift" required>
                                                                    <option value="">Pilih setelah menghitung fisik</option>
                                                                    @foreach($c['open_shifts'] as $s)
                                                                        @if($s['cashier_id'] === $row['cashier_id'])
                                                                            <option value="{{ $s['id'] }}:{{ $s['version'] }}">{{ $s['device_name'] }} · {{ $s['cashier_name'] }}</option>
                                                                        @endif
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                            <p class="text-xs text-slate-500">Harga/jumlah tetap quote izin. Server tetap menolak stok tidak cukup, recall, atau masa simpan habis.</p>
                                                        @endif
                                                        <x-molecules.field name="reason" label="Alasan keputusan" :remember="false" required />
                                                        <x-molecules.field name="reconciliation_reference" label="Bukti hitungan uang/barang/refund aktual" :remember="false" required />
                                                        <label class="flex items-start gap-2 text-xs text-slate-700">
                                                            <input type="checkbox" name="verified" value="1" required class="mt-0.5">
                                                            <span>Saya memeriksa kejadian fisik dan uang {{ $action === 'accept' ? 'yang belum dibukukan dan berada dalam shift pilihan' : 'yang dikembalikan/direkonsiliasi' }}</span>
                                                        </label>
                                                        <x-atoms.button>{{ $label }}</x-atoms.button>
                                                    </form>
                                                </details>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">Belum ada antrean atau konflik offline.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3"><x-molecules.pagination :items="$rows" /></div>
        </section>
    @endif
</x-templates.console>
