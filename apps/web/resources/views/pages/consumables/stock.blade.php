<x-templates.console>
<a href="/app/stores/{{ $store }}/operations">← Operasional toko</a>
<h1>Stok kemasan dan alat makan</h1>
<p><a href="/app/stores/{{ $store }}/consumable-shipments">Kiriman dari pusat dan ke booth →</a></p>
<p>Jumlah stok dihitung dari pembelian, penjualan, dan hitung fisik. Penjualan tidak ditolak saat stok habis; barang yang kurang ditandai agar segera dibeli atau dihitung ulang.</p>
@php($short = collect($items)->where('short', true))
@if($short->isNotEmpty())<div role="alert" class="errors">Stok kurang: {{ $short->pluck('name')->implode(', ') }}. Catat pembelian atau hitung fisik untuk memperbaikinya.</div>@endif

<section class="panel">
<h2>Saldo saat ini</h2>
@if(count($items))
<table><thead><tr><th>Barang</th><th>Saldo</th><th>Status</th></tr></thead><tbody>
@foreach($items as $item)<tr><td>{{ $item['name'] }}</td><td>{{ $item['balance'] }} {{ $item['unit'] }}</td><td>@if($item['short'])<span class="stock-short">Kurang</span>@elseif($item['balance'] === 0)<span class="stock-zero">Habis</span>@else<span class="stock-ok">Cukup</span>@endif</td></tr>@endforeach
</tbody></table>
@else
<p>Belum ada barang. Pengelola organisasi menambahkannya di menu kemasan dan alat makan.</p>
@endif
</section>

@if(count($items))
<form class="panel" method="post" action="/app/stores/{{ $store }}/consumables/purchases">@csrf<x-molecules.operation-key :remember="false" />
<h2>Catat belanja barang</h2>
<div class="form-grid">
<label>Barang<select name="consumable_item_id" required><option value="">Pilih</option>@foreach($items as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }} ({{ $item['unit'] }})</option>@endforeach</select></label>
<x-molecules.field name="quantity" label="Jumlah dibeli" type="number" min="1" :remember="false" required />
<x-molecules.field name="amount" label="Total belanja (Rp)" type="number" min="0" :remember="false" />
<x-molecules.field name="note" label="Catatan (toko, nota)" maxlength="400" :remember="false" />
</div>
@if($shift)<input type="hidden" name="shift_id" value="{{ $shift['id'] }}"><input type="hidden" name="shift_version" value="{{ $shift['version'] }}"><label><input type="checkbox" name="record_cash" value="1">Catat juga sebagai kas keluar dari shift yang sedang buka (wajib isi total belanja)</label>@else<p>Tidak ada shift yang sedang buka, jadi belanja hanya menambah stok dan tidak mengurangi kas.</p>@endif
<x-atoms.button>Simpan belanja</x-atoms.button>
</form>

<form class="panel" method="post" action="/app/stores/{{ $store }}/consumables/counts">@csrf<x-molecules.operation-key :remember="false" />
<h2>Hitung fisik harian</h2>
<p>Isi jumlah yang benar-benar ada. Saldo akan disesuaikan dan selisihnya dicatat bersama alasannya.</p>
<div class="form-grid">
<label>Barang<select name="consumable_item_id" required><option value="">Pilih</option>@foreach($items as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }} (saldo {{ $item['balance'] }})</option>@endforeach</select></label>
<x-molecules.field name="counted" label="Jumlah fisik" type="number" min="0" :remember="false" required />
<x-molecules.field name="reason" label="Alasan atau catatan" maxlength="1000" :remember="false" required />
</div>
<x-atoms.button>Simpan hitung fisik</x-atoms.button>
</form>
@endif

<section class="panel">
<h2>Riwayat terbaru</h2>
@php($labels = ['purchase' => 'Pembelian', 'sale' => 'Penjualan', 'count' => 'Hitung fisik', 'shipment_in' => 'Kiriman dari pusat', 'shipment_out' => 'Kiriman ke booth'])
@forelse($recent as $entry)
<p>{{ \Carbon\Carbon::parse($entry['created_at'])->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB · {{ $names[$entry['consumable_item_id']] ?? 'Barang nonaktif' }} · {{ $entry['delta'] > 0 ? '+' : '' }}{{ $entry['delta'] }} · {{ $labels[$entry['reason_type']] ?? $entry['reason_type'] }}@if($entry['amount']) · Rp {{ number_format($entry['amount'],0,',','.') }}@endif @if($entry['note']) · {{ $entry['note'] }}@endif</p>
@empty
<p>Belum ada pergerakan stok.</p>
@endforelse
</section>
</x-templates.console>
