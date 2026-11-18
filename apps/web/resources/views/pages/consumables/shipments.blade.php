<x-templates.console>
<a href="/app/stores/{{ $store }}/consumables">← Stok kemasan dan alat makan</a>
<h1>Kiriman kemasan dan alat makan</h1>
<p>Pusat mengirim kemasan dan alat makan langsung dari gudang ke booth. Stok gudang berkurang saat dikirim, dan booth menambah stoknya sendiri setelah memeriksa jumlah yang benar-benar datang. Kiriman ini tidak memakai harga atau tagihan.</p>
@php($labels = ['dispatched' => 'Dalam perjalanan', 'received' => 'Sudah diterima'])

@if($canSend)
<form class="panel" method="post" action="/app/stores/{{ $store }}/consumable-shipments">@csrf<x-molecules.operation-key :remember="false" />
<h2>Kirim ke booth</h2>
<label>Booth tujuan<select name="destination_store_id" required><option value="">Pilih booth</option>@foreach($destinations as $d)<option value="{{ $d['id'] }}">{{ $d['name'] }} · {{ $d['organization_name'] }}</option>@endforeach</select></label>
@for($i = 0; $i < 5; $i++)
<div class="form-grid">
<label>Barang {{ $i + 1 }}<select name="lines[{{ $i }}][consumable_item_id]"><option value="">{{ $i === 0 ? 'Pilih barang' : '(kosongkan bila tidak dipakai)' }}</option>@foreach($items as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }} · stok {{ $item['balance'] }} {{ $item['unit'] }}</option>@endforeach</select></label>
<x-molecules.field :name="'lines['.$i.'][quantity]'" label="Jumlah" type="number" min="1" :remember="false" />
</div>
@endfor
<x-molecules.field name="note" label="Catatan (surat jalan, kurir)" maxlength="500" :remember="false" />
<x-atoms.button>Kirim dan kurangi stok gudang</x-atoms.button>
</form>
@endif

<h2>Kiriman</h2>
@forelse($shipments as $s)
<section class="panel">
<h3>{{ $s['direction'] === 'outgoing' ? 'Ke '.$s['destination_store_name'] : 'Dari '.$s['source_store_name'] }} <span class="status-chip {{ $s['status'] === 'received' ? 'is-available' : '' }}">{{ $labels[$s['status']] ?? $s['status'] }}</span></h3>
<p>Dikirim {{ \Carbon\Carbon::parse($s['dispatched_at'])->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB @if($s['note']) · {{ $s['note'] }}@endif</p>
@if($s['status'] === 'dispatched' && $s['direction'] === 'incoming' && $canReceive)
<form method="post" action="/app/consumable-shipments/{{ $s['id'] }}/receive">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="store_id" value="{{ $store }}"><input type="hidden" name="version" value="{{ $s['version'] }}">
<p>Periksa jumlah yang benar-benar datang dan pilih barang milik booth yang menerimanya.</p>
@foreach($s['lines'] as $i => $line)
<div class="form-grid">
<input type="hidden" name="lines[{{ $i }}][line_id]" value="{{ $line['id'] }}">
<label>{{ $line['name'] }} (dikirim {{ $line['quantity_sent'] }} {{ $line['unit'] }}) disimpan sebagai<select name="lines[{{ $i }}][destination_item_id]" required><option value="">Pilih barang</option>@foreach($items as $item)<option value="{{ $item['id'] }}" @selected(strcasecmp($item['name'], $line['name']) === 0)>{{ $item['name'] }} ({{ $item['unit'] }})</option>@endforeach</select></label>
<x-molecules.field :name="'lines['.$i.'][quantity_received]'" label="Jumlah diterima" type="number" min="0" :max="$line['quantity_sent']" :value="$line['quantity_sent']" :remember="false" required />
</div>
@endforeach
<x-molecules.field name="note" label="Alasan bila jumlah kurang" maxlength="1000" :remember="false" />
<x-atoms.button>Konfirmasi penerimaan</x-atoms.button>
</form>
@else
<ul>@foreach($s['lines'] as $line)<li>{{ $line['name'] }}: dikirim {{ $line['quantity_sent'] }} {{ $line['unit'] }}@if($line['quantity_received'] !== null) · diterima {{ $line['quantity_received'] }}@if($line['quantity_received'] < $line['quantity_sent']) <span class="stock-short">kurang {{ $line['quantity_sent'] - $line['quantity_received'] }}</span>@endif @endif</li>@endforeach</ul>
@if($s['receive_note'])<p>Catatan penerima: {{ $s['receive_note'] }}</p>@endif
@endif
</section>
@empty
<p>Belum ada kiriman.</p>
@endforelse
</x-templates.console>
