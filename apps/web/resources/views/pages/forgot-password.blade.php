<x-templates.console>
    <div class="max-w-md mx-auto py-8">
        <section class="auth-card panel space-y-4">
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Bantuan Akun</p>
                <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Pulihkan kata sandi</h1>
            </div>
            <form method="post" action="/forgot-password" class="space-y-4">
                @csrf
                <x-molecules.field name="email" label="Email akun" type="email" :remember="false" required />
                <x-atoms.button>Kirim instruksi pemulihan</x-atoms.button>
            </form>
            <p class="text-sm"><a href="/login" class="text-emerald-700 hover:underline">Kembali masuk</a></p>
        </section>
    </div>
</x-templates.console>
