@props(['plan'])
<article class="panel"><h3>Rencana {{ ['return'=>'retur','replacement'=>'pengganti','credit'=>'kredit'][$plan['type']] }} · {{ $plan['status'] }}</h3>
<p>Usulan {{ $plan['id'] }} oleh #{{ $plan['proposed_by'] }}</p><p>{{ $plan['proposal'] }}</p><p>Referensi bukti: {{ $plan['reference'] ?? '—' }}</p>
@if($plan['decided_by'] ?? null)<p>Keputusan oleh #{{ $plan['decided_by'] }} · {{ $plan['decision_note'] }} · {{ $plan['decided_at'] }}</p>@endif
<p>Persetujuan rencana tidak membuat pergerakan stok, pengiriman pengganti, nota kredit atau pembayaran.</p></article>
