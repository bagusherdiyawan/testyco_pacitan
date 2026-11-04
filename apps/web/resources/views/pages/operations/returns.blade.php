<x-templates.console>
<a href="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy">← Kasus selisih</a><h1>Pengiriman barang retur</h1>
<p>Retur hanya barang rusak fisik dari karantina toko mitra ke karantina gudang asal. Barang kurang/hilang tidak dapat diretur. Penerimaan tidak membuat barang layak jual. Tidak ada tagihan baru atau nota kredit dari alur ini.</p>
@if($pending)
<aside>Hasil belum pasti. Jangan membuat pengiriman atau penerimaan retur lain.</aside>
<x-organisms.shipment-discrepancy-plan :plan="$pending['review']['resolution']" />
<p>Versi kasus yang ditinjau {{ $pending['body']['version'] }} · Aksi {{ $pending['action'] === 'dispatch' ? 'Pengiriman' : 'Penerimaan' }}</p>
@foreach($pending['body']['lines'] as $row)
@if($pending['action'] === 'dispatch')@php($q=collect($pending['review']['quota'])->firstWhere('shipment_line_id',$row['original_shipment_line_id']))
@foreach($row['allocations'] as $a)@php($b=collect($pending['review']['batches'])->firstWhere('id',$a['batch_id']))<p>{{ $q['sku'] }} · Batch {{ $b['batch_code'] }} · Kirim {{ $a['quantity_packs'] }} kemasan isi {{ $q['pack_size'] }}</p>@endforeach
@else<p>Baris {{ $row['return_line_id'] }} · Diterima karantina {{ $row['received_packs'] }} · {{ $row['condition_note'] ?? '' }}</p>@endif
@endforeach
<p>{{ $pending['body']['note'] ?? '' }}</p>
<form method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy/returns/retry">@csrf<x-atoms.button>Ulangi request yang sama</x-atoms.button></form>
@else
@php($labels=['ready'=>'Siap dikirim','in_transit'=>'Dalam pengiriman','partially_fulfilled'=>'Sebagian retur diterima karantina','fulfilled'=>'Returan selesai diterima karantina','requires_follow_up'=>'Perlu tindak lanjut: retur kurang/rusak'])
<h2>{{ $labels[$c['execution_status']] ?? $c['execution_status'] }}</h2>
<x-organisms.shipment-discrepancy-plan :plan="$c['resolution']" />
<p>Versi kasus {{ $c['case_version'] }}. Sisa kuota mengurangi seluruh jumlah yang telah dikirim, termasuk yang kemudian rusak/hilang. Selisih baru tidak menambah kuota otomatis.</p>
@foreach($c['quota'] as $q)<section class="panel"><h3>{{ $q['sku'] }} → {{ $q['local_sku'] }}</h3><p>Selisih awal {{ $q['limit_packs'] }} · Retur dikirim {{ $q['return_sent_packs'] }} · Diterima karantina {{ $q['return_received_packs'] }} · Dalam perjalanan {{ $q['in_transit_packs'] }} · Sisa kirim {{ $q['remaining_packs'] }} kemasan isi {{ $q['pack_size'] }}</p></section>@endforeach
@if($c['can_dispatch'])
<form class="panel" method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy/returns">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $c['case_version'] }}">
<input type="hidden" name="destination_bucket" value="quarantined"><p>Tujuan: karantina gudang asal. Konfirmasi ini menyetujui penempatan karantina.</p><h2>Kirim retur dari karantina mitra</h2>
@foreach($c['quota'] as $i=>$q)@if($q['remaining_packs']>0)<fieldset><legend>{{ $q['sku'] }} · Sisa {{ $q['remaining_packs'] }} kemasan</legend><input type="hidden" name="lines[{{ $i }}][original_shipment_line_id]" value="{{ $q['shipment_line_id'] }}"><label>Batch sumber<select name="lines[{{ $i }}][batch_id]"><option value="">Pilih batch untuk jumlah yang dikirim</option>@foreach($c['batches'] as $b)@if($b['product_id']===$q['allocation_product_id'] && $b['pack_size']===$q['pack_size'])<option value="{{ $b['id'] }}">{{ $b['batch_code'] }} · Kedaluwarsa {{ substr($b['expires_on'],0,10) }} · {{ $b['quarantined_pieces'] }} biji karantina</option>@endif @endforeach</select></label><x-molecules.field :name="'lines['.$i.'][quantity_packs]'" label="Jumlah kirim kemasan (kosong/0 untuk dilewati)" type="number" min="0" :max="$q['remaining_packs']" :remember="false" /></fieldset>@endif @endforeach
<x-molecules.field name="handler" label="Petugas / pihak serah terima" maxlength="120" :remember="false" required /><x-molecules.field name="note" label="Catatan pengiriman" maxlength="2000" :remember="false" /><p>Konfirmasi berikut memotong stok karantina mitra sesuai batch/jumlah yang dipilih.</p><x-atoms.button>Konfirmasi kirim dan kurangi stok karantina mitra</x-atoms.button></form>
@endif
<h2>Riwayat kiriman retur</h2>
@forelse($history as $item)<x-organisms.return-shipment :item="$item" />
@if($item['can_receive'])<form class="panel" method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy/returns/{{ $item['id'] }}/receive">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $c['case_version'] }}"><input type="hidden" name="page" value="{{ $c['current_page'] }}"><h3>Periksa fisik dan terima retur</h3>
@foreach($item['lines'] as $i=>$line)<fieldset><legend>{{ $line['local_sku'] }} · Batch {{ $line['batch_code'] }} · Dikirim {{ $line['quantity_packs'] }} kemasan</legend><input type="hidden" name="lines[{{ $i }}][return_line_id]" value="{{ $line['id'] }}"><x-molecules.field :name="'lines['.$i.'][received_packs]'" label="Kemasan diterima karantina (isi 0 bila tidak ada)" type="number" min="0" :max="$line['quantity_packs']" :remember="false" required /><x-molecules.field :name="'lines['.$i.'][condition_note]'" label="Kondisi/alasan selisih (wajib jika tidak semua baik)" maxlength="2000" :remember="false" /></fieldset>@endforeach
<x-molecules.field name="note" label="Catatan penerimaan" maxlength="2000" :remember="false" /><x-atoms.button>Konfirmasi penerimaan fisik dan tambah stok karantina gudang</x-atoms.button></form>@endif
@empty<p>Belum ada kiriman retur.</p>@endforelse
<x-molecules.pagination :items="$history" />
@endif
</x-templates.console>
