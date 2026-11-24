<x-templates.console>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Keamanan Akun</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Ganti kata sandi</h1>
            <p class="text-sm text-slate-600 mt-1">Semua sesi akan dicabut setelah kata sandi berubah.</p>
        </div>
        <a href="/app/account/security" class="button button--lite">Authenticator dan sesi perangkat</a>
    </div>

    <section class="panel max-w-lg">
        <form method="post" action="/app/account/password" class="space-y-4">
            @csrf
            <x-molecules.field name="current_password" label="Kata sandi saat ini" type="password" autocomplete="current-password" required />
            <x-molecules.field name="password" label="Kata sandi baru (12+ karakter, besar/kecil dan angka)" type="password" autocomplete="new-password" minlength="12" required />
            <x-molecules.field name="password_confirmation" label="Ulangi kata sandi baru" type="password" autocomplete="new-password" required />
            <x-atoms.button>Ganti kata sandi</x-atoms.button>
        </form>
    </section>
</x-templates.console>
