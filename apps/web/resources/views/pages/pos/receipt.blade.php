<x-templates.console>
    <div class="no-print flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Struk Transaksi POS</p>
            <h1 class="text-2xl font-bold text-slate-900 mt-0.5">Struk Pembayaran</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/app/pos/sales/{{ $sale['id'] }}/refunds" class="button button--lite">Review refund (pengelola berwenang)</a>
            <a href="/app/stores/{{ $sale['store_id'] }}/pos" class="button button--lite">Kembali ke POS</a>
        </div>
    </div>

    @php($cfg = $receiptSettings ?? session('pos_receipt_settings', ['header_text' => '', 'footer_text' => 'Simpan sebagai bukti transaksi', 'show_address' => true, 'show_cashier' => true, 'show_discount' => true, 'show_logo' => false, 'logo_data_uri' => null]))

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        <div class="panel">
            <x-organisms.sale-receipt :sale="$sale" :settings="$cfg" />
        </div>

        <div class="space-y-4 no-print">
            <section class="panel space-y-4">
                <h2 class="text-base font-semibold text-slate-900">Cetak Struk Thermal</h2>
                <label class="field">Kertas printer
                    <select data-receipt-paper required>
                        <option value="">Pilih ukuran kertas</option>
                        <option value="58">58 mm</option>
                        <option value="80">80 mm</option>
                    </select>
                </label>
                <p class="text-xs text-slate-600" data-print-status role="status">Pilih printer melalui dialog sistem. Periksa kertas sesudah mencetak; tidak ada transaksi penjualan baru.</p>
                <button class="button" type="button" data-print-receipt>Cetak melalui browser</button>
            </section>

            <details class="panel">
                <summary class="font-semibold text-sm text-slate-900 cursor-pointer">Edit Tampilan Struk Thermal (Logo, Header &amp; Footer)</summary>
                <form method="post" enctype="multipart/form-data" action="/app/pos/receipt-settings" class="mt-4 pt-3 border-t border-slate-200 space-y-3">
                    @csrf
                    @if(!empty($cfg['logo_data_uri']))
                        <div class="flex items-center gap-3">
                            <img src="{{ $cfg['logo_data_uri'] }}" alt="Logo saat ini" class="h-12 object-contain border border-slate-200 rounded p-1">
                            <label class="inline-flex items-center gap-1.5 text-xs text-rose-700">
                                <input type="checkbox" name="remove_logo" value="1">
                                <span>Hapus logo</span>
                            </label>
                        </div>
                    @endif
                    <label class="field">Upload Logo Struk (PNG/JPG/WebP, maks 512 KB)
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="show_logo" value="1" @checked($cfg['show_logo'] ?? false)>
                        <span>Tampilkan logo di atas struk</span>
                    </label>
                    <x-molecules.field name="header_text" label="Tagline / Header Tambahan" maxlength="160" :value="$cfg['header_text'] ?? ''" :remember="false" />
                    <x-molecules.field name="footer_text" label="Pesan Footer Struk" maxlength="240" :value="$cfg['footer_text'] ?? ''" :remember="false" />
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="show_address" value="1" @checked($cfg['show_address'] ?? true)> Alamat toko</label>
                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="show_cashier" value="1" @checked($cfg['show_cashier'] ?? true)> Nama kasir</label>
                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="show_discount" value="1" @checked($cfg['show_discount'] ?? true)> Baris diskon</label>
                    </div>
                    <x-atoms.button>Simpan pengaturan struk</x-atoms.button>
                </form>
            </details>
        </div>
    </div>

    <script src="/receipt.js?v={{ @filemtime(public_path('receipt.js')) }}" defer></script>
</x-templates.console>
