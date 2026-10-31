@props(['batch'])
<article class="panel batch-expiry">
<h2>{{ $batch['sku'] }} · {{ $batch['name'] }}</h2>
<p>Batch {{ $batch['batch_code'] }} · Tanggal kedaluwarsa kemasan: {{ $batch['expires_on'] }}</p>
<p>Kondisi {{ $batch['condition'] }} · Isi kemasan historis {{ $batch['pack_size'] }} biji</p>
<p>Utuh {{ number_format($batch['usable_pieces'], 0, ',', '.') }} · Beku terbuka {{ number_format($batch['opened_pieces'], 0, ',', '.') }} · Siap jual {{ number_format($batch['ready_pieces'], 0, ',', '.') }} biji</p>
<p>Karantina {{ number_format($batch['quarantined_pieces'], 0, ',', '.') }} · Susut kumulatif {{ number_format($batch['waste_pieces'], 0, ',', '.') }} biji</p>
<details><summary>ID batch</summary><p>{{ $batch['id'] }}</p></details>
</article>
