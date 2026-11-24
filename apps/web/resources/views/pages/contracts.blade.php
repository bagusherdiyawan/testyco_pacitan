<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Kemitraan &amp; Legal</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Kontrak dan persetujuan kemitraan</h1>
        </div>
        <a href="/app/organizations/{{ $organization }}" class="button button--lite">Ringkasan Organisasi</a>
    </div>

    @if($pending)
        <section class="panel border-l-4 border-amber-500 bg-amber-50/70 mb-6">
            <h2 class="text-base font-semibold text-amber-900">Hasil Belum Pasti; Request Semula Ditahan</h2>
            <p class="text-sm text-amber-800 mt-1">{{ $pending['body']['title'] ?? 'Keputusan kontrak' }} · {{ $pending['expected_status'] }}</p>
            <p class="text-sm text-amber-800 mt-1">{{ $pending['body']['terms'] ?? $pending['body']['reason'] ?? '' }}</p>
            <form method="post" action="/app/organizations/{{ $organization }}/contracts/retry" class="mt-3">
                @csrf
                <x-atoms.button>Ulangi request yang sama</x-atoms.button>
            </form>
        </section>
    @else
        @if($c['can_create'])
            <details class="panel mb-6">
                <summary class="font-semibold text-slate-900 cursor-pointer">+ Susun kontrak baru</summary>
                <form class="mt-4 pt-4 border-t border-slate-200 space-y-3" method="post" enctype="multipart/form-data" action="/app/organizations/{{ $organization }}/contracts">
                    @csrf
                    <x-molecules.operation-key :remember="false" />
                    <div class="form-grid">
                        <label>Mitra
                            <select name="partner_id" required>
                                @foreach($partners as $p)
                                    @if($p['type'] === 'partner')
                                        <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                        <x-molecules.field name="title" label="Judul kontrak" maxlength="160" :remember="false" required />
                        <label>Skema
                            <select name="mode">
                                <option value="buyout">Putus beli</option>
                                <option value="consignment">Konsinyasi — penyelesaian khusus</option>
                            </select>
                        </label>
                        <x-molecules.field name="starts_on" label="Mulai berlaku" type="date" :remember="false" required />
                        <x-molecules.field name="ends_on" label="Akhir berlaku" type="date" :remember="false" required />
                    </div>
                    @foreach(['terms' => 'Ketentuan kerja sama', 'brand_terms' => 'Penggunaan merek', 'data_scope' => 'Cakupan data yang dibagikan', 'facilities' => 'Fasilitas, tanggung jawab dan kewajiban'] as $key => $label)
                        <label class="block text-sm font-medium text-slate-700">{{ $label }}
                            <textarea name="{{ $key }}" maxlength="{{ $key === 'terms' ? 30000 : 10000 }}" required class="mt-1 w-full rounded border border-slate-300 p-2 text-sm"></textarea>
                        </label>
                    @endforeach
                    <label class="block text-sm text-slate-700">Lampiran PDF privat (opsional, maksimal 5 MiB)
                        <input type="file" name="attachment" accept="application/pdf" class="block mt-1">
                    </label>
                    <x-atoms.button>Simpan draft untuk ditinjau</x-atoms.button>
                </form>
            </details>
        @endif

        <div class="space-y-4">
            @forelse($contracts as $contract)
                <section class="panel space-y-3">
                    <x-organisms.partnership-contract :contract="$contract" />
                    @if($contract['has_attachment'] ?? false)
                        <p class="text-sm"><a href="/app/organizations/{{ $organization }}/contracts/{{ $contract['id'] }}/attachment" class="text-emerald-700 hover:underline">Unduh lampiran kontrak privat</a></p>
                    @endif
                    @php($actions = $c['can_create'] ? ($contract['status'] === 'draft' ? ['publish' => 'Terbitkan untuk persetujuan mitra', 'withdraw' => 'Tarik draft'] : ($contract['status'] === 'published' ? ['withdraw' => 'Tarik penawaran'] : [])) : ($c['can_decide'] && $contract['status'] === 'published' ? ['accept' => 'Setujui kontrak', 'reject' => 'Tolak kontrak'] : []))
                    @if(count($actions))
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-slate-200">
                            @foreach($actions as $action => $label)
                                <form class="p-3 rounded-lg bg-slate-50 border border-slate-200 space-y-2" method="post" action="/app/organizations/{{ $organization }}/contracts/{{ $contract['id'] }}/{{ $action }}">
                                    @csrf
                                    <x-molecules.operation-key :remember="false" />
                                    <input type="hidden" name="version" value="{{ $contract['version'] }}">
                                    @if($action === 'accept')
                                        <label class="flex items-start gap-2 text-xs text-slate-700">
                                            <input type="checkbox" name="consent" value="1" required class="mt-0.5">
                                            <span>Saya telah membaca dan menyetujui seluruh ketentuan versi {{ $contract['version'] }} di atas.</span>
                                        </label>
                                        <x-molecules.field name="consent_name" label="Nama lengkap penanggung jawab yang menyetujui" maxlength="160" :remember="false" required />
                                    @endif
                                    <x-molecules.field name="reason" label="Catatan keputusan" maxlength="2000" :remember="false" :required="in_array($action, ['withdraw', 'reject'], true)" />
                                    <x-atoms.button>{{ $label }}</x-atoms.button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </section>
            @empty
                <section class="panel text-slate-500 text-sm">Belum ada kontrak yang dapat dibaca.</section>
            @endforelse
        </div>
        <div class="mt-4"><x-molecules.pagination :items="$contracts" /></div>
    @endif
</x-templates.console>
