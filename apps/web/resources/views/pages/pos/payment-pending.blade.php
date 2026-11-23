<x-templates.console>
    <div class="max-w-lg mx-auto py-8">
        <section class="panel border-l-4 border-amber-500 space-y-4">
            <div>
                <p class="text-xs font-medium text-amber-700 uppercase tracking-wider">Pemulihan Pembayaran</p>
                <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Pembayaran belum pasti</h1>
                <p class="text-sm text-slate-600 mt-1">Draft <span class="font-mono text-xs">{{ $intent }}</span>. Ulangi request yang sama dan periksa struk sebelum mengulangi pembayaran fisik.</p>
            </div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm space-y-1">
                @foreach($pending['body'] as $key => $value)
                    @if($key !== 'operation_id')
                        <div><span class="text-slate-500">{{ $key }}:</span> <strong>{{ $value }}</strong></div>
                    @endif
                @endforeach
            </div>
            <form method="post" action="/app/pos/intents/{{ $intent }}/pay">
                @csrf
                <x-atoms.button>Ulangi request yang sama</x-atoms.button>
            </form>
        </section>
    </div>
</x-templates.console>
