<x-templates.app>
<section class="hero" aria-labelledby="hero-title">
<div>
<x-atoms.badge>TESTYCO PACITAN</x-atoms.badge>
<h1 id="hero-title">Operasional dalam satu ekosistem.</h1>
<p>Fondasi jaringan mitra, toko, dan POS.</p>
<div class="hero-actions"><a class="button button--accent button--lg" href="/login">Masuk workspace →</a></div>
</div>
<ul class="hero-points" aria-label="Cakupan utama">
<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l1-5h16l1 5M4 9v11h16V9M9 20v-6h6v6"/></svg>Mitra, toko dan pengguna</li>
<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/></svg>Pesanan, stok dan batch</li>
<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3zM8 8h8M8 12h8"/></svg>POS, shift kas dan struk</li>
<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V4M4 20h16M8 16v-5M13 16V8M18 16v-3"/></svg>Laporan dan pembukuan</li>
</ul>
</section>
<aside role="status">Lingkungan pengembangan. Akses organisasi, toko, pengguna dan katalog tersedia setelah masuk.</aside>
@if($unavailable)<p role="alert">API belum tersedia. Periksa koneksi layanan backend.</p>@endif
</x-templates.app>
