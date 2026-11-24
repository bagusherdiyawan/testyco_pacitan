<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Notifikasi &amp; Peringatan</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Inbox operasional</h1>
            <p class="text-sm text-slate-600 mt-1">{{ $c['unread_count'] }} belum dibaca. Pemberitahuan mengikuti hak akses saat ini.</p>
        </div>
        <a href="/app" class="button button--lite">← Workspace</a>
    </div>

    <section class="panel mb-6">
        <form method="post" action="/app/inbox/preferences" class="flex flex-wrap items-end gap-4">
            @csrf
            <input type="hidden" name="version" value="{{ $c['preferences']['version'] }}">
            <label class="field">Email notifikasi
                <select name="email_enabled" required>
                    <option value="0" @selected(!$c['preferences']['email_enabled'])>Tidak mengirim email</option>
                    <option value="1" @selected($c['preferences']['email_enabled'])>Saya mengizinkan email notifikasi ke email akun</option>
                </select>
            </label>
            <x-atoms.button>Simpan pilihan kanal</x-atoms.button>
        </form>
        <p class="text-xs text-slate-500 mt-2">{{ $c['smtp_configured'] ? 'Pengiriman melalui antrean SMTP.' : 'SMTP belum aktif; inbox tetap tersedia. Email belum dikirim.' }}</p>
    </section>

    <section class="panel">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-slate-500">
                        <th class="py-2 pr-4">Pemberitahuan</th>
                        <th class="py-2 pr-4">Waktu</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 font-semibold"><a href="{{ $entry['local_path'] }}" class="text-emerald-700 hover:underline">{{ $entry['title'] }}</a></td>
                            <td class="py-2.5 pr-4 font-mono text-xs text-slate-600">{{ $entry['created_at'] }}</td>
                            <td class="py-2.5 pr-4"><x-atoms.badge>{{ $entry['read_at'] ? 'Sudah dibaca' : 'Belum dibaca' }}</x-atoms.badge></td>
                            <td class="py-2.5">
                                @if(!$entry['read_at'])
                                    <form method="post" action="/app/inbox/{{ $entry['id'] }}/read" class="inline">
                                        @csrf
                                        <x-atoms.button class="button--lite">Tandai dibaca</x-atoms.button>
                                    </form>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-slate-500">Inbox kosong.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3"><x-molecules.pagination :items="$entries" /></div>
    </section>
</x-templates.console>
