<x-templates.console>
    <div class="max-w-md mx-auto py-8">
        <section class="auth-card panel space-y-4">
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Pemulihan Akun</p>
                <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Atur kata sandi baru</h1>
            </div>
            <form method="post" action="/reset-password" class="space-y-4">
                @csrf
                <x-molecules.field name="email" label="Email akun" type="email" :remember="false" required />
                <x-molecules.field name="token" label="Token dari tautan pemulihan" :remember="false" autocomplete="off" required />
                <x-molecules.field name="password" label="Kata sandi baru (12+ karakter, besar/kecil dan angka)" type="password" :remember="false" autocomplete="new-password" required />
                <x-molecules.field name="password_confirmation" label="Ulangi kata sandi baru" type="password" :remember="false" autocomplete="new-password" required />
                <x-molecules.field name="code" label="Kode authenticator/recovery bila MFA aktif" :remember="false" autocomplete="one-time-code" />
                <x-atoms.button>Konfirmasi pemulihan</x-atoms.button>
            </form>
        </section>
    </div>
    <script src="/reset-password.js" defer></script>
</x-templates.console>
