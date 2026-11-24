<x-templates.app>
    <nav class="public-nav" aria-label="Navigasi publik">
        <a class="public-brand" href="/">
            <span class="public-brand-mark" aria-hidden="true">T</span>
            <span>Testyco Pacitan</span>
        </a>
        <div class="public-nav-links">
            <a href="#tentang">Tentang Kami</a>
            <a href="#menu">Menu Unggulan</a>
            <a href="#kemitraan">Kemitraan</a>
            <a href="#kontak">Kontak</a>
            <a class="button" href="/login">Masuk</a>
        </div>
    </nav>

    <section class="hero" aria-labelledby="hero-title">
        <div>
            <x-atoms.badge>TESTYCO INDONESIA · DAPUR PUSAT PACITAN</x-atoms.badge>
            <h1 id="hero-title">Dimsum hangat berkualitas. Operasional dalam satu ekosistem.</h1>
            <p>Diproduksi langsung dari dapur pusat Pacitan menggunakan bahan baku pilihan—tersedia dalam sajian siap santap (<em>ready to eat</em>) dan kemasan beku (<em>frozen food</em>) untuk jaringan booth dan mitra resmi.</p>
            <div class="hero-actions">
                <a class="button button--accent button--lg" href="/login">Masuk workspace →</a>
                <a class="button button--ghost button--lg" href="#menu">Lihat Menu Testyco</a>
            </div>
        </div>
        <ul class="hero-points" aria-label="Cakupan utama">
            <li>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l1-5h16l1 5M4 9v11h16V9M9 20v-6h6v6"/></svg>
                <div><strong>Produksi Dapur Pusat Mandiri</strong><span>Pengolahan bahan baku terstandar hingga siap distribusi</span></div>
            </li>
            <li>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/></svg>
                <div><strong>Distribusi &amp; Kontrol Stok Booth</strong><span>Penerimaan fisik, buka/kukus, masa simpan, dan kemasan booth</span></div>
            </li>
            <li>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3zM8 8h8M8 12h8"/></svg>
                <div><strong>Kasir POS &amp; Shift Anti-Fraud</strong><span>Transaksi tunai/QRIS, blind close, dan serah terima kas shift</span></div>
            </li>
            <li>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V4M4 20h16M8 16v-5M13 16V8M18 16v-3"/></svg>
                <div><strong>HPP &amp; Laporan Penjualan</strong><span>Pantau omzet booth, pemakaian bahan, dan margin keuntungan</span></div>
            </li>
        </ul>
    </section>

    <section id="tentang" class="cp-section">
        <div class="cp-about">
            <div>
                <span class="badge">Tentang Testyco</span>
                <h2>Cita Rasa Dimsum Pilihan dari Pacitan untuk Semua Kalangan</h2>
                <p>Testyco hadir membawa olahan dimsum dan camilan beku yang lembut, <em>juicy</em>, dan higienis. Seluruh proses produksi dikelola secara mandiri oleh dapur pusat kami—mulai dari pemilihan bahan baku segar, pengukusan terukur, pengemasan kedap, hingga pengiriman terjadwal ke setiap booth mitra.</p>
            </div>
            <div class="cp-metrics">
                <div class="cp-metric">
                    <strong>100% Produksi Mandiri</strong>
                    <span>Bahan baku &amp; resep dijaga langsung oleh dapur pusat</span>
                </div>
                <div class="cp-metric">
                    <strong>Siap Saji &amp; Frozen</strong>
                    <span>Melayani pelanggan langsung di booth maupun stok rumah</span>
                </div>
                <div class="cp-metric">
                    <strong>Pacitan &amp; Jawa Timur</strong>
                    <span>Basis operasional utama di Kabupaten Pacitan &amp; sekitarnya</span>
                </div>
                <div class="cp-metric">
                    <strong>Terintegrasi POS</strong>
                    <span>Stok, shift kasir, hingga HPP tercatat rapi real-time</span>
                </div>
            </div>
        </div>
    </section>

    <section id="menu" class="cp-section">
        <div class="cp-head">
            <span class="badge">Menu Unggulan</span>
            <h2>Produk Favorit Pelanggan Testyco</h2>
            <p>Tersedia untuk pembelian langsung di booth (<em>dine-in</em> / <em>takeaway</em>) maupun kemasan beku siap kukus di rumah.</p>
        </div>
        <div class="cp-grid">
            <article class="cp-card">
                <span class="cp-tag">Best Seller</span>
                <h3>Dimsum Ayam Original + Chili Oil</h3>
                <p>Dimsum daging ayam pilihan yang lembut dan <em>juicy</em>, dipadukan dengan racikan saus chili oil pedas gurih khas Testyco.</p>
            </article>
            <article class="cp-card">
                <span class="cp-tag">Favorit Booth</span>
                <h3>Dimsum Saus Mentai</h3>
                <p>Perpaduan dimsum ayam hangat dengan siraman saus mentai <em>creamy</em> gurih yang dipanggang ringan, tersedia paket porsi dan box.</p>
            </article>
            <article class="cp-card">
                <span class="cp-tag">Ekonomis</span>
                <h3>Dimsum Jenderal</h3>
                <p>Varian ekonomis dengan kombinasi daging ayam dan sapi bertekstur kenyal, cocok untuk camilan harian maupun paket acara.</p>
            </article>
            <article class="cp-card">
                <span class="cp-tag">Frozen &amp; Camilan</span>
                <h3>Gyoza, Risoles &amp; Lemper Sultan</h3>
                <p>Pilihan pelengkap praktis mulai dari gyoza, risoles, hingga lemper panggang suwiran ayam berbumbu rempah khas.</p>
            </article>
        </div>
    </section>

    <section id="kemitraan" class="cp-section">
        <div class="cp-head">
            <span class="badge">Ekosistem Operasional</span>
            <h2>Alur Kemitraan &amp; Operasional Terintegrasi</h2>
            <p>Setiap mitra dan petugas booth terhubung ke sistem terpadu agar kualitas produk, stok fisik, dan setoran kas selalu terjaga.</p>
        </div>
        <div class="flow-strip">
            <article class="flow-card">
                <span class="flow-step">1</span>
                <h2>Produksi &amp; Standar Pusat</h2>
                <p>Dapur pusat mencatat pembelian bahan baku, proses produksi, serta kontrol mutu sebelum stok siap dikirim.</p>
            </article>
            <article class="flow-card">
                <span class="flow-step">2</span>
                <h2>Pengiriman &amp; Stok Booth</h2>
                <p>Booth mengajukan kebutuhan stok, menerima kiriman fisik terverifikasi, lalu memantau stok beku, terbuka, dan siap jual.</p>
            </article>
            <article class="flow-card">
                <span class="flow-step">3</span>
                <h2>Kasir POS &amp; Laporan</h2>
                <p>Penjualan tunai/non-tunai, aturan buka-tutup shift (<em>blind close</em>), dan laporan penjualan harian tercatat transparan.</p>
            </article>
        </div>
    </section>

    <section id="kontak" class="cp-section">
        <div class="cp-contact">
            <div>
                <h2>Hubungi Testyco Pacitan</h2>
                <p>Tertarik bermitra booth, reseller <em>frozen food</em>, atau memesan untuk acara di Pacitan dan sekitarnya?</p>
            </div>
            <div>
                <h3>Lokasi Dapur &amp; Kantor Operasional</h3>
                <p>Perum. Laris 2 No. 11, RT/RW 01/XI, Dusun Pojok, Desa Sidoharjo, Kec. Pacitan, Kab. Pacitan, Jawa Timur</p>
                <p>Telepon / WhatsApp: <strong>0822-2833-3103</strong> · <strong>0852-3676-7808</strong></p>
            </div>
            <div style="justify-self: start;">
                <a class="button button--accent button--lg" href="/login">Masuk ke Workspace Staf →</a>
            </div>
        </div>
    </section>

    <aside role="status">Portal internal &amp; mitra Testyco Pacitan. Akses organisasi, toko, pengguna dan produk tersedia setelah masuk.</aside>
    @if($unavailable)<p role="alert">API belum tersedia. Periksa koneksi layanan backend.</p>@endif
</x-templates.app>
