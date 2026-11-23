<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Keamanan Pangan</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Recall terkoordinasi</h1>
            <p class="text-sm text-slate-600 mt-1">Koordinasi mengikuti batch yang bergerak melalui ledger penerimaan asli/pengganti/retur.</p>
        </div>
        <a href="/app/organizations/{{ $organization }}/food-safety" class="button button--lite">SOP &amp; Recall Organisasi</a>
    </div>

    @if($pending)
        <x-organisms.recall-pending :organization="$organization" :pending="$pending" />
    @else
        <details class="panel mb-6">
            <summary class="font-semibold text-rose-800 cursor-pointer">+ Terbitkan koordinasi recall baru</summary>
            <form method="post" action="/app/organizations/{{ $organization }}/recalls" class="mt-4 pt-4 border-t border-slate-200 space-y-3">
                @csrf
                <x-molecules.operation-key :remember="false" />
                <label class="field">Recall aktif organisasi
                    <select name="recall_id" required>
                        <option value="">Pilih recall yang telah diterbitkan</option>
                        @foreach($recalls as $r)
                            @if($r['status'] === 'active')
                                <option value="{{ $r['id'] }}">{{ $r['batch_code'] }} — {{ $r['reason'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
                <x-molecules.field name="instructions" label="Instruksi pemeriksaan, kontak dan tindakan fisik" :remember="false" required />
                <x-atoms.button>Terbitkan koordinasi recall</x-atoms.button>
            </form>
        </details>

        <section class="panel">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Daftar Kampanye Recall</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">ID Kampanye</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Instruksi</th>
                            <th class="py-2">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="border-b border-slate-100">
                                <td class="py-2.5 pr-4 font-mono text-xs">{{ $row['id'] }}</td>
                                <td class="py-2.5 pr-4"><x-atoms.badge>{{ $row['status'] }}</x-atoms.badge></td>
                                <td class="py-2.5 pr-4">{{ $row['instructions'] }}</td>
                                <td class="py-2.5"><a href="/app/organizations/{{ $organization }}/recalls/{{ $row['id'] }}" class="text-emerald-700 hover:underline">Detail</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">Belum ada koordinasi recall.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3"><x-molecules.pagination :items="$rows" /></div>
        </section>
    @endif
</x-templates.console>
