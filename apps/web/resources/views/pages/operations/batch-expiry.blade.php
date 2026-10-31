<x-templates.console>
<a href="/app/stores/{{ $store }}/operations">← Pesanan dan stok toko</a>
<h1>Penelusuran kedaluwarsa batch · {{ $metadata['store']['name'] }}</h1>
@if(!$metadata['store']['active'])<aside>Toko nonaktif. Data berizin tetap dapat dibaca.</aside>@endif
<p>Diurutkan berdasarkan tanggal kedaluwarsa kemasan, lalu ID batch. Pilih batas tanggal untuk penelusuran yang diperlukan.</p>
<form method="get" action="{{ url()->current() }}"><div class="form-grid">
<x-molecules.field name="q" label="Cari SKU / nama / batch" :value="$filters['q'] ?? ''" :remember="false" maxlength="100" />
<x-molecules.field name="expires_from" type="date" label="Kedaluwarsa mulai (opsional)" :value="$filters['expires_from'] ?? ''" :remember="false" />
<x-molecules.field name="expires_to" type="date" label="Kedaluwarsa sampai, termasuk (opsional)" :value="$filters['expires_to'] ?? ''" :remember="false" />
<label class="field">Saldo<select name="with_balance"><option value="0" @selected(!($filters['with_balance'] ?? false))>Semua batch</option><option value="1" @selected($filters['with_balance'] ?? false)>Ada saldo utuh/terbuka/siap/karantina</option></select></label>
</div><x-atoms.button>Terapkan filter</x-atoms.button></form>
<p>Filter diterapkan: mulai {{ $filters['expires_from'] ?? 'tanpa batas' }} sampai {{ $filters['expires_to'] ?? 'tanpa batas' }} (tanggal kemasan termasuk).</p>
@if(isset($filters['q']))<p>Pencarian diterapkan: {{ $filters['q'] }}</p>@endif
<p>{{ ($filters['with_balance'] ?? false) ? 'Hanya batch dengan saldo positif pada salah satu bucket utuh/terbuka/siap/karantina.' : 'Seluruh batch termasuk saldo nol.' }} Susut kumulatif sendiri tidak memenuhi filter saldo.</p>
<p>Tanggal kemasan bukan masa simpan hasil kukus atau sertifikasi mutu. Penelusuran ini tidak memilih batch penjualan otomatis, memblokir penjualan, atau menjalankan recall. Periode peringatan dan SOP mutu masih memerlukan keputusan bisnis.</p>
<p>Diamati {{ \Carbon\CarbonImmutable::parse($metadata['observed_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB. Saldo dibaca live per halaman.</p>
@forelse($batches as $batch)<x-molecules.batch-expiry :batch="$batch" />@empty<p>Tidak ada batch pada filter ini.</p>@endforelse
<x-molecules.pagination :items="$batches" />
</x-templates.console>
