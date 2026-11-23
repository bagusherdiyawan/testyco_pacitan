<x-templates.console>
    <a href="/app/organizations/{{ $organization }}">← Organisasi</a>
    <h1>Jejak audit · {{ $metadata['organization']['name'] }}</h1>
    <p>Riwayat seluruh organisasi, bukan hanya satu toko. {{ $metadata['visibility'] === 'onboarding' ? 'Cakupan: hanya data onboarding yang diizinkan.' : 'Cakupan: audit organisasi yang diizinkan.' }}</p>
    <form method="get" action="{{ url()->current() }}">
        <div class="form-grid">
            <x-molecules.field name="from_day" type="date" label="Tanggal awal WIB" :value="$filters['from_day']" :remember="false" required />
            <x-molecules.field name="to_day" type="date" label="Tanggal akhir WIB (termasuk)" :value="$filters['to_day']" :remember="false" required />
            <x-molecules.field name="action" label="Aksi persis (opsional)" :value="$filters['action'] ?? ''" :remember="false" maxlength="80" placeholder="Contoh: sale.completed" />
            <x-molecules.field name="subject_id" label="ID objek persis (opsional)" :value="$filters['subject_id'] ?? ''" :remember="false" maxlength="80" />
            <x-molecules.field name="actor_id" type="number" label="ID pelaku (opsional)" :value="$filters['actor_id'] ?? ''" :remember="false" min="1" step="1" />
        </div>
        <x-atoms.button>Terapkan filter</x-atoms.button>
    </form>
    <p>Periode diterapkan: {{ $filters['from_day'] }} — {{ $filters['to_day'] }} WIB (maksimal 31 hari). Filter tambahan memakai nilai persis.</p>
    @if(isset($filters['action']))<p>Aksi diterapkan: {{ $filters['action'] }}</p>@endif
    @if(isset($filters['subject_id']))<p>Objek diterapkan: {{ $filters['subject_id'] }}</p>@endif
    @if(isset($filters['actor_id']))<p>Pelaku diterapkan: {{ $filters['actor_id'] }}</p>@endif
    <p>Diamati {{ \Carbon\CarbonImmutable::parse($metadata['observed_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB. Setiap halaman dibaca terpisah; bukan snapshot beku.</p>
    <p>Snapshot kosong berarti tidak ada snapshot tersimpan. ID objek bukan UUID operasi. Catatan keamanan global tanpa organisasi tidak termasuk halaman ini.</p>
    <p><a href="/app/organizations/{{ $organization }}/audit/export?{{ http_build_query($filters) }}">Unduh CSV audit dengan filter aktif</a> · Maksimal 10.000 catatan / 8 MiB snapshot; persempit filter jika melebihi batas. Unduhan mencakup semua halaman yang cocok.</p>
    @if(count($entries))
    <div class="panel">
    <table>
        <thead>
            <tr>
                <th>Waktu (WIB)</th>
                <th>Aksi</th>
                <th>Pelaku</th>
                <th>Objek</th>
                <th>Perubahan</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
            <tr class="audit-record">
                <td>{{ \Carbon\CarbonImmutable::parse($entry['occurred_at'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB</td>
                <td><strong>{{ $entry['action'] }}</strong></td>
                <td>{{ $entry['actor_id'] === null ? 'Pelaku tidak tersedia' : 'Pelaku #'.$entry['actor_id'] }}</td>
                <td><code>{{ $entry['subject_id'] }}</code></td>
                <td>
                    <details><summary>Perubahan sebelum / sesudah</summary>
                        <div class="form-grid">
                            <x-molecules.json-snapshot label="Sebelum" :value="$entry['before']" />
                            <x-molecules.json-snapshot label="Sesudah" :value="$entry['after']" />
                        </div>
                        <p>ID audit: {{ $entry['id'] }}</p>
                    </details>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @else
        <p class="panel">Tidak ada audit pada filter ini.</p>
    @endif
    <x-molecules.pagination :items="$entries" />
</x-templates.console>
