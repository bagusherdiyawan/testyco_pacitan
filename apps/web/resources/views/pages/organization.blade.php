<x-templates.console :organizationInfo="$organizationInfo">
    @php
        $isCenter = ($organizationInfo['type'] ?? null) === 'center';
        $roles = $isCenter
            ? ['admin' => 'Admin pusat', 'warehouse' => 'Gudang', 'store_manager' => 'Kepala toko', 'cashier' => 'Kasir']
            : ['partner_lead' => 'Pimpinan mitra', 'store_manager' => 'Kepala toko', 'cashier' => 'Kasir'];
        $roleLabels = ['owner' => 'Pemilik', ...$roles];
    @endphp
    <h1>{{ $organizationInfo['name'] }}</h1>
    <p><x-atoms.badge>{{ $isCenter ? 'Organisasi Pusat' : 'Organisasi Mitra' }}</x-atoms.badge> Semua menu pengelolaan organisasi tersedia di sidebar sebelah kiri.</p>

    <div class="section-heading">
        <h2>Daftar Toko &amp; Booth</h2>
    </div>
    <details class="panel">
        <summary>+ Tambah toko baru</summary>
        <form method="post" action="/app/organizations/{{ $organization }}/stores">
            @csrf
            <x-molecules.field name="name" label="Nama toko" required />
            <x-molecules.field name="region" label="Wilayah" />
            <x-molecules.field name="address" label="Alamat" />
            <x-atoms.button>Tambah toko</x-atoms.button>
        </form>
    </details>
    <section class="panel">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Nama Toko</th>
                        <th class="py-2 pr-4">Wilayah &amp; Alamat</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stores as $store)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $store['name'] }}</td>
                            <td class="py-2.5 pr-4 text-slate-600">{{ $store['address'] }}@if($store['region']) · {{ $store['region'] }}@endif</td>
                            <td class="py-2.5 pr-4"><x-atoms.badge>{{ $store['active'] ? 'Aktif' : 'Nonaktif' }}</x-atoms.badge></td>
                            <td class="py-2.5">
                                <div class="pill-actions">
                                    <a class="button button--lite" href="/app/stores/{{ $store['id'] }}/operations">Buka Operasional</a>
                                    <form method="post" action="/app/stores/{{ $store['id'] }}/status">
                                        @csrf
                                        <input type="hidden" name="active" value="{{ $store['active'] ? '0' : '1' }}">
                                        <x-atoms.button>{{ $store['active'] ? 'Nonaktifkan toko' : 'Aktifkan toko' }}</x-atoms.button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-3 text-slate-500">Belum ada toko atau booth terdaftar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <x-molecules.pagination :items="$stores" />

    <div class="section-heading">
        <h2>Pengguna &amp; Penugasan</h2>
    </div>
    <details class="panel">
        <summary>+ Tambah pengguna baru</summary>
        <form method="post" action="/app/organizations/{{ $organization }}/users">
            @csrf
            <x-molecules.field name="name" label="Nama pengguna" required />
            <x-molecules.field name="email" label="Email" type="email" required />
            <x-molecules.field name="password" label="Kata sandi awal (12+ karakter, besar/kecil dan angka)" type="password" minlength="12" autocomplete="new-password" required />
            <label class="field">Peran
                <select name="role">
                    @foreach($roles as $key => $label)
                        <option value="{{ $key }}" @selected($key === 'cashier')>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <fieldset>
                <legend>Penugasan toko</legend>
                @foreach($stores as $store)
                    <label><input type="checkbox" name="store_ids[]" value="{{ $store['id'] }}">{{ $store['name'] }}</label>
                @endforeach
            </fieldset>
            <x-atoms.button>Tambah pengguna</x-atoms.button>
        </form>
    </details>
    <section class="panel">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Nama Pengguna</th>
                        <th class="py-2 pr-4">Email</th>
                        <th class="py-2 pr-4">Peran</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Pengaturan &amp; Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr class="border-b border-slate-100 align-top">
                            <td class="py-2.5 pr-4 font-semibold text-slate-900">{{ $user['name'] }}</td>
                            <td class="py-2.5 pr-4 text-slate-600">{{ $user['email'] }}</td>
                            <td class="py-2.5 pr-4"><x-atoms.badge>{{ $roleLabels[$user['role']] ?? $user['role'] }}</x-atoms.badge></td>
                            <td class="py-2.5 pr-4">{{ $user['active'] ? 'Aktif' : 'Nonaktif' }}</td>
                            <td class="py-2.5">
                                @if($user['role'] !== 'owner' && $user['id'] !== session('api_user.id'))
                                    <details>
                                        <summary>Ubah peran &amp; penugasan toko</summary>
                                        <form method="post" action="/app/organizations/{{ $organization }}/users/{{ $user['id'] }}/membership">
                                            @csrf
                                            <label class="field">Peran
                                                <select name="role">
                                                    @foreach($roles as $key => $label)
                                                        <option value="{{ $key }}" @selected($key === $user['role'])>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <fieldset>
                                                <legend>Ganti penugasan toko (pilih seluruh penugasan yang diinginkan)</legend>
                                                @foreach(array_diff($user['store_ids'] ?? [], $stores->pluck('id')->all()) as $retained)
                                                    <input type="hidden" name="store_ids[]" value="{{ $retained }}">
                                                @endforeach
                                                @foreach($stores as $store)
                                                    <label><input type="checkbox" name="store_ids[]" value="{{ $store['id'] }}" @checked(in_array($store['id'], $user['store_ids'] ?? [], true))>{{ $store['name'] }}</label>
                                                @endforeach
                                            </fieldset>
                                            <x-atoms.button>Simpan penugasan</x-atoms.button>
                                        </form>
                                        @if($user['active'])
                                            <form method="post" action="/app/organizations/{{ $organization }}/users/{{ $user['id'] }}/deactivate">
                                                @csrf
                                                <x-atoms.button>Nonaktifkan akun</x-atoms.button>
                                            </form>
                                        @else
                                            <p><a href="/app/organizations/{{ $organization }}/users/{{ $user['id'] }}/reactivation">Tinjau reaktivasi akun</a></p>
                                        @endif
                                    </details>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-slate-500">Belum ada pengguna terdaftar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <x-molecules.pagination :items="$users" />

    <div class="section-heading">
        <h2>Audit Terbaru</h2>
    </div>
    <section class="panel">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Waktu</th>
                        <th class="py-2 pr-4">Aksi</th>
                        <th class="py-2">Aktor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($audit as $entry)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pr-4 font-mono text-xs text-slate-600">{{ $entry['created_at'] }}</td>
                            <td class="py-2 pr-4 font-medium">{{ $entry['action'] }}</td>
                            <td class="py-2">aktor {{ $entry['actor_id'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-slate-500">Belum ada catatan audit terbaru.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <x-molecules.pagination :items="$audit" />
</x-templates.console>
