<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Checkout POS</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Tinjau pembayaran</h1>
            <p class="text-sm text-slate-600 mt-1">Draft <span class="font-mono text-xs">{{ $intent['id'] }}</span> · {{ $intent['status'] }}. Harga dan stok diverifikasi ulang saat konfirmasi.</p>
        </div>
        <a href="/app/stores/{{ $intent['store_id'] }}/pos" class="button button--lite">← Kembali ke POS</a>
    </div>

    <section class="panel mb-6">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Rincian Item Draft</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">SKU &amp; Produk</th>
                        <th class="py-2 pr-4">Jumlah</th>
                        <th class="py-2 pr-4">Harga Satuan</th>
                        <th class="py-2">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($intent['items'] as $item)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-semibold">{{ $item['sku'] }} · {{ $item['name'] }}</td>
                            <td class="py-2.5 pr-4">{{ $item['quantity'] }} {{ $item['unit'] }}</td>
                            <td class="py-2.5 pr-4">Rp {{ $item['unit_price'] }}</td>
                            <td class="py-2.5 font-medium">Rp {{ $item['line_total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4 pt-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm text-slate-600 space-y-1">
                @if(!empty($intent['service_type']))
                    <p>Jenis penjualan: <strong>{{ ['dine_in' => 'Makan di tempat', 'takeaway' => 'Bawa pulang'][$intent['service_type']] ?? $intent['service_type'] }}</strong></p>
                @endif
                <p>Diskon Rp {{ $intent['discount_amount'] ?? 0 }} · Voucher {{ $intent['voucher_snapshot']['code'] ?? 'Tidak ada' }}</p>
            </div>
            <h2 class="text-xl font-bold text-slate-900">Total Rp {{ $intent['total_amount'] }}</h2>
        </div>
    </section>

    @if($intent['status'] === 'pending' && $is_owner)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <form class="panel space-y-3" method="post" action="/app/pos/intents/{{ $intent['id'] }}/pay">
                @csrf
                <x-molecules.operation-key />
                <h2 class="text-base font-semibold text-slate-900">Konfirmasi Pembayaran</h2>
                <label class="field">Metode
                    <select name="payment_method" required>
                        <option value="cash">Tunai</option>
                        <option value="qris_manual">QRIS diverifikasi manual</option>
                        <option value="bank_transfer">Transfer diverifikasi manual</option>
                    </select>
                </label>
                <x-molecules.field name="cash_received" label="Tunai diterima (Rp)" type="number" min="{{ $intent['total_amount'] }}" max="1000000000000" />
                <x-molecules.field name="payment_reference" label="Referensi bukti unik (wajib non-tunai)" maxlength="160" :remember="false" />
                <x-molecules.field name="verified_amount" label="Nominal aktual terverifikasi (wajib non-tunai, harus persis total)" type="number" min="0" :remember="false" />
                <label class="flex items-start gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="verified" value="1" class="mt-0.5">
                    <span>Saya sudah memeriksa bukti dan pembayaran aktual non-tunai. Pemilihan metode saja tidak melunasi.</span>
                </label>
                <x-atoms.button>Konfirmasi pembayaran tunai / terverifikasi</x-atoms.button>
            </form>

            <form class="panel space-y-3" method="post" action="/app/pos/intents/{{ $intent['id'] }}/cancel">
                @csrf
                <x-molecules.operation-key />
                <h2 class="text-base font-semibold text-slate-900">Batalkan Draft</h2>
                <p class="text-xs text-slate-600">Pembatalan draft tidak mengubah saldo kas shift maupun stok batch.</p>
                <x-molecules.field name="reason" label="Alasan batal / tinjau ulang" required />
                <x-atoms.button class="button--lite">Batalkan draft</x-atoms.button>
            </form>
        </div>
    @endif

    <p class="text-sm"><a href="/app/stores/{{ $intent['store_id'] }}/pos" class="text-emerald-700 hover:underline">Kembali ke POS dan periksa struk terbaru</a></p>
    @if($intent['status'] === 'pending' && $is_owner)
        <script src="/pos-payment.js?v={{ @filemtime(public_path('pos-payment.js')) }}" defer></script>
    @endif
</x-templates.console>
