<x-templates.console :store="$store">
    <h1>{{ $store['name'] }}</h1>
    <p><x-atoms.badge>{{ $store['active'] ? 'Toko aktif' : 'Toko nonaktif' }}</x-atoms.badge> Menu POS, stok, kemasan, dan laporan toko tersedia di sidebar kiri.</p>

    @if($store['can_order'])
        <div class="section-heading">
            <h2>Pesanan Toko</h2>
            @if($store['active'])
                <a class="button" href="/app/stores/{{ $store['id'] }}/orders/new">+ Buat pengajuan</a>
            @endif
        </div>
        <x-organisms.order-list :orders="$orders" />
    @endif

    <div class="section-heading">
        <h2>Stok Per Batch</h2>
    </div>
    <p>Angka dalam biji. Stok karantina dipisahkan dari stok yang diterima baik; jumlah ini bukan sertifikasi mutu otomatis.</p>
    @if(count($batches))
    <div class="panel">
    <table>
        <thead>
            <tr>
                <th>SKU &amp; Produk</th>
                <th>Batch &amp; Kedaluwarsa</th>
                <th>Baik (Utuh)</th>
                <th>Terbuka</th>
                <th>Siap Jual</th>
                <th>Karantina</th>
                <th>Susut</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @foreach($batches as $batch)
                <tr>
                    <td><strong>{{ $batch['sku'] }} · {{ $batch['name'] }}</strong><br><small>Isi {{ $batch['pack_size'] }} biji/kemasan</small></td>
                    <td>Batch {{ $batch['batch_code'] }}<br><small>Kedaluwarsa {{ $batch['expires_on'] }}</small></td>
                    <td><strong>{{ $batch['usable_pieces'] }} biji</strong></td>
                    <td>{{ $batch['opened_pieces'] ?? 0 }} biji</td>
                    <td>{{ $batch['ready_pieces'] ?? 0 }} biji</td>
                    <td>Karantina: {{ $batch['quarantined_pieces'] }} biji</td>
                    <td>{{ $batch['waste_pieces'] ?? 0 }} biji</td>
                    <td>
                        @if(($store['can_manage_stock'] ?? false) && isset($batch['id']))
                            <a href="/app/stores/{{ $store['id'] }}/inventory/ledger?batch_id={{ urlencode($batch['id']) }}">Riwayat batch ini</a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @else
        <p class="panel">Belum ada penerimaan stok.</p>
    @endif
    <x-molecules.pagination :items="$batches" />

    @if($store['can_intake'] && $store['active'])
        <section class="panel">
            <h2>Penerimaan Fisik Gudang Pusat</h2>
            <form method="post" action="/app/stores/{{ $store['id'] }}/inventory/intakes">
                @csrf
                <x-molecules.operation-key />
                <label class="field">SKU
                    <select name="product_id" required>
                        @foreach($products as $product)
                            <option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }} ({{ $product['pack_size'] }} biji/kemasan)</option>
                        @endforeach
                    </select>
                </label>
                <div class="form-grid">
                    <x-molecules.field name="batch_code" label="Kode batch" required maxlength="80" />
                    <x-molecules.field name="expires_on" label="Tanggal kedaluwarsa" type="date" required />
                    <x-molecules.field name="quantity_packs" label="Kemasan diterima baik" type="number" min="1" max="10000" required />
                    <x-molecules.field name="note" label="Referensi penerimaan dan pemeriksaan fisik" required maxlength="2000" />
                </div>
                <x-atoms.button>Konfirmasi penerimaan gudang</x-atoms.button>
            </form>
        </section>
    @endif
</x-templates.console>
