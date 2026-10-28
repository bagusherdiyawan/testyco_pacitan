@props(['row'])
<div>
<strong>{{ $row['sku'] }} · {{ $row['name'] }}</strong>
<p>Tersedia {{ $row['available_pieces'] }} biji · Ambang {{ $row['minimum_pieces'] === null ? 'belum diaktifkan' : $row['minimum_pieces'].' biji' }}</p>
<p>Utuh {{ $row['usable_pieces'] }} · Terbuka {{ $row['opened_pieces'] }} · Siap {{ $row['ready_pieces'] }}</p>
@if($row['is_low'])<p role="status"><strong>Stok rendah · Kekurangan {{ $row['shortfall_pieces'] }} biji dari ambang</strong></p>@elseif($row['minimum_pieces'] !== null)<p>Stok memenuhi ambang.</p>@endif
</div>
