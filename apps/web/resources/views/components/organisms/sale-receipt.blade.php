@props(['sale', 'settings' => null])
@php($cfg = $settings ?? session('pos_receipt_settings', ['header_text' => '', 'footer_text' => '', 'show_address' => true, 'show_cashier' => true, 'show_discount' => true, 'show_logo' => false, 'logo_data_uri' => null]))
<article class="panel receipt">
    @if(($cfg['show_logo'] ?? false) && !empty($cfg['logo_data_uri']))
        <div style="text-align:center;margin-bottom:8px;">
            <img src="{{ $cfg['logo_data_uri'] }}" alt="Logo struk" style="max-height:56px;max-width:160px;margin:0 auto;">
        </div>
    @endif
    <h1>{{ $sale['store_name'] }}</h1>
    @if($cfg['show_address'] ?? true)
        <p>{{ $sale['store_address'] }}</p>
    @endif
    @if(!empty($cfg['header_text']))
        <p>{{ $cfg['header_text'] }}</p>
    @endif
    <strong>{{ $sale['number'] }}</strong>
    <p>{{ \Carbon\Carbon::parse($sale['sold_at'])->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB @if($cfg['show_cashier'] ?? true) · Kasir {{ $sale['cashier_name'] }}@endif</p>
    @if(!empty($sale['service_type']))
        <p>{{ ['dine_in' => 'Makan di tempat', 'takeaway' => 'Bawa pulang'][$sale['service_type']] ?? $sale['service_type'] }}</p>
    @endif
    <hr>
    @foreach($sale['lines'] as $line)
        <p>{{ $line['sku'] }} · {{ $line['name'] }}<br>{{ $line['quantity'] }} {{ $line['unit'] }} ({{ $line['pieces_per_unit'] }} biji/unit) × Rp {{ number_format($line['unit_price'], 0, ',', '.') }}<br>Rp {{ number_format($line['line_total'], 0, ',', '.') }}</p>
    @endforeach
    <hr>
    <p>Subtotal Rp {{ number_format($sale['subtotal'], 0, ',', '.') }}@if($cfg['show_discount'] ?? true) · Diskon Rp {{ $sale['discount_amount'] }}@endif</p>
    <h2>Total Rp {{ number_format($sale['total_amount'], 0, ',', '.') }}</h2>
    @if($sale['payment_method'] === 'cash')
        <p>Tunai Rp {{ number_format($sale['cash_received'], 0, ',', '.') }}</p>
        <p>Kembalian Rp {{ number_format($sale['change_amount'], 0, ',', '.') }}</p>
    @else
        <p>Referensi terverifikasi {{ $sale['payment_reference'] ?? '' }}</p>
    @endif
    @foreach($sale['refunds'] ?? [] as $refund)
        <p>Refund Rp{{ number_format($refund['amount'], 0, ',', '.') }} · {{ $refund['reference'] }} · {{ $refund['payment_method'] }}</p>
    @endforeach
    @if(!empty($sale['consumption']))
        <p class="no-print">Kemasan dan alat makan terpakai: @foreach($sale['consumption'] as $used){{ $used['name'] }} {{ $used['quantity'] }} {{ $used['unit'] }}@if(($used['shortfall'] ?? 0) > 0) <strong class="stock-short">stok kurang {{ $used['shortfall'] }}</strong>@endif{{ $loop->last ? '' : ', ' }}@endforeach</p>
    @endif
    <p>{{ $sale['currency'] }} · {{ $sale['status'] }} · {{ $sale['payment_method'] }}</p>
    @if(!empty($cfg['footer_text']))
        <p style="margin-top:6px;">{{ $cfg['footer_text'] }}</p>
    @endif
</article>
