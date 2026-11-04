<x-templates.console>
<a href="/app/orders/{{ $orderId }}">← Pesanan</a><h1>Revisi SKU dan sisa pesanan</h1>
<p>Barang yang sudah dikirim tidak dapat dihapus atau diganti. Perubahan sisa/tambahan baru menunggu persetujuan pihak lawan; pengiriman dan penerimaan ditahan selama review. Harga baris lama tetap, tambahan memakai quote baru yang ditampilkan pada usulan.</p>
@if($pending !== null)
<aside>Hasil belum pasti. Jangan membuat usulan atau keputusan lain.</aside><p>Versi ditinjau {{ $pending['body']['version'] }} · {{ $pending['body']['reason'] ?? 'Konfirmasi revisi' }}</p>
@foreach($pending['body']['existing'] ?? [] as $l)<p>Baris {{ $l['order_line_id'] }} · Usulan {{ $l['quantity_packs'] }} kemasan</p>@endforeach
@foreach($pending['body']['additions'] ?? [] as $l)<p>SKU sumber {{ $l['supplier_product_id'] }} → lokal {{ $l['local_product_id'] }} · Tambah {{ $l['quantity_packs'] }} kemasan</p>@endforeach
<form method="post" action="/app/orders/{{ $orderId }}/revisions/retry">@csrf<x-atoms.button>Ulangi request yang sama</x-atoms.button></form>
@else
<p>Pesanan {{ $orderId }} · Versi {{ $context['order']['version'] }}</p>
@if($context['actions']['propose'])
<form class="panel" method="post" action="/app/orders/{{ $orderId }}/revisions">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $context['order']['version'] }}">
<h2>Sisa baris lama</h2><p>Isi 0 untuk menghapus baris yang belum dikirim. Kenaikan atau SKU pengganti ditambahkan sebagai baris baru agar harga dan histori lama tetap.</p>
@foreach($context['order']['lines'] as $i=>$l)<fieldset><legend>{{ $l['sku'] }} · Isi {{ $l['pack_size'] }} · Harga historis Rp{{ $l['unit_price'] }} · Sudah kirim {{ $context['sent_packs'][$l['id']] ?? 0 }}</legend><input type="hidden" name="existing[{{ $i }}][order_line_id]" value="{{ $l['id'] }}"><x-molecules.field :name="'existing['.$i.'][quantity_packs]'" label="Jumlah total lama yang dipertahankan" type="number" :min="$context['sent_packs'][$l['id']] ?? 0" :max="$l['quantity_packs']" :value="$l['quantity_packs']" :remember="false" required /></fieldset>@endforeach
<h2>Tambahan / substitusi</h2><p>Lewati jumlah kosong. Pemetaan SKU lokal baru diusulkan pimpinan mitra agar katalog operasional tidak dibagikan ke pusat.</p>
@for($i=0;$i<3;$i++)<fieldset><legend>Tambahan {{ $i+1 }}</legend><label>SKU pusat<select name="additions[{{ $i }}][supplier_product_id]"><option value="">Pilih sumber</option>@foreach($context['supplier_products'] as $p)<option value="{{ $p['id'] }}">{{ $p['sku'] }} · Isi {{ $p['pack_size'] }}</option>@endforeach</select></label><label>SKU lokal<select name="additions[{{ $i }}][local_product_id]"><option value="">Pilih lokal</option>@foreach($context['local_products'] as $p)<option value="{{ $p['id'] }}">{{ $p['sku'] }} · Isi {{ $p['pack_size'] }}</option>@endforeach</select></label><x-molecules.field :name="'additions['.$i.'][quantity_packs]'" label="Jumlah kemasan tambahan" type="number" min="1" max="10000" :remember="false" /></fieldset>@endfor
<x-molecules.field name="reason" label="Alasan revisi" maxlength="2000" :remember="false" required /><x-atoms.button>Ajukan revisi untuk ditinjau pihak lawan</x-atoms.button></form>
@endif
<h2>Riwayat revisi dan perbandingan</h2>
@forelse($changes as $r)<article class="panel"><h3>{{ $r['status'] }} · {{ $r['reason'] }}</h3><p>Pengusul #{{ $r['requested_by'] }} · {{ $r['before']['requested_side'] }} · Rp{{ $r['before']['total_amount'] }} → Rp{{ $r['after']['total_amount'] }}</p>
@foreach($r['after']['lines'] as $l)<p>{{ $l['sku'] }} → {{ $l['local_sku'] }} · Usulan {{ $l['quantity_packs'] }} kemasan isi {{ $l['pack_size'] }} × Rp{{ $l['unit_price'] }} @php($old=collect($r['before']['lines'])->firstWhere('id',$l['id'])) · {{ $old ? 'Semula '.$old['quantity_packs'] : 'Baris baru' }}</p>@endforeach
@if($r['status']==='pending')@foreach(['approve'=>'Setujui revisi','reject'=>'Tolak revisi','withdraw'=>'Tarik usulan'] as $action=>$label)@if($context['actions'][$action])<form method="post" action="/app/orders/{{ $orderId }}/revisions/{{ $r['id'] }}/{{ $action }}">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $context['order']['version'] }}"><x-molecules.field name="reason" label="Catatan keputusan" maxlength="2000" :remember="false" :required="$action!=='approve'" /><x-atoms.button>{{ $label }}</x-atoms.button></form>@endif @endforeach @endif
<p>{{ $r['decision_note'] ?? '' }}</p></article>
@empty<p>Belum ada revisi.</p>@endforelse
<x-molecules.pagination :items="$changes" />
@endif
</x-templates.console>
