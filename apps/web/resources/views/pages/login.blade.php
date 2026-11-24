<x-templates.app>
    <nav class="public-nav" aria-label="Navigasi publik">
        <a class="public-brand" href="/">
            <span class="public-brand-mark" aria-hidden="true">T</span>
            <span>Testyco Pacitan</span>
        </a>
        <a class="button button--lite" href="/">← Beranda</a>
    </nav>
    <div class="auth-shell">
        <div class="auth-brand">
            <x-atoms.badge>TESTYCO PACITAN</x-atoms.badge>
            <h2>Kelola mitra, toko dan penjualan dimsum dari satu tempat.</h2>
            <p>Masuk untuk membuka pesanan, stok, POS dan laporan sesuai akses akun Anda.</p>
        </div>
        <section class="auth-card">
            <h1>Masuk workspace</h1>
            <p>Gunakan akun yang diberikan pengelola organisasi (atau akun pemilik pusat yang dibuat lewat <code>scripts/bootstrap</code>).</p>
            <x-organisms.feedback />
            <form method="post" action="/login">
                @csrf
                <x-molecules.field name="email" label="Email" type="email" autocomplete="username" required />
                <x-molecules.field name="password" label="Kata sandi" type="password" autocomplete="current-password" required />
                <x-atoms.button>Masuk</x-atoms.button>
            </form>
        </section>
    </div>
</x-templates.app>
