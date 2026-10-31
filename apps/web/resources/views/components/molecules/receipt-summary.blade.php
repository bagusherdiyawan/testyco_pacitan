@props(['sale'])
<article class="panel receipt-summary">
    <h2><a href="/app/pos/sales/{{ $sale['id'] }}">{{ $sale['number'] }}</a></h2>
    <p>{{ \Carbon\CarbonImmutable::parse($sale['sold_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB · Kasir {{ $sale['cashier_name'] }}</p>
    <p>Toko saat transaksi: {{ $sale['store_name'] }}</p>
    <strong>Rp {{ number_format($sale['total_amount'], 0, ',', '.') }}</strong>
    <p><a href="/app/pos/sales/{{ $sale['id'] }}">Buka struk tersimpan</a></p>
</article>
