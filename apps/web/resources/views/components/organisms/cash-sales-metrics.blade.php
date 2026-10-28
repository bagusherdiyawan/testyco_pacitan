@props(['totals'])
<div class="module-grid">
<article class="panel"><h2>Transaksi</h2><strong>{{ $totals['transaction_count'] }}</strong></article>
<article class="panel"><h2>Penjualan kotor</h2><strong>Rp {{ number_format($totals['gross_sales'], 0, ',', '.') }}</strong></article>
<article class="panel"><h2>Diskon tercatat</h2><strong>Rp {{ number_format($totals['discount_amount'], 0, ',', '.') }}</strong></article>
<article class="panel"><h2>Penjualan bersih</h2><strong>Rp {{ number_format($totals['net_sales'], 0, ',', '.') }}</strong></article>
</div>
