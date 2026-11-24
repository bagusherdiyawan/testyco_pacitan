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
@if(count($batches))
<div class="panel">
<table>
    <thead>
        <tr>
            <th>Produk</th>
            <th>Batch &amp; Kondisi</th>
            <th>Kedaluwarsa Kemasan</th>
            <th>Saldo (Utuh / Terbuka / Siap)</th>
            <th>Karantina &amp; Susut</th>
            <th>ID Batch</th>
        </tr>
    </thead>
    <tbody>
        @foreach($batches as $batch)
        <tr class="batch-expiry">
            <td><strong>{{ $batch['sku'] }} · {{ $batch['name'] }}</strong></td>
            <td>Batch {{ $batch['batch_code'] }} · Kondisi {{ $batch['condition'] }} · Isi kemasan historis {{ $batch['pack_size'] }} biji</td>
            <td>Tanggal kedaluwarsa kemasan: {{ $batch['expires_on'] }}</td>
            <td>Utuh {{ number_format($batch['usable_pieces'], 0, ',', '.') }} · Beku terbuka {{ number_format($batch['opened_pieces'], 0, ',', '.') }} · Siap jual {{ number_format($batch['ready_pieces'], 0, ',', '.') }} biji</td>
            <td>Karantina {{ number_format($batch['quarantined_pieces'], 0, ',', '.') }} · Susut kumulatif {{ number_format($batch['waste_pieces'], 0, ',', '.') }} biji</td>
            <td><details><summary>ID batch</summary><p>{{ $batch['id'] }}</p></details></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@else
<p class="panel">Tidak ada batch pada filter ini.</p>
@endif
<x-molecules.pagination :items="$batches" />
</x-templates.console>
