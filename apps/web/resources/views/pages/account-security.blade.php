<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Keamanan Akun</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Keamanan akun dan perangkat</h1>
            <p class="text-sm text-slate-600 mt-1">MFA {{ $c['enabled'] ? 'aktif' : 'belum aktif' }}. Mengubah MFA mencabut seluruh sesi. Kode recovery hanya dapat digunakan sekali; simpan di tempat aman terpisah.</p>
        </div>
        <a href="/app/account/password" class="button button--lite">Ganti kata sandi</a>
    </div>

    <section class="panel mb-6 space-y-4">
        @if($enrollment)
            <h2 class="text-lg font-semibold text-slate-900">Tambahkan ke aplikasi authenticator</h2>
            <p class="text-sm text-slate-700">Issuer Testyco · SHA1 · 6 digit · 30 detik. Secret: <strong class="font-mono">{{ $enrollment['secret'] }}</strong></p>
            <div>
                <p class="text-sm font-medium text-slate-700 mb-2">Kode recovery:</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    @foreach($enrollment['recovery_codes'] as $code)
                        <div class="px-3 py-1.5 rounded bg-slate-100 font-mono text-xs text-slate-800">{{ $code }}</div>
                    @endforeach
                </div>
            </div>
            <form method="post" action="/app/account/security/mfa/enable" class="space-y-3 pt-2">
                @csrf
                <x-molecules.field name="code" label="Kode authenticator saat ini" :remember="false" autocomplete="one-time-code" required />
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="codes_saved" value="1" required>
                    <span>Saya telah menyimpan kode recovery secara aman</span>
                </label>
                <x-atoms.button>Aktifkan MFA dan cabut sesi</x-atoms.button>
            </form>
        @elseif(!$c['enabled'])
            <h2 class="text-lg font-semibold text-slate-900">Aktifkan Verifikasi Dua Langkah (MFA)</h2>
            <form method="post" action="/app/account/security/mfa/enroll" class="space-y-3 max-w-md">
                @csrf
                <x-molecules.field name="current_password" label="Kata sandi saat ini" type="password" :remember="false" required />
                <x-atoms.button>Siapkan authenticator</x-atoms.button>
            </form>
        @else
            <h2 class="text-lg font-semibold text-slate-900">Nonaktifkan MFA</h2>
            <form method="post" action="/app/account/security/mfa/disable" class="space-y-3 max-w-md">
                @csrf
                <x-molecules.field name="current_password" label="Kata sandi saat ini" type="password" :remember="false" required />
                <x-molecules.field name="code" label="Kode authenticator/recovery" :remember="false" autocomplete="one-time-code" required />
                <x-atoms.button>Nonaktifkan MFA dan cabut sesi</x-atoms.button>
            </form>
        @endif
    </section>

    <section class="panel">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Sesi Perangkat</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Perangkat</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Terakhir Dipakai</th>
                        <th class="py-2 pr-4">Berakhir</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sessions as $s)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-medium">{{ $s['name'] }}</td>
                            <td class="py-2.5 pr-4"><x-atoms.badge>{{ $s['is_current'] ? 'Sesi ini' : 'Sesi lain' }}</x-atoms.badge></td>
                            <td class="py-2.5 pr-4 font-mono text-xs text-slate-600">{{ $s['last_used_at'] ?? 'Belum' }}</td>
                            <td class="py-2.5 pr-4 font-mono text-xs text-slate-600">{{ $s['expires_at'] }}</td>
                            <td class="py-2.5">
                                <form method="post" action="/app/account/security/sessions/{{ $s['id'] }}/revoke" class="inline">
                                    @csrf
                                    <x-atoms.button class="button--lite">Cabut sesi</x-atoms.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-slate-500">Tidak ada sesi aktif.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-templates.console>
