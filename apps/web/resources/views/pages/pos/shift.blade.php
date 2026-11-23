<x-templates.console>
    <div class="mb-4">
        <a href="/app/stores/{{ $shift['store_id'] }}/pos" class="text-sm text-slate-600 hover:text-slate-900">← Kembali ke POS</a>
    </div>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Shift {{ $shift['cashier_name'] }}</h1>
        <p class="text-sm text-slate-600 mt-1">{{ $shift['device_name'] }} · Status: <strong>{{ $shift['status'] }}</strong> · versi {{ $shift['version'] }}</p>
    </div>

    @php($showSystemCash = $shift['status'] === 'closed' || ($shift['can_manage'] ?? false))
    @php($noncash = $shift['noncash_summary'] ?? null)
    @php($categories = $shift['movement_categories'] ?? ['Tambah kembalian', 'Operasional darurat', 'Setoran kas', 'Lainnya'])
    @php($requests = $shift['cash_movement_requests'] ?? [])

    <section class="panel mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <span class="text-slate-500 block">Kas Awal (Modal Laci)</span>
                <strong class="text-slate-900 text-base">Rp {{ number_format((int) $shift['opening_cash'], 0, ',', '.') }}</strong>
                @if(($shift['expected_opening_cash'] ?? null) !== null)
                    <span class="block text-xs text-slate-500 mt-0.5">
                        Target dari shift sebelumnya: Rp {{ number_format((int) $shift['expected_opening_cash'], 0, ',', '.') }}
                        @if(($shift['opening_discrepancy'] ?? 0) !== 0)
                            · <strong class="text-rose-700">Selisih serah terima Rp {{ number_format((int) $shift['opening_discrepancy'], 0, ',', '.') }}</strong>
                        @endif
                    </span>
                @endif
                @if(!empty($shift['opening_note']))
                    <span class="block text-xs text-slate-600 mt-0.5">Catatan awal: {{ $shift['opening_note'] }}</span>
                @endif
            </div>

            @if($showSystemCash && $shift['expected_cash'] !== null)
                <div>
                    <span class="text-slate-500 block">Penjualan Tunai · Kas Masuk · Kas Keluar</span>
                    <strong class="text-slate-900">
                        Rp {{ number_format((int) ($shift['cash_sales'] ?? 0), 0, ',', '.') }} ·
                        +Rp {{ number_format((int) ($shift['cash_in'] ?? 0), 0, ',', '.') }} ·
                        -Rp {{ number_format((int) ($shift['cash_out'] ?? 0), 0, ',', '.') }}
                    </strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Kas Seharusnya</span>
                    <h2 class="text-lg font-bold text-slate-900">Kas seharusnya Rp {{ $shift['expected_cash'] }}</h2>
                </div>
            @else
                <div class="md:col-span-2 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900">
                    <strong>Mode Hitung Buta (Blind Close) Aktif</strong>
                    <p class="text-xs mt-1">Saldo sistem disembunyikan selama shift berlangsung agar penghitungan uang fisik akhir benar-benar objektif. Hasil selisih tampil otomatis setelah shift ditutup.</p>
                </div>
            @endif
        </div>

        @if($noncash)
            <div class="mt-4 pt-4 border-t border-slate-200 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="text-slate-500 block">QRIS Manual (Cocokkan ke Mutasi)</span>
                    <strong>{{ $noncash['qris_count'] ?? 0 }} struk · Rp {{ number_format((int) ($noncash['qris_amount'] ?? 0), 0, ',', '.') }}</strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Transfer Bank Manual</span>
                    <strong>{{ $noncash['transfer_count'] ?? 0 }} struk · Rp {{ number_format((int) ($noncash['transfer_amount'] ?? 0), 0, ',', '.') }}</strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Total Non-Tunai / Refund Non-Tunai</span>
                    <strong>Rp {{ number_format((int) ($noncash['total_noncash_sales'] ?? 0), 0, ',', '.') }} / -Rp {{ number_format((int) ($noncash['total_noncash_refunds'] ?? 0), 0, ',', '.') }}</strong>
                </div>
            </div>
        @endif

        @if($shift['status'] === 'closed')
            <div class="mt-4 pt-4 border-t border-slate-200 grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-slate-500 block">Kas Fisik Dihitung</span>
                    <strong class="text-slate-900">Fisik Rp {{ $shift['counted_cash'] }}</strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Sisa Modal Ditinggal di Laci</span>
                    <strong class="text-slate-900">Rp {{ number_format((int) ($shift['retained_cash'] ?? $shift['counted_cash'] ?? 0), 0, ',', '.') }}</strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Setoran ke Owner / Brankas</span>
                    <strong class="text-emerald-700">Rp {{ number_format((int) ($shift['deposit_cash'] ?? 0), 0, ',', '.') }}</strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Selisih Kas</span>
                    <strong class="{{ ((int) ($shift['difference_cash'] ?? 0)) === 0 ? 'text-emerald-700' : 'text-rose-700' }}">Selisih Rp {{ $shift['difference_cash'] }}</strong>
                </div>
            </div>
            @if(!empty($shift['closing_note']))
                <p class="mt-3 text-sm text-slate-700">Catatan penutupan: {{ $shift['closing_note'] }}</p>
            @endif
            @if(!empty($shift['force_closed_by']))
                <p class="mt-2 text-xs text-rose-700 font-medium">Ditutup paksa oleh pengelola (ID {{ $shift['force_closed_by'] }}): {{ $shift['force_close_reason'] }}</p>
            @endif
        @endif
    </section>

    @if($shift['status'] === 'open' && $shift['is_owner'])
        <section class="panel mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-2">Tutup Shift &amp; Hitung Fisik Laci</h2>
            <p class="text-sm text-slate-600 mb-4">Pastikan seluruh transaksi offline sudah disinkronkan dan izin offline sudah diakhiri sebelum menutup shift.</p>

            <div id="denom-calculator" class="p-3 rounded-lg bg-slate-50 border border-slate-200 mb-4">
                <p class="text-xs font-semibold text-slate-700 mb-2">Kalkulator Pecahan Uang (opsional, otomatis mengisi total kas fisik)</p>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                    @foreach([100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100] as $denom)
                        <label class="block">
                            <span class="text-slate-600">Rp {{ number_format($denom, 0, ',', '.') }}</span>
                            <input type="number" min="0" value="0" data-denom="{{ $denom }}" class="mt-0.5 w-full rounded border border-slate-300 px-2 py-1 text-sm">
                        </label>
                    @endforeach
                </div>
            </div>

            <form method="post" action="/app/pos/shifts/{{ $shift['id'] }}/close" class="space-y-4">
                @csrf
                <x-molecules.operation-key />
                <input type="hidden" name="version" value="{{ $shift['version'] }}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-molecules.field name="counted_cash" label="Kas fisik yang dihitung (Rp)" type="number" min="0" max="1000000000000" required />
                    <x-molecules.field name="retained_cash" label="Sisa modal ditinggal di laci untuk shift berikut (Rp, opsional)" type="number" min="0" max="1000000000000" />
                </div>
                <p class="text-sm text-slate-700">Perkiraan Setoran ke Owner/Brankas: <strong id="deposit-preview">Rp 0</strong></p>
                <x-molecules.field name="closing_note" label="Catatan (wajib bila ada selisih)" />
                <x-atoms.button>Tutup shift</x-atoms.button>
            </form>
        </section>
    @endif

    @if($shift['status'] === 'open' && ($shift['can_request_movement'] ?? false))
        <section class="panel mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-2">Ajukan Kas Masuk / Keluar (Memerlukan Persetujuan Pengelola)</h2>
            <form method="post" action="/app/pos/shifts/{{ $shift['id'] }}/cash-requests" class="space-y-3">
                @csrf
                <x-molecules.operation-key />
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="field">Jenis
                        <select name="kind">
                            <option value="out">Kas keluar</option>
                            <option value="in">Kas masuk</option>
                        </select>
                    </label>
                    <x-molecules.field name="amount" label="Nominal Rp" type="number" min="1" max="1000000000000" required />
                    <label class="field">Kategori
                        <select name="category" required>
                            @foreach($categories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <x-molecules.field name="reason" label="Alasan &amp; detail kebutuhan" required maxlength="2000" />
                    <x-molecules.field name="evidence_reference" label="Nomor bukti / nota fisik (opsional)" maxlength="160" />
                </div>
                <x-atoms.button>Kirim pengajuan kas</x-atoms.button>
            </form>
        </section>
    @endif

    @if($shift['status'] === 'open' && ($shift['can_manage'] ?? false))
        <section class="panel mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-2">Catat Kas Langsung (Wewenang Pengelola)</h2>
            <form method="post" action="/app/pos/shifts/{{ $shift['id'] }}/cash-movements" class="space-y-3">
                @csrf
                <x-molecules.operation-key />
                <input type="hidden" name="version" value="{{ $shift['version'] }}">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="field">Jenis
                        <select name="kind">
                            <option value="in">Kas masuk</option>
                            <option value="out">Kas keluar</option>
                        </select>
                    </label>
                    <x-molecules.field name="amount" label="Nominal Rp" type="number" min="1" max="1000000000000" required />
                    <x-molecules.field name="category" label="Kategori" required maxlength="80" />
                </div>
                <x-molecules.field name="reason" label="Alasan dan bukti fisik" required maxlength="2000" />
                <x-atoms.button>Catat kas sebagai pengelola</x-atoms.button>
            </form>
        </section>

        @if(!($shift['is_owner'] ?? false))
            <section class="panel mb-6 border-l-4 border-rose-500 bg-rose-50/50">
                <h2 class="text-lg font-semibold text-rose-900 mb-2">Tutup Paksa Shift (Pengelola)</h2>
                <p class="text-sm text-rose-800 mb-3">Gunakan hanya jika kasir berhalangan atau lupa menutup shift sehingga pergantian shift berikutnya terblokir.</p>
                <form method="post" action="/app/pos/shifts/{{ $shift['id'] }}/force-close" class="space-y-3">
                    @csrf
                    <x-molecules.operation-key />
                    <input type="hidden" name="version" value="{{ $shift['version'] }}">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <x-molecules.field name="counted_cash" label="Kas fisik yang dihitung pengelola (Rp)" type="number" min="0" max="1000000000000" required />
                        <x-molecules.field name="retained_cash" label="Sisa modal di laci (Rp, opsional)" type="number" min="0" max="1000000000000" />
                    </div>
                    <x-molecules.field name="reason" label="Alasan tutup paksa (wajib)" required minlength="3" maxlength="2000" />
                    <x-atoms.button>Tutup paksa shift</x-atoms.button>
                </form>
            </section>
        @endif
    @endif

    @if(count($requests))
        <section class="panel mb-6">
            <h2 class="text-base font-semibold text-slate-900 mb-3">Daftar Pengajuan Kas Masuk / Keluar</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Jenis &amp; Nominal</th>
                            <th class="py-2 pr-4">Kategori &amp; Alasan</th>
                            <th class="py-2 pr-4">Bukti</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($requests as $req)
                            <tr class="border-b border-slate-100">
                                <td class="py-2 pr-4 font-medium">{{ $req['kind'] === 'in' ? 'Masuk' : 'Keluar' }} · Rp {{ number_format((int) $req['amount'], 0, ',', '.') }}</td>
                                <td class="py-2 pr-4">{{ $req['category'] }} · {{ $req['reason'] }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $req['evidence_reference'] ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $req['status'] }} @if(!empty($req['decision_note']))<span class="block text-xs text-slate-500">{{ $req['decision_note'] }}</span>@endif</td>
                                <td class="py-2">
                                    @if($req['status'] === 'pending')
                                        <div class="flex flex-wrap gap-2">
                                            @if(($shift['can_manage'] ?? false) && (int) $req['requested_by'] !== (int) session('api_user.id', 0))
                                                <form method="post" action="/app/pos/cash-requests/{{ $req['id'] }}/approve" class="inline-flex gap-1">
                                                    @csrf
                                                    <x-molecules.operation-key />
                                                    <input type="hidden" name="shift_id" value="{{ $shift['id'] }}">
                                                    <input type="hidden" name="version" value="{{ $req['version'] }}">
                                                    <input type="text" name="decision_note" placeholder="Catatan" class="border border-slate-300 rounded px-2 py-1 text-xs">
                                                    <button type="submit" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs">Setujui</button>
                                                </form>
                                                <form method="post" action="/app/pos/cash-requests/{{ $req['id'] }}/reject" class="inline-flex gap-1">
                                                    @csrf
                                                    <x-molecules.operation-key />
                                                    <input type="hidden" name="shift_id" value="{{ $shift['id'] }}">
                                                    <input type="hidden" name="version" value="{{ $req['version'] }}">
                                                    <input type="text" name="decision_note" placeholder="Alasan tolak" required class="border border-slate-300 rounded px-2 py-1 text-xs">
                                                    <button type="submit" class="px-2 py-1 rounded bg-rose-600 text-white text-xs">Tolak</button>
                                                </form>
                                            @elseif($shift['is_owner'] ?? false)
                                                <form method="post" action="/app/pos/cash-requests/{{ $req['id'] }}/cancel" class="inline">
                                                    @csrf
                                                    <x-molecules.operation-key />
                                                    <input type="hidden" name="shift_id" value="{{ $shift['id'] }}">
                                                    <input type="hidden" name="version" value="{{ $req['version'] }}">
                                                    <button type="submit" class="px-2 py-1 rounded border border-slate-300 text-xs">Batalkan</button>
                                                </form>
                                            @endif
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="panel">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Riwayat kas di luar penjualan</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Jenis</th>
                        <th class="py-2 pr-4">Nominal</th>
                        <th class="py-2 pr-4">Kategori</th>
                        <th class="py-2 pr-4">Alasan &amp; Bukti</th>
                        <th class="py-2">Aktor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($shift['movements'] as $m)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pr-4 font-medium">{{ $m['kind'] }}</td>
                            <td class="py-2 pr-4">Rp {{ $m['amount'] }}</td>
                            <td class="py-2 pr-4">{{ $m['category'] }}</td>
                            <td class="py-2 pr-4">{{ $m['reason'] }}</td>
                            <td class="py-2">aktor {{ $m['actor_id'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-slate-500">Belum ada pergerakan kas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script src="/pos-shift-count.js" defer></script>
</x-templates.console>
