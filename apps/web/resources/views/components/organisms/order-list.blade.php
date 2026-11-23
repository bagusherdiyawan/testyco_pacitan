@props(['orders'])
@if(count($orders))
<div class="panel">
<table>
    <thead>
        <tr>
            <th>Status</th>
            <th>Tujuan</th>
            <th>Jenis</th>
            <th>Total</th>
            <th>ID Pesanan</th>
            <th>Aksi</th>
        </tr>
    </thead>
    <tbody>
        @foreach($orders as $order)
        <tr>
            <td><x-atoms.badge>{{ $order['status'] }}</x-atoms.badge></td>
            <td>{{ $order['destination_name'] }}</td>
            <td>{{ $order['kind'] === 'internal_transfer' ? 'Transfer internal' : 'Pembelian mitra' }}</td>
            <td>Rp {{ number_format($order['total_amount'], 0, ',', '.') }}</td>
            <td><code>{{ $order['id'] }}</code></td>
            <td><a href="/app/orders/{{ $order['id'] }}">Detail pesanan</a></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@else
<p class="panel">Belum ada pesanan sesuai akses Anda.</p>
@endif
<x-molecules.pagination :items="$orders" />
