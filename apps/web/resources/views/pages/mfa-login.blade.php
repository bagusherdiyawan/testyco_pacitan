<x-templates.console>
    <div class="max-w-md mx-auto py-8">
        <section class="auth-card panel space-y-4">
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Keamanan Login</p>
                <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Verifikasi dua langkah</h1>
            </div>
            <form method="post" action="/login/mfa" class="space-y-4">
                @csrf
                <x-molecules.field name="code" label="Kode authenticator atau recovery sekali pakai" autocomplete="one-time-code" :remember="false" required />
                <x-atoms.button>Verifikasi dan masuk</x-atoms.button>
            </form>
            <p class="text-sm"><a href="/login" class="text-emerald-700 hover:underline">Mulai login baru</a></p>
        </section>
    </div>
</x-templates.console>
