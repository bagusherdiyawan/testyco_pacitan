<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Pengawasan Stok Toko</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Stok minimum · {{ $report['store']['name'] }}</h1>
            <p class="text-sm text-slate-600 mt-1">Jumlah dalam biji (utuh + terbuka + siap, tidak termasuk karantina/susut). Data server: {{ $report['observed_at'] }}.</p>
        </div>
        <a href="/app/stores/{{ $report['store']['id'] }}/operations" class="button button--lite">← Operasional Toko</a>
    </div>

    <section class="panel mb-6">
        <form method="get" class="flex flex-wrap items-end gap-4">
            <x-molecules.field name="q" label="Cari SKU/nama" :value="$filters['q'] ?? ''" maxlength="100" />
            <label class="inline-flex items-center gap-2 text-sm text-slate-700 pb-2">
                <input type="checkbox" name="low_only" value="1" @checked($filters['low_only'] ?? false)>
                <span>Hanya stok rendah</span>
            </label>
            <x-atoms.button>Perbarui tampilan</x-atoms.button>
        </form>
        @if($report['can_manage'])
            <p class="text-xs text-slate-500 mt-3">Isi ambang untuk mengaktifkan. Kosongkan untuk menonaktifkan peringatan SKU tersebut; angka 0 tetap ambang aktif. Perubahan harus disertai alasan.</p>
        @endif
    </section>

    <section class="panel">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Status &amp; SKU</th>
                        @if($report['can_manage'])
                            <th class="py-2">Atur Ambang Minimum</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['data'] as $row)
                        <tr class="border-b border-slate-100 align-top">
                            <td class="py-3 pr-4">
                                <x-molecules.stock-minimum-status :row="$row" />
                            </td>
                            @if($report['can_manage'])
                                <td class="py-3">
                                    <form method="post" action="/app/stores/{{ $report['store']['id'] }}/stock-minimums/{{ $row['product_id'] }}" class="flex flex-wrap items-end gap-3">
                                        @csrf
                                        <x-molecules.operation-key :remember="false" />
                                        <input type="hidden" name="version" value="{{ $row['version'] }}">
                                        <x-molecules.field name="minimum_pieces" :remember="false" label="Ambang (biji)" type="number" min="0" max="1000000000" :value="$row['minimum_pieces']" />
                                        <x-molecules.field name="reason" :remember="false" label="Alasan perubahan" required maxlength="2000" />
                                        <x-atoms.button>Simpan ambang</x-atoms.button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $report['can_manage'] ? 2 : 1 }}" class="py-6 text-center text-slate-500">
                                Tidak ada SKU yang cocok dengan filter. SKU tanpa ambang tidak memicu peringatan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex items-center justify-between text-sm text-slate-600">
            <span>Halaman {{ $report['current_page'] }} dari {{ $report['last_page'] }} · {{ $report['total'] }} SKU</span>
            <div class="flex gap-2">
                @if($report['current_page'] > 1)
                    <a href="?{{ http_build_query([...$filters, 'page' => $report['current_page'] - 1]) }}" class="button button--lite">Sebelumnya</a>
                @endif
                @if($report['current_page'] < $report['last_page'])
                    <a href="?{{ http_build_query([...$filters, 'page' => $report['current_page'] + 1]) }}" class="button button--lite">Berikutnya</a>
                @endif
            </div>
        </div>
    </section>
</x-templates.console>
