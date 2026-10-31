<x-templates.console>
<a href="/app/stores/{{ $storeId }}/operations">← Pesanan dan stok toko</a>
<h1>Riwayat pergerakan stok · {{ $metadata['store']['name'] }}</h1>
@if(!$metadata['store']['active'])<aside>Toko nonaktif. Riwayat berizin tetap dapat dibaca.</aside>@endif
<p>Perubahan per bucket dalam biji. Baris yang terlihat bukan saldo akhir atau saldo berjalan. Referensi sumber bukan UUID operasi dan tidak memberikan izin membuka objek lain.</p>
<form method="get" action="{{ url()->current() }}"><div class="form-grid">
    <x-molecules.field name="from_day" label="Tanggal awal WIB" type="date" :value="$filters['from_day']" :remember="false" required />
    <x-molecules.field name="to_day" label="Tanggal akhir WIB (termasuk)" type="date" :value="$filters['to_day']" :remember="false" required />
    <x-molecules.field name="q" label="Cari SKU / nama / batch" :value="$filters['q'] ?? ''" :remember="false" maxlength="100" />
    <x-molecules.field name="batch_id" label="ID batch persis (opsional)" :value="$filters['batch_id'] ?? ''" :remember="false" maxlength="26" />
    <label class="field">Sumber<select name="source_type"><option value="">Semua sumber</option>@foreach($sources as $code=>$label)<option value="{{ $code }}" @selected(($filters['source_type'] ?? '') === $code)>{{ $label }}</option>@endforeach</select></label>
    <label class="field">Bucket<select name="bucket"><option value="">Semua bucket</option>@foreach($buckets as $code=>$label)<option value="{{ $code }}" @selected(($filters['bucket'] ?? '') === $code)>{{ $label }}</option>@endforeach</select></label>
</div><x-atoms.button>Terapkan filter</x-atoms.button></form>
<p>Periode diterapkan: {{ $filters['from_day'] }} — {{ $filters['to_day'] }} WIB (maksimal 31 hari).</p>
@if(isset($filters['q']))<p>Pencarian diterapkan: {{ $filters['q'] }}</p>@endif
@if(isset($filters['source_type']))<p>Sumber diterapkan: {{ $sources[$filters['source_type']] }}</p>@endif
@if(isset($filters['bucket']))<p>Bucket diterapkan: {{ $buckets[$filters['bucket']] }}</p>@endif
@if(isset($filters['batch_id']))<p>Batch diterapkan: {{ $filters['batch_id'] }}</p><p><a href="{{ url()->current() }}?{{ http_build_query(array_diff_key($filters, ['batch_id'=>true])) }}">Lihat semua batch</a></p>@endif
<p>Diamati {{ \Carbon\CarbonImmutable::parse($metadata['observed_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB. Setiap halaman dibaca terpisah, bukan snapshot beku.</p>
@forelse($entries as $entry)
    <x-molecules.stock-movement :entry="$entry" :sources="$sources" :buckets="$buckets" />
@empty
    <p>Tidak ada pergerakan pada filter ini.</p>
@endforelse
<x-molecules.pagination :items="$entries" />
</x-templates.console>
