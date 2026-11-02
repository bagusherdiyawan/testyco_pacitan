@props(['snapshot'])
@if($snapshot)
<section class="panel"><h2>Penerimaan yang ditinjau</h2>
<p>Diterima {{ $snapshot['received_at'] }} oleh #{{ $snapshot['received_by'] }} · {{ $snapshot['problem_packs'] }} kemasan rusak/kurang.</p><p>{{ $snapshot['receipt_note'] }}</p>
@foreach($snapshot['lines'] as $line)<article><strong>{{ $line['sku'] }} → {{ $line['local_sku'] }} · {{ $line['batch_code'] }}</strong>
<p>Isi {{ $line['pack_size'] }} biji · Kedaluwarsa kemasan {{ $line['expires_on'] }} · Dikirim {{ $line['sent_packs'] }} kemasan · Baik {{ $line['good_packs'] }} · Rusak {{ $line['damaged_packs'] }} ({{ $line['damaged_pieces'] }} biji) · Kurang {{ $line['missing_packs'] }} ({{ $line['missing_pieces'] }} biji).</p><p>{{ $line['condition_note'] }}</p></article>@endforeach
<p>Snapshot penerimaan, bukan saldo tersedia saat ini. Sisa pesanan yang belum dikirim tidak dihitung sebagai barang hilang.</p></section>
@endif
