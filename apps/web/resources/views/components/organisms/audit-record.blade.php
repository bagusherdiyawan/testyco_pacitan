@props(['entry'])
<article class="panel audit-record">
    <h2>{{ $entry['action'] }}</h2>
    <p>{{ \Carbon\CarbonImmutable::parse($entry['occurred_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB · {{ $entry['actor_id'] === null ? 'Pelaku tidak tersedia' : 'Pelaku #'.$entry['actor_id'] }}</p>
    <p>Objek: {{ $entry['subject_id'] }}</p>
    <details><summary>Perubahan sebelum / sesudah</summary>
        <div class="form-grid">
            <x-molecules.json-snapshot label="Sebelum" :value="$entry['before']" />
            <x-molecules.json-snapshot label="Sesudah" :value="$entry['after']" />
        </div>
        <p>ID audit: {{ $entry['id'] }}</p>
    </details>
</article>
