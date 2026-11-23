<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Keamanan Pangan</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Tindak lanjut recall kiriman</h1>
        </div>
        <a href="/app/organizations/{{ $organization }}/recalls" class="button button--lite">← Daftar Recall</a>
    </div>

    @if($pending)
        <x-organisms.recall-pending :organization="$organization" :pending="$pending" />
    @else
        <section class="panel mb-6 space-y-3">
            <p class="text-sm font-semibold text-slate-900">{{ $c['campaign']['instructions'] }} · <x-atoms.badge>{{ $c['campaign']['status'] }}</x-atoms.badge></p>
            <p class="text-xs text-slate-600">Pemberitahuan memerlukan tindakan pimpinan penerima. Menutup koordinasi atau mencabut recall pemasok tidak otomatis melepas blok yang diputuskan penerima.</p>
            @if($c['is_source'])
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                    @foreach(['refresh' => 'Telusuri kiriman berikutnya', 'close' => 'Tutup koordinasi (blok penerima tetap)'] as $action => $label)
                        <form method="post" action="/app/organizations/{{ $organization }}/recalls/{{ $campaign }}" class="p-3 rounded-lg bg-slate-50 border border-slate-200 space-y-2">
                            @csrf
                            <x-molecules.operation-key :remember="false" />
                            <input type="hidden" name="action" value="{{ $action }}">
                            <input type="hidden" name="version" value="{{ $c['campaign']['version'] }}">
                            @if($action === 'close')
                                <x-molecules.field name="reason" label="Dasar penutupan koordinasi" :remember="false" required />
                            @endif
                            <x-atoms.button>{{ $label }}</x-atoms.button>
                        </form>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="panel">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Target Kiriman Terdampak</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Organisasi &amp; Toko</th>
                            <th class="py-2 pr-4">Batch</th>
                            <th class="py-2 pr-4">Status &amp; Bukti</th>
                            <th class="py-2">Keputusan Pimpinan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="border-b border-slate-100 align-top">
                                <td class="py-2.5 pr-4 font-semibold">{{ $row['organization_name'] }} · {{ $row['store_name'] }}</td>
                                <td class="py-2.5 pr-4 font-mono text-xs">{{ $row['batch_code'] }}</td>
                                <td class="py-2.5 pr-4">
                                    <x-atoms.badge>{{ $row['status'] }}</x-atoms.badge>
                                    <div class="text-xs text-slate-600 mt-1">{{ $row['reason'] }} · {{ $row['evidence_reference'] }}</div>
                                </td>
                                <td class="py-2.5">
                                    @if($row['organization_id'] === $organization && isset($row['stock_revision']))
                                        <form method="post" action="/app/organizations/{{ $organization }}/recalls/{{ $campaign }}" class="space-y-2 min-w-[240px]">
                                            @csrf
                                            <x-molecules.operation-key :remember="false" />
                                            <input type="hidden" name="target_id" value="{{ $row['id'] }}">
                                            <input type="hidden" name="version" value="{{ $row['version'] }}">
                                            <input type="hidden" name="expected_revision" value="{{ $row['stock_revision'] }}">
                                            <label class="field">Tindakan pimpinan organisasi
                                                <select name="action" required>
                                                    <option value="">Pilih setelah pemeriksaan</option>
                                                    <option value="hold">Konfirmasi penahanan penjualan/pengolahan/pengiriman</option>
                                                    <option value="release">Lepaskan penahanan ini berdasarkan bukti</option>
                                                </select>
                                            </label>
                                            <x-molecules.field name="observed_pieces" type="number" min="0" label="Jumlah biji yang benar-benar diperiksa" :remember="false" required />
                                            <x-molecules.field name="evidence_reference" label="Referensi pemeriksaan/disposisi" :remember="false" required />
                                            <x-molecules.field name="reason" label="Dasar keputusan dan tindakan fisik" :remember="false" required />
                                            <x-atoms.button>Catat keputusan pimpinan</x-atoms.button>
                                        </form>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">Belum ada target terdampak.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3"><x-molecules.pagination :items="$rows" /></div>
        </section>
    @endif
</x-templates.console>
