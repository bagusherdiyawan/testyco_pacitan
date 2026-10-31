@props(['entry', 'sources', 'buckets'])
<article class="panel stock-movement">
    <h2>{{ $entry['sku'] }} · {{ $entry['name'] }}</h2>
    <p>Batch {{ $entry['batch_code'] }} · Isi kemasan historis: {{ $entry['pack_size'] }} biji · {{ $entry['condition'] }}</p>
    <p>Kedaluwarsa kemasan: {{ $entry['expires_on'] ?? '—' }}</p>
    <strong>{{ $entry['quantity_pieces'] > 0 ? '+' : '' }}{{ number_format($entry['quantity_pieces'], 0, ',', '.') }} biji · {{ $buckets[$entry['bucket']] ?? $entry['bucket'] }}</strong>
    <p>{{ $sources[$entry['source_type']] ?? $entry['source_type'] }} · {{ \Carbon\CarbonImmutable::parse($entry['occurred_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB</p>
    <p>Pelaku #{{ $entry['actor_id'] }} · Referensi sumber: {{ $entry['source_id'] }}</p>
    @if($entry['note'] !== null)<p>{{ $entry['note'] }}</p>@endif
    <details><summary>ID catatan dan batch</summary><p>ID ledger: {{ $entry['id'] }}</p><p>ID batch: {{ $entry['inventory_batch_id'] }}</p></details>
</article>
