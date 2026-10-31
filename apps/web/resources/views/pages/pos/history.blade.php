<x-templates.console>
<a href="/app/stores/{{ $store }}/pos">← POS</a>
<h1>Riwayat struk · {{ $metadata['store']['name'] }}</h1>
<p>{{ $metadata['scope'] === 'own' ? 'Cakupan: struk saya.' : 'Cakupan: seluruh kasir toko yang diizinkan.' }}</p>
@if(!$metadata['store']['active'])<aside>Toko nonaktif. Struk historis berizin tetap dapat dibaca.</aside>@endif
<form method="get" action="{{ url()->current() }}"><div class="form-grid">
    <x-molecules.field name="q" label="Cari nomor struk" :value="$filters['q'] ?? ''" :remember="false" maxlength="100" />
    <x-molecules.field name="from_day" type="date" label="Tanggal awal WIB (opsional)" :value="$filters['from_day'] ?? ''" :remember="false" />
    <x-molecules.field name="to_day" type="date" label="Tanggal akhir WIB, termasuk (opsional)" :value="$filters['to_day'] ?? ''" :remember="false" />
</div><x-atoms.button>Terapkan filter</x-atoms.button></form>
<p>Tanpa tanggal: seluruh riwayat berhalaman. Jika memakai tanggal, isi kedua tanggal dengan rentang maksimal 31 hari kalender WIB.</p>
@if(isset($filters['from_day']))<p>Periode diterapkan: {{ $filters['from_day'] }} — {{ $filters['to_day'] }} WIB.</p>@else<p>Periode diterapkan: seluruh riwayat.</p>@endif
@if(isset($filters['q']))<p>Nomor struk diterapkan: {{ $filters['q'] }}</p>@endif
<p>Setiap halaman dibaca terpisah; bukan snapshot beku. Buka struk untuk melihat rincian pembayaran historis.</p>
@forelse($sales as $sale)
    <x-molecules.receipt-summary :sale="$sale" />
@empty
    <p>Tidak ada struk pada filter ini.</p>
@endforelse
<x-molecules.pagination :items="$sales" />
</x-templates.console>
