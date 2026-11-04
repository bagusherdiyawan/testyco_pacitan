@props(['item'])
<article class="panel"><h3>Retur {{ $item['id'] }}</h3><p>Petugas {{ $item['handler'] }} · {{ $item['received_at'] ? 'Diterima' : 'Dalam pengiriman' }} · {{ $item['dispatched_at'] }}</p><p>{{ $item['note'] ?? '' }}</p>
@foreach($item['lines'] as $line)<p>{{ $line['sku'] }} → {{ $line['local_sku'] }} · Batch {{ $line['batch_code'] }} · Kedaluwarsa {{ substr($line['expires_on'],0,10) }} · Isi {{ $line['pack_size'] }} · Kirim {{ $line['quantity_packs'] }} kemasan @if($item['received_at']) · Karantina {{ $line['received_packs'] }} · Kurang {{ $line['quantity_packs']-$line['received_packs'] }} · {{ $line['condition_note'] }} @endif</p>@endforeach
@if($item['received_at'])<p>{{ $item['receipt_note'] ?? '' }}</p>@endif</article>
