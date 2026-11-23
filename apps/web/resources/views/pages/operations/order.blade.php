<x-templates.console>
    <p><a href="/app">← Workspace</a></p>
    <h1>{{ $order['destination_name'] }}</h1>
    <p>
        <x-atoms.badge>{{ $order['status'] }}</x-atoms.badge>
        <span>{{ $order['id'] }} · Versi {{ $order['version'] }} · {{ $order['delivery_mode'] }} · {{ $order['kind'] }}</span>
    </p>
    <p>{{ $order['destination_address'] }}</p>
    @if($order['note'] || $order['decision_note'])
        <p>{{ $order['note'] }} {{ $order['decision_note'] }}</p>
    @endif

    <div class="pill-actions">
        @if($order['kind'] === 'wholesale')
            <a class="button button--lite" href="/app/orders/{{ $order['id'] }}/billing">Tagihan, pembayaran dan konsinyasi (pimpinan)</a>
        @endif
        @if(in_array($order['status'], ['approved', 'processing', 'dispatched', 'partially_received', 'completed'], true) && $order['kind'] === 'wholesale')
            <a class="button button--lite" href="/app/orders/{{ $order['id'] }}/revisions">Revisi SKU/jumlah dan substitusi sisa pesanan</a>
        @endif
        @if(config('console.show_order_changes_legacy', false))
            <a class="button button--lite" href="/app/orders/{{ $order['id'] }}/changes">Usulan pengurangan dan persetujuan ulang</a>
        @endif
    </div>

    @if($order['change_pending'] ?? false)
        <aside>Pesanan ditahan: ada usulan perubahan yang menunggu keputusan.</aside>
    @endif

    <section class="panel">
        <h2>Item dan harga historis</h2>
        <table>
            <thead>
                <tr>
                    <th>SKU Pusat → Lokal</th>
                    <th>Pesanan</th>
                    <th>Harga / Kemasan</th>
                    <th>Terkirim</th>
                    <th>Diterima Baik</th>
                    <th>Sisa Kirim</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order['lines'] as $line)
                    @php
                        $dispatched = $progress[$line['id']]['dispatched'] ?? 0;
                        $good = $progress[$line['id']]['good'] ?? 0;
                        $remaining = max(0, $line['quantity_packs'] - $dispatched);
                    @endphp
                    <tr>
                        <td><strong>{{ $line['sku'] }} → {{ $line['local_sku'] }}</strong></td>
                        <td>{{ $line['quantity_packs'] }} kemasan × {{ $line['pack_size'] }} biji</td>
                        <td>Rp {{ number_format($line['unit_price'], 0, ',', '.') }}</td>
                        <td>{{ $dispatched }}</td>
                        <td>{{ $good }}</td>
                        <td>{{ $remaining }} kemasan</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p><strong>Total {{ $order['kind'] === 'internal_transfer' ? 'transfer internal' : 'pesanan' }}: Rp {{ number_format($order['total_amount'], 0, ',', '.') }}</strong></p>
    </section>

    @if($order['can_approve'] && in_array($order['status'], ['awaiting_approval', 'rejected'], true))
        <p><a class="button button--lite" href="/app/orders/{{ $order['id'] }}/edit">Ubah jumlah/item sebelum persetujuan</a></p>
        @foreach(['approve' => 'Setujui dan kirim ke pusat', 'reject' => 'Kembalikan / tolak'] as $action => $label)
            <form class="panel" method="post" action="/app/orders/{{ $order['id'] }}/{{ $action }}">
                @csrf
                <x-molecules.operation-key />
                <input type="hidden" name="version" value="{{ $order['version'] }}">
                @if($action === 'reject')
                    <x-molecules.field name="reason" label="Alasan" required />
                @endif
                <x-atoms.button>{{ $label }}</x-atoms.button>
            </form>
        @endforeach
    @endif

    @if(!($order['change_pending'] ?? false) && $order['can_approve'] && in_array($order['status'], ['awaiting_approval', 'rejected', 'approved', 'processing'], true))
        <form class="panel" method="post" action="/app/orders/{{ $order['id'] }}/cancel">
            @csrf
            <x-molecules.operation-key />
            <input type="hidden" name="version" value="{{ $order['version'] }}">
            <x-molecules.field name="reason" label="Alasan pembatalan" required />
            <x-atoms.button>Batalkan pesanan</x-atoms.button>
        </form>
    @endif

    @if($order['can_process'] && $order['status'] === 'approved')
        <form class="panel" method="post" action="/app/orders/{{ $order['id'] }}/process">
            @csrf
            <x-molecules.operation-key />
            <input type="hidden" name="version" value="{{ $order['version'] }}">
            <label class="field">Gudang sumber
                <select name="source_store_id" required>
                    @foreach($stores as $store)
                        @if($store['organization_id'] === $order['supplier_organization_id'] && $store['id'] !== $order['store_id'] && $store['active'])
                            <option value="{{ $store['id'] }}">{{ $store['name'] }}</option>
                        @endif
                    @endforeach
                </select>
            </label>
            <x-atoms.button>Terima pesanan dan siapkan</x-atoms.button>
        </form>
    @endif

    @if(!($order['change_pending'] ?? false) && ($order['can_process'] || $order['access_side'] === 'supplier') && in_array($order['status'], ['processing', 'dispatched', 'partially_received'], true))
        <section class="panel">
            <h2>Alokasi batch dan pengiriman</h2>
            <p>Pilih batch secara manual. Jumlah nol tidak dikirim. Periksa kedaluwarsa dan kondisi sesuai SOP.</p>
            <form method="post" action="/app/orders/{{ $order['id'] }}/shipments">
                @csrf
                <x-molecules.operation-key />
                <input type="hidden" name="version" value="{{ $order['version'] }}">
                <x-molecules.field name="handler" label="Petugas / pihak serah terima" required />
                <x-molecules.field name="note" label="Catatan pengiriman" />
                @foreach($order['lines'] as $i => $line)
                    <fieldset>
                        <legend>{{ $line['sku'] }}</legend>
                        <input type="hidden" name="lines[{{ $i }}][order_line_id]" value="{{ $line['id'] }}">
                        @forelse(collect($batches)->filter(fn($b) => $b['product_id'] === $line['supplier_product_id'] && $b['pack_size'] === $line['pack_size'] && $b['usable_pieces'] > 0)->values() as $j => $batch)
                            <input type="hidden" name="lines[{{ $i }}][allocations][{{ $j }}][batch_id]" value="{{ $batch['id'] }}">
                            <label class="field">{{ $batch['batch_code'] }} · Kedaluwarsa {{ $batch['expires_on'] }} · {{ $batch['usable_pieces'] }} biji tersedia
                                <input type="number" name="lines[{{ $i }}][allocations][{{ $j }}][quantity_packs]" min="0" max="10000" value="0">
                            </label>
                        @empty
                            <p>Belum ada stok batch yang cocok.</p>
                        @endforelse
                    </fieldset>
                @endforeach
                <x-atoms.button>Konfirmasi pengiriman</x-atoms.button>
            </form>
        </section>
    @endif

    <h2>Pengiriman dan penerimaan aktual</h2>
    @forelse($shipments as $shipment)
        <section class="panel">
            <h3>{{ $shipment['id'] }}</h3>
            <p>Petugas: {{ $shipment['handler'] }} · <x-atoms.badge>{{ $shipment['received_at'] ? 'Sudah diterima' : 'Menunggu penerimaan' }}</x-atoms.badge></p>
            <table>
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Dikirim</th>
                        <th>Isi Kemasan</th>
                        <th>Hasil Penerimaan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipment['lines'] as $line)
                        <tr>
                            <td><strong>Batch {{ $line['batch_code'] }}</strong></td>
                            <td>{{ $line['quantity_packs'] }} kemasan</td>
                            <td>{{ $line['pack_size'] }} biji/kemasan</td>
                            <td>
                                @if($shipment['received_at'])
                                    Baik {{ $line['good_packs'] }}, rusak {{ $line['damaged_packs'] }}, kurang {{ $line['quantity_packs'] - $line['good_packs'] - $line['damaged_packs'] }}
                                    @if($line['condition_note']) · {{ $line['condition_note'] }} @endif
                                @else
                                    Menunggu konfirmasi
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if(!$shipment['received_at'] && $order['access_side'] === 'recipient')
                <form method="post" action="/app/orders/{{ $order['id'] }}/shipments/{{ $shipment['id'] }}/receive">
                    @csrf
                    <x-molecules.operation-key />
                    <input type="hidden" name="version" value="{{ $order['version'] }}">
                    @foreach($shipment['lines'] as $i => $line)
                        <fieldset>
                            <legend>Batch {{ $line['batch_code'] }} (dikirim {{ $line['quantity_packs'] }} kemasan)</legend>
                            <input type="hidden" name="lines[{{ $i }}][shipment_line_id]" value="{{ $line['id'] }}">
                            <label class="field">Diterima baik<input name="lines[{{ $i }}][good_packs]" type="number" min="0" max="{{ $line['quantity_packs'] }}" required></label>
                            <label class="field">Diterima rusak / karantina<input name="lines[{{ $i }}][damaged_packs]" type="number" min="0" max="{{ $line['quantity_packs'] }}" value="0" required></label>
                            <label class="field">Kondisi dan alasan selisih<input name="lines[{{ $i }}][condition_note]" maxlength="2000"></label>
                        </fieldset>
                    @endforeach
                    <x-molecules.field name="note" label="Catatan penerimaan" />
                    <x-atoms.button>Konfirmasi jumlah aktual</x-atoms.button>
                </form>
            @endif
            @if($shipment['received_at'])
                <p><a href="/app/orders/{{ $order['id'] }}/shipments/{{ $shipment['id'] }}/discrepancy">Kasus selisih kiriman {{ $shipment['id'] }}</a></p>
            @endif
        </section>
    @empty
        <p class="panel">Belum ada pengiriman.</p>
    @endforelse

    @if($order['status'] === 'partially_received')
        <aside>Pesanan diterima sebagian. Lanjutkan pengiriman sisa kuota atau selesaikan kasus selisih/pengganti/retur pada kiriman terkait.</aside>
    @elseif($order['status'] === 'settled')
        <aside>Pesanan selesai diselesaikan melalui penanganan kasus selisih, pengganti, retur, atau nota kredit.</aside>
    @endif
</x-templates.console>
