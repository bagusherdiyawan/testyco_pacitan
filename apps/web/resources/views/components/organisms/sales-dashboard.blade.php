@props(['dashboard'])
@php
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $t = $dashboard['totals'];
    $tiles = [
        ['Omzet hari ini', $rp($dashboard['today']['revenue']), 'tunai dan transfer', 'ni-money-coins', 'stat-icon-red'],
        ['Omzet 7 hari', $rp($t['revenue']), 'tunai '.$rp($t['cash']).' · transfer '.$rp($t['noncash']), 'ni-chart-bar-32', 'stat-icon-yellow'],
        ['Transaksi 7 hari', number_format((int) $t['transactions'], 0, ',', '.'), $dashboard['today']['transactions'].' hari ini', 'ni-cart', 'stat-icon-orange'],
        ['Refund 7 hari', $rp($t['refunds']), 'omzet bersih '.$rp($t['net_revenue']), 'ni-credit-card', 'stat-icon-dark'],
    ];
@endphp
<section class="dashboard mb-4" aria-label="Ringkasan penjualan">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="mb-0">Ringkasan penjualan</h1>
            <p class="mb-0 text-sm">{{ $dashboard['organization']['name'] }} · {{ $dashboard['period'] }} (WIB)</p>
        </div>
        @if($dashboard['others'])
            <div class="d-flex flex-wrap gap-2" role="group" aria-label="Pilih organisasi">
                @foreach($dashboard['others'] as $other)<a class="btn btn-sm btn-outline-danger mb-0" href="/app?org={{ $other['id'] }}">{{ $other['name'] }}</a>@endforeach
            </div>
        @endif
    </div>
    <div class="row g-3">
        @foreach($tiles as [$label, $value, $note, $icon, $tone])
            <div class="col-xl-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body p-3">
                        <div class="row align-items-center">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-capitalize font-weight-bold">{{ $label }}</p>
                                    <h5 class="font-weight-bolder mb-0">{{ $value }}</h5>
                                    <p class="text-xs mb-0">{{ $note }}</p>
                                </div>
                            </div>
                            <div class="col-4 text-end"><div class="icon icon-shape {{ $tone }} shadow text-center"><i class="ni {{ $icon }}" aria-hidden="true"></i></div></div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @unless($dashboard['has_sales'])
        <aside class="mt-3" role="status">Belum ada penjualan dalam 7 hari terakhir. Grafik akan terisi setelah ada transaksi.</aside>
    @endunless
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="card chart-card h-100">
                <div class="card-header pb-0"><h6 class="mb-0">Omzet harian</h6><p class="text-sm mb-0"><span class="legend-dot" style="background:var(--red-500)"></span>Tunai <span class="legend-dot ms-2" style="background:var(--yellow-400)"></span>Transfer dan QRIS</p></div>
                <div class="card-body p-3"><div class="chart-wrap"><canvas id="chart-trend" role="img" aria-label="Grafik omzet harian tunai dan non-tunai selama 7 hari" data-chart='@json(['type' => 'trend', ...$dashboard['trend']])'></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card chart-card h-100">
                <div class="card-header pb-0"><h6 class="mb-0">Produk terlaris</h6><p class="text-sm mb-0">Menurut nilai penjualan, 7 hari</p></div>
                <div class="card-body p-3"><div class="chart-wrap"><canvas id="chart-products" role="img" aria-label="Grafik lima produk dengan penjualan tertinggi" data-chart='@json(['type' => 'products', ...$dashboard['products']])'></canvas></div></div>
            </div>
        </div>
    </div>
</section>
@push('scripts')<script src="/dashboard-charts.js?v={{ @filemtime(public_path('dashboard-charts.js')) }}"></script>@endpush
