<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Keamanan Pangan &amp; Recall</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">SOP stok, masa simpan dan recall</h1>
            <p class="text-sm text-slate-600 mt-1">Pimpinan organisasi menetapkan SOP sendiri berdasarkan dokumen tervalidasi. Sistem tidak menetapkan umur simpan makanan secara otomatis.</p>
        </div>
        <a href="/app/organizations/{{ $organization }}" class="button button--lite">Ringkasan Organisasi</a>
    </div>

    @if($pending)
        <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6">
            <h2 class="text-base font-semibold text-amber-900">Hasil Belum Pasti</h2>
            @foreach($pending['body'] as $key => $v)
                @if(is_scalar($v) && $key !== 'operation_id')
                    <p class="text-sm text-amber-800">{{ $key }}: {{ $v }}</p>
                @endif
            @endforeach
            <form method="post" action="/app/organizations/{{ $organization }}/food-safety/retry" class="mt-3">
                @csrf
                <x-atoms.button>Ulangi request yang sama</x-atoms.button>
            </form>
        </section>
    @else
        <section class="panel mb-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Persetujuan SOP Organisasi</h2>
                    <p class="text-sm text-slate-600">SOP saat ini: <strong>{{ ($c['policy']['enabled'] ?? false) ? 'Aktif' : 'Belum aktif' }}</strong> · {{ $c['policy']['reference'] ?? 'Belum ada referensi' }}</p>
                </div>
            </div>
            <details class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                <summary class="font-semibold text-sm text-slate-900 cursor-pointer">Atur / Perbarui SOP Masa Simpan &amp; FEFO</summary>
                <form class="mt-4 space-y-3" method="post" action="/app/organizations/{{ $organization }}/food-safety">
                    @csrf
                    <x-molecules.operation-key :remember="false" />
                    <input type="hidden" name="action" value="policy">
                    <input type="hidden" name="version" value="{{ $c['policy']['version'] ?? 0 }}">
                    <div class="form-grid">
                        @foreach(['enabled' => 'Aktifkan SOP ini', 'block_expired' => 'Blokir kemasan melewati tanggal kedaluwarsa WIB'] as $name => $label)
                            <label>{{ $label }}
                                <select name="{{ $name }}" required>
                                    <option value="">Pilih setelah review</option>
                                    <option value="1">Ya</option>
                                    <option value="0">Tidak</option>
                                </select>
                            </label>
                        @endforeach
                        <label>FEFO
                            <select name="fefo" required>
                                <option value="">Pilih aturan organisasi</option>
                                <option value="off">Manual tanpa aturan FEFO</option>
                                <option value="suggest">Saran batch berdasarkan masa simpan</option>
                                <option value="enforce">Wajib memakai stok layak yang paling awal berakhir</option>
                            </select>
                        </label>
                        <x-molecules.field name="ready_life_minutes" label="Masa simpan siap jual dalam menit menurut SOP (kosong jika belum ditetapkan)" type="number" min="1" :remember="false" />
                        <x-molecules.field name="reference" label="Dokumen SOP dan dasar persetujuan" :remember="false" required />
                    </div>
                    <p class="text-xs text-slate-500">Stok siap lama tanpa waktu pengolahan akan ditahan saat masa simpan diaktifkan.</p>
                    <x-atoms.button>Setujui dan simpan SOP</x-atoms.button>
                </form>
            </details>
        </section>

        <details class="panel mb-6">
            <summary class="font-semibold text-rose-800 cursor-pointer">+ Terbitkan recall lot milik organisasi</summary>
            <form class="mt-4 pt-4 border-t border-slate-200 space-y-3" method="post" action="/app/organizations/{{ $organization }}/food-safety">
                @csrf
                <x-molecules.operation-key :remember="false" />
                <input type="hidden" name="action" value="recall">
                <div class="form-grid">
                    <label>Produk
                        <select name="product_id" required>
                            <option value="">Pilih</option>
                            @foreach($c['products'] as $p)
                                <option value="{{ $p['id'] }}">{{ $p['sku'] }} — {{ $p['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-molecules.field name="batch_code" label="Kode lot/batch sesuai stok nyata" :remember="false" required />
                    <x-molecules.field name="reason" label="Alasan recall dan tindakan yang diperlukan" :remember="false" required />
                    <x-molecules.field name="reference" label="Referensi bukti/keputusan" :remember="false" required />
                </div>
                <p class="text-xs text-slate-500">Recall memblokir penjualan, pengiriman dan pengolahan lot; tidak mengubah saldo/menyusutkan barang otomatis.</p>
                <x-atoms.button>Konfirmasi recall dan blokir lot</x-atoms.button>
            </form>
        </details>

        <section class="panel mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-1">Waktu Pengolahan Stok Siap yang Perlu Ditinjau</h2>
            <p class="text-xs text-slate-600 mb-3">Isi waktu pengolahan nyata menurut bukti dalam WIB. Peninjauan tidak dapat memperpanjang batas yang sudah ada.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">SKU &amp; Batch</th>
                            <th class="py-2 pr-4">Stok Siap</th>
                            <th class="py-2 pr-4">Batas Saat Ini</th>
                            <th class="py-2">Konfirmasi Waktu Pengolahan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($readyRows as $b)
                            <tr class="border-b border-slate-100 align-top">
                                <td class="py-2.5 pr-4 font-semibold">{{ $b['sku'] }} · {{ $b['batch_code'] }}</td>
                                <td class="py-2.5 pr-4">{{ $b['ready_pieces'] }} biji siap</td>
                                <td class="py-2.5 pr-4 text-xs">{{ $b['ready_expires_at'] ?? 'Belum diketahui: ditahan jika SOP mewajibkan' }}</td>
                                <td class="py-2.5">
                                    <form method="post" action="/app/organizations/{{ $organization }}/food-safety" class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <x-molecules.operation-key :remember="false" />
                                        <input type="hidden" name="action" value="ready">
                                        <input type="hidden" name="batch_id" value="{{ $b['id'] }}">
                                        <input type="hidden" name="expected_revision" value="{{ $b['stock_revision'] }}">
                                        <x-molecules.field name="prepared_at" label="Waktu pengolahan nyata WIB" type="datetime-local" :remember="false" required />
                                        <x-molecules.field name="reference" label="Referensi pemeriksaan/bukti pengolahan" :remember="false" required />
                                        <x-atoms.button>Konfirmasi waktu pengolahan menurut bukti</x-atoms.button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">Tidak ada stok siap yang menunggu peninjauan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3"><x-molecules.pagination :items="$readyRows" /></div>
        </section>

        <section class="panel">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Riwayat Recall Lot</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Batch / Lot</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Alasan &amp; Referensi</th>
                            <th class="py-2">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            <tr class="border-b border-slate-100 align-top">
                                <td class="py-2.5 pr-4 font-mono font-semibold">{{ $r['batch_code'] }}</td>
                                <td class="py-2.5 pr-4"><x-atoms.badge>{{ $r['status'] }}</x-atoms.badge></td>
                                <td class="py-2.5 pr-4">{{ $r['reason'] }} · {{ $r['reference'] }}</td>
                                <td class="py-2.5">
                                    @if($r['status'] === 'active')
                                        <form method="post" action="/app/organizations/{{ $organization }}/food-safety" class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <x-molecules.operation-key :remember="false" />
                                            <input type="hidden" name="action" value="withdraw">
                                            <input type="hidden" name="subject" value="{{ $r['id'] }}">
                                            <input type="hidden" name="version" value="{{ $r['version'] }}">
                                            <x-molecules.field name="reason" label="Dasar keputusan mencabut recall (tidak menyatakan barang aman otomatis)" :remember="false" required />
                                            <x-atoms.button>Konfirmasi pencabutan recall</x-atoms.button>
                                        </form>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">Belum ada catatan recall.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3"><x-molecules.pagination :items="$rows" /></div>
        </section>
    @endif
</x-templates.console>
