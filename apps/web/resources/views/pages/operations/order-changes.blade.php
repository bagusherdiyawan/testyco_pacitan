<x-templates.console>
<a href="/app/orders/{{ $orderId }}">← Pesanan</a><h1>Usulan pengurangan setelah persetujuan</h1>
@if($pending !== null)
<aside>Hasil request belum pasti. Payload, versi dan UUID ditahan di session server. Ulangi request yang sama; tidak ada tombol membuat pengganti.</aside>
<p>Pesanan {{ $orderId }} · Versi yang dikirim {{ $pending['body']['version'] }}</p>
<x-molecules.json-snapshot label="Request yang ditahan" :value="$pending['body']" />
<form method="post" action="/app/orders/{{ $orderId }}/changes/retry">@csrf<x-atoms.button>Ulangi request yang sama</x-atoms.button></form>
@else
<p>Pesanan {{ $orderId }} · Versi {{ $context['order']['version'] }} · {{ $context['order']['status'] }}</p>
<p>Tahap ini untuk pengurangan jumlah baris yang sudah disetujui, minimal satu kemasan per baris, sebelum pengiriman pertama. Harga/kemasan tetap sesuai persetujuan. Tolak atau tarik usulan mempertahankan pesanan asli.</p>
@if($context['change_pending'])<aside>Proses dan pengiriman ditahan hingga usulan diputuskan atau ditarik. Jumlah pesanan belum berubah.</aside>@endif
@if($context['actions']['propose'])
<form class="panel" method="post" action="/app/orders/{{ $orderId }}/changes">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $context['order']['version'] }}">
<h2>Usulkan jumlah pengganti</h2><p>Isi semua baris. Setidaknya satu baris harus berkurang; harga, SKU dan pemetaan tetap.</p>
@foreach($context['order']['lines'] as $i=>$line)<fieldset><legend>{{ $line['sku'] }} · Saat ini {{ $line['quantity_packs'] }} kemasan</legend><input type="hidden" name="lines[{{ $i }}][order_line_id]" value="{{ $line['id'] }}"><x-molecules.field :name="'lines['.$i.'][quantity_packs]'" label="Jumlah kemasan usulan" type="number" min="1" :max="$line['quantity_packs']" :value="$line['quantity_packs']" :remember="false" required /></fieldset>@endforeach
<x-molecules.field name="reason" label="Alasan pengurangan" :remember="false" maxlength="2000" required /><x-atoms.button>Kirim usulan untuk persetujuan ulang</x-atoms.button></form>
@endif
<h2>Riwayat usulan dan keputusan</h2>
@forelse($changes as $change)<x-organisms.order-change :change="$change" :context="$context" :order-id="$orderId" />@empty<p>Belum ada usulan perubahan.</p>@endforelse
<x-molecules.pagination :items="$changes" />
@endif
</x-templates.console>
