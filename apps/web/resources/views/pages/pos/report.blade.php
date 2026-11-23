<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Ringkasan Harian POS</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Ringkasan penjualan tunai</h1>
        </div>
        <a href="/app/stores/{{ $store }}/pos" class="button button--lite">← POS</a>
    </div>

    <section class="panel mb-6">
        <form method="get" class="flex flex-wrap items-end gap-4">
            <x-molecules.field name="day" label="Tanggal kalender WIB (bukan kebijakan pergantian shift)" type="date" :value="$day" required />
            <x-atoms.button>Tampilkan</x-atoms.button>
        </form>
    </section>

    <section class="panel mb-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
            <div>
                <span class="text-slate-500 block">Transaksi</span>
                <strong class="text-lg text-slate-900">Transaksi {{ $summary['transaction_count'] }}</strong>
            </div>
            <div>
                <span class="text-slate-500 block">Penjualan Kotor</span>
                <strong class="text-lg text-slate-900">Penjualan kotor Rp {{ $summary['gross_sales'] }}</strong>
            </div>
            <div>
                <span class="text-slate-500 block">Diskon Tercatat</span>
                <strong class="text-lg text-slate-900">Diskon tercatat Rp {{ $summary['discount_amount'] }}</strong>
            </div>
            <div>
                <span class="text-slate-500 block">Penjualan Bersih</span>
                <h2 class="text-lg font-bold text-emerald-700">Penjualan bersih Rp {{ $summary['net_sales'] }}</h2>
            </div>
            <div>
                <span class="text-slate-500 block">Penjualan Tunai</span>
                <strong class="text-lg text-slate-900">Penjualan tunai Rp {{ $summary['cash_sales'] }}</strong>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-4">
            Rentang [{{ \Carbon\Carbon::parse($summary['from'])->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB, {{ \Carbon\Carbon::parse($summary['to'])->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB)
        </p>
    </section>

    <p class="text-xs text-slate-500">Kas awal/masuk/keluar tidak dihitung sebagai omzet. Refund, diskon/voucher dan pembayaran non-tunai belum didukung.</p>
</x-templates.console>
