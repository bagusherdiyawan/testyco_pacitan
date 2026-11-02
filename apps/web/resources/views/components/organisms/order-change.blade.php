@props(['change', 'context', 'orderId'])
<article class="panel audit-record">
<h2>Usulan {{ $change['id'] }} · {{ $change['status'] }}</h2>
<p>Versi asal {{ $change['base_version'] }} · Pengusul #{{ $change['requested_by'] }} · {{ $change['reason'] }}</p>
@php($proposed = collect($change['after']['lines'])->keyBy('id'))
<ul>@foreach($change['before']['lines'] as $line)<li>{{ $line['sku'] }} → {{ $line['local_sku'] }} · {{ $line['quantity_packs'] }} → {{ $proposed[$line['id']]['quantity_packs'] }} kemasan · Isi {{ $line['pack_size'] }} biji · Rp {{ $line['unit_price'] }}/kemasan</li>@endforeach</ul>
<p>Total sebelum Rp {{ number_format($change['before']['total_amount'],0,',','.') }} → usulan Rp {{ number_format($change['after']['total_amount'],0,',','.') }}</p>
@if($change['status'] === 'pending')
@foreach(['approve'=>'Setujui jumlah revisi','reject'=>'Tolak usulan, pertahankan pesanan asli','withdraw'=>'Tarik usulan pemasok'] as $action=>$label)
@if($context['actions'][$action] ?? false)<form method="post" action="/app/orders/{{ $orderId }}/changes/{{ $change['id'] }}/{{ $action }}">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $context['order']['version'] }}"><x-molecules.field name="reason" label="Catatan keputusan" :remember="false" maxlength="2000" :required="$action !== 'approve'" /><x-atoms.button>{{ $label }}</x-atoms.button></form>@endif
@endforeach
@else<p>Keputusan oleh #{{ $change['decided_by'] }} · {{ $change['decision_note'] }} · {{ $change['decided_at'] }}</p>@endif
</article>
