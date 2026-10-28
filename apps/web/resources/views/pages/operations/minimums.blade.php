<x-templates.console>
<h1>Stok minimum · {{ $report['store']['name'] }}</h1>
<p><a href="/app/stores/{{ $report['store']['id'] }}/operations">Kembali ke stok toko</a></p>
<p>Jumlah dalam biji: utuh + terbuka + siap, tidak termasuk karantina/susut. Ini bukan jaminan mutu atau jumlah siap saji. Peringatan muncul jika stok lebih kecil dari ambang; tidak membuat pesanan otomatis.</p>
<p>Data server: {{ $report['observed_at'] }}. Muat ulang untuk saldo terbaru.</p>
<form method="get"><div class="form-grid"><x-molecules.field name="q" label="Cari SKU/nama" :value="$filters['q'] ?? ''" maxlength="100" /><label><input type="checkbox" name="low_only" value="1" @checked($filters['low_only'] ?? false)> Hanya stok rendah</label></div><x-atoms.button>Perbarui tampilan</x-atoms.button></form>
@if($report['can_manage'])<p>Isi ambang untuk mengaktifkan. Kosongkan untuk menonaktifkan peringatan SKU tersebut; angka 0 tetap ambang aktif. Perubahan harus disertai alasan.</p>@endif
<div class="module-grid">
@forelse($report['data'] as $row)
<section class="panel"><x-molecules.stock-minimum-status :row="$row" />
@if($report['can_manage'])<form method="post" action="/app/stores/{{ $report['store']['id'] }}/stock-minimums/{{ $row['product_id'] }}">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $row['version'] }}"><x-molecules.field name="minimum_pieces" :remember="false" label="Ambang (biji)" type="number" min="0" max="1000000000" :value="$row['minimum_pieces']" /><x-molecules.field name="reason" :remember="false" label="Alasan perubahan" required maxlength="2000" /><x-atoms.button>Simpan ambang</x-atoms.button></form>@endif
</section>
@empty<p>Tidak ada SKU yang cocok dengan filter. SKU tanpa ambang tidak memicu peringatan.</p>@endforelse
</div>
<p>Halaman {{ $report['current_page'] }} dari {{ $report['last_page'] }} · {{ $report['total'] }} SKU</p>
@if($report['current_page'] > 1)<a href="?{{ http_build_query([...$filters, 'page' => $report['current_page'] - 1]) }}">Sebelumnya</a>@endif
@if($report['current_page'] < $report['last_page'])<a href="?{{ http_build_query([...$filters, 'page' => $report['current_page'] + 1]) }}">Berikutnya</a>@endif
</x-templates.console>
