<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Pengolahan &amp; Opname Stok</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Pengolahan dan opname · {{ $store['name'] }}</h1>
            <p class="text-sm text-slate-600 mt-1">Jumlah dalam biji kecuali pembukaan kemasan. Ikuti SOP penyimpanan dan pengolahan yang disepakati.</p>
        </div>
        <a href="/app/stores/{{ $store['id'] }}/operations" class="button button--lite">← Operasional Toko</a>
    </div>

    @if(!$store['active'])
        <aside class="p-4 rounded-xl border border-amber-300 bg-amber-50 text-amber-900 mb-6">
            Toko nonaktif. Riwayat tetap tersedia; tindakan baru ditolak.
        </aside>
    @endif

    @if($store['active'] && count($batches))
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <details class="panel" open>
                <summary class="font-semibold text-slate-900 cursor-pointer">Buka kemasan beku</summary>
                <form method="post" action="/app/stores/{{ $store['id'] }}/stock-control/processes" class="mt-4 space-y-3">
                    @csrf
                    <x-molecules.operation-key />
                    <input type="hidden" name="kind" value="open">
                    <x-molecules.stock-batch-selector :batches="array_values(array_filter($batches, fn($b) => $b['condition'] === 'frozen'))" />
                    <x-molecules.field name="quantity_packs" label="Kemasan yang benar-benar dibuka" type="number" min="1" max="10000" required />
                    <x-molecules.field name="reason" label="Catatan pengolahan" required maxlength="2000" />
                    <x-atoms.button>Catat pembukaan kemasan</x-atoms.button>
                </form>
            </details>

            <details class="panel" open>
                <summary class="font-semibold text-slate-900 cursor-pointer">Kukus biji beku terbuka</summary>
                <form method="post" action="/app/stores/{{ $store['id'] }}/stock-control/processes" class="mt-4 space-y-3">
                    @csrf
                    <x-molecules.operation-key />
                    <input type="hidden" name="kind" value="cook">
                    <x-molecules.stock-batch-selector :batches="array_values(array_filter($batches, fn($b) => $b['condition'] === 'frozen'))" />
                    <x-molecules.field name="input_pieces" label="Biji beku terbuka yang dikukus" type="number" min="1" max="100000000" required />
                    <x-molecules.field name="output_pieces" label="Hasil aktual siap jual (biji)" type="number" min="0" max="100000000" required />
                    <p class="text-xs text-slate-500">Selisih input dan hasil dicatat sebagai susut proses. Jangan memasukkan hasil perkiraan.</p>
                    <x-molecules.field name="reason" label="Catatan hasil aktual dan penyebab susut bila ada" required maxlength="2000" />
                    <x-atoms.button>Catat hasil pengukusan</x-atoms.button>
                </form>
            </details>
        </div>

        @if($canPropose ?? true)
            <details class="panel mb-6">
                <summary class="font-semibold text-slate-900 cursor-pointer">+ Ajukan opname atau susut terpisah</summary>
                <div class="mt-4 pt-4 border-t border-slate-200">
                    <p class="text-xs text-slate-600 mb-3">Saldo tidak langsung berubah. Tinjauan harus oleh pimpinan berwenang yang berbeda dari pengusul.</p>
                    <form method="post" action="/app/stores/{{ $store['id'] }}/stock-control/counts" class="space-y-3">
                        @csrf
                        <x-molecules.operation-key />
                        <x-molecules.stock-batch-selector :batches="$batches" />
                        <div class="form-grid">
                            <label class="field">Tujuan
                                <select name="purpose">
                                    <option value="stocktake">Stok opname</option>
                                    <option value="waste">Susut terpisah (hanya pengurangan)</option>
                                </select>
                            </label>
                            <label class="field">Bucket yang dihitung
                                <select name="bucket">
                                    <option value="usable">Utuh: biji harus kelipatan isi kemasan</option>
                                    <option value="opened">Beku terbuka</option>
                                    <option value="ready">Siap jual</option>
                                    <option value="quarantined">Karantina</option>
                                </select>
                            </label>
                            <x-molecules.field name="counted_pieces" label="Jumlah fisik tersisa (biji), bukan jumlah selisih" type="number" min="0" max="1000000000" required />
                            <x-molecules.field name="reason" label="Alasan dan bukti pemeriksaan" required maxlength="2000" />
                        </div>
                        <x-atoms.button>Ajukan untuk ditinjau</x-atoms.button>
                    </form>
                </div>
            </details>
        @endif
    @endif

    <section class="panel mb-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Saldo Batch</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">SKU &amp; Batch</th>
                        <th class="py-2 pr-4">Kedaluwarsa Kemasan</th>
                        <th class="py-2 pr-4">Isi Kemasan</th>
                        <th class="py-2">Rincian Saldo (biji)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr class="border-b border-slate-100 align-top">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $batch['sku'] }} · {{ $batch['batch_code'] }}</td>
                            <td class="py-2.5 pr-4 font-mono text-xs">{{ $batch['expires_on'] }}</td>
                            <td class="py-2.5 pr-4">{{ $batch['pack_size'] }} biji</td>
                            <td class="py-2.5"><x-molecules.stock-balances :batch="$batch" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-slate-500">Belum ada batch. Catat penerimaan fisik terlebih dahulu.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel mb-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Pengajuan Opname / Susut</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">SKU &amp; Batch</th>
                        <th class="py-2 pr-4">Perubahan &amp; Selisih</th>
                        <th class="py-2 pr-4">Pengusul &amp; Catatan</th>
                        <th class="py-2">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($counts as $count)
                        <tr class="border-b border-slate-100 align-top">
                            <td class="py-2.5 pr-4"><x-atoms.badge>{{ $count['status'] }}</x-atoms.badge></td>
                            <td class="py-2.5 pr-4">
                                <div class="font-semibold">{{ $count['batch']['sku'] }} · {{ $count['batch']['batch_code'] }}</div>
                                <div class="text-xs text-slate-500">{{ $count['purpose'] }} · snapshot {{ $count['snapshot_revision'] }}</div>
                            </td>
                            <td class="py-2.5 pr-4">
                                <div>Bucket {{ $count['bucket'] }}: {{ $count['expected_pieces'] }} → {{ $count['counted_pieces'] }}</div>
                                <div class="text-xs text-slate-600">Selisih {{ $count['difference_pieces'] }} biji</div>
                                @if($count['is_stale'])
                                    <div class="text-xs text-rose-700 font-medium mt-1">Snapshot kedaluwarsa karena ada pergerakan stok.</div>
                                @endif
                            </td>
                            <td class="py-2.5 pr-4 text-xs text-slate-700">
                                <div>Pengusul {{ $count['created_by'] }} · {{ $count['reason'] }}</div>
                                @if($count['review_note'])
                                    <div class="text-slate-500 mt-1">Peninjau {{ $count['reviewed_by'] }} · {{ $count['review_note'] }}</div>
                                @endif
                            </td>
                            <td class="py-2.5">
                                @if($count['can_review'])
                                    <div class="space-y-2 min-w-[200px]">
                                        @foreach(['approve' => 'Setujui penyesuaian', 'reject' => 'Tolak pengajuan'] as $action => $label)
                                            <form method="post" action="/app/stores/{{ $store['id'] }}/stock-control/counts/{{ $count['id'] }}/{{ $action }}" class="space-y-1">
                                                @csrf
                                                <x-molecules.operation-key />
                                                <input type="hidden" name="version" value="{{ $count['version'] }}">
                                                <x-molecules.field name="review_note" label="Catatan peninjauan" required maxlength="2000" />
                                                <x-atoms.button :disabled="$action === 'approve' && $count['is_stale']">{{ $label }}</x-atoms.button>
                                            </form>
                                        @endforeach
                                    </div>
                                @elseif($count['can_cancel'])
                                    <form method="post" action="/app/stores/{{ $store['id'] }}/stock-control/counts/{{ $count['id'] }}/cancel" class="space-y-1 min-w-[200px]">
                                        @csrf
                                        <x-molecules.operation-key />
                                        <input type="hidden" name="version" value="{{ $count['version'] }}">
                                        <x-molecules.field name="review_note" label="Alasan pembatalan untuk hitung ulang" required maxlength="2000" />
                                        <x-atoms.button>Batalkan pengajuan sendiri</x-atoms.button>
                                    </form>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-500">Belum ada pengajuan penyesuaian.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3"><x-molecules.pagination :items="$counts" /></div>
    </section>

    <section class="panel">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Riwayat Pembukaan dan Pengukusan</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Waktu WIB</th>
                        <th class="py-2 pr-4">Jenis</th>
                        <th class="py-2 pr-4">Batch</th>
                        <th class="py-2 pr-4">Input / Hasil / Susut</th>
                        <th class="py-2">Catatan &amp; Petugas</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($processes as $process)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pr-4 font-mono text-xs">{{ \Carbon\Carbon::parse($process['created_at'])->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</td>
                            <td class="py-2 pr-4 font-medium">{{ $process['kind'] }}</td>
                            <td class="py-2 pr-4">{{ $process['batch']['sku'] ?? $process['inventory_batch_id'] ?? 'Batch' }} {{ $process['batch']['batch_code'] ?? '' }}</td>
                            <td class="py-2 pr-4">Input {{ $process['input_pieces'] }}, hasil {{ $process['output_pieces'] }}, susut {{ $process['waste_pieces'] }} biji</td>
                            <td class="py-2 text-slate-600">{{ $process['reason'] }} · Petugas {{ $process['actor_id'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-500">Belum ada pengolahan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3"><x-molecules.pagination :items="$processes" /></div>
    </section>

    <script src="/stock-control.js" defer></script>
</x-templates.console>
