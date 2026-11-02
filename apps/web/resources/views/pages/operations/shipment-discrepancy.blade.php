<x-templates.console>
<a href="/app/orders/{{ $order }}">← Pesanan</a><h1>Kasus selisih kiriman</h1>
<p>Owner/admin pusat mengusulkan rencana, pimpinan mitra mengonfirmasi/menolak. Catatan dibaca penerima dan pemasok yang berwenang. Pencatatan/persetujuan di sini belum melaksanakan retur, pengiriman pengganti atau kredit.</p>
@if($pending !== null)
<aside>Hasil request belum pasti. Tinjauan, versi dan request semula ditahan. Ulangi tanpa membuat pengganti.</aside>
<x-organisms.shipment-discrepancy-snapshot :snapshot="$pending['review']['snapshot']" />
@if($pending['review']['pending_resolution'])<x-organisms.shipment-discrepancy-plan :plan="$pending['review']['pending_resolution']" />@endif
<section class="panel"><h2>Pencatatan yang ditahan</h2><p>Versi yang ditinjau {{ $pending['body']['version'] ?? $pending['body']['order_version'] }}</p>
@foreach(['reason'=>'Alasan / keputusan','message'=>'Catatan tindak lanjut','proposal'=>'Rencana usulan','reference'=>'Referensi bukti'] as $key=>$label)@if(isset($pending['body'][$key]))<p>{{ $label }}: {{ $pending['body'][$key] }}</p>@endif @endforeach
@if(isset($pending['body']['type']))<p>Jenis rencana: {{ $pending['body']['type'] }}</p>@endif</section>
<form method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy/retry">@csrf<x-atoms.button>Ulangi request yang sama</x-atoms.button></form>
@else
<p>Pesanan {{ $order }} · {{ $c['order']['status'] }} · Versi {{ $c['order']['version'] }} · Kiriman {{ $shipment }}</p>
<x-organisms.shipment-discrepancy-snapshot :snapshot="$c['snapshot']" />
@if($c['actions']['report'])
<form class="panel" method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="order_version" value="{{ $c['order']['version'] }}"><x-molecules.field name="reason" label="Alasan laporan kasus" maxlength="2000" :remember="false" required /><x-molecules.field name="reference" label="Referensi bukti (teks, opsional)" maxlength="500" :remember="false" /><x-atoms.button>Catat kasus selisih</x-atoms.button></form>
@endif
@if($c['case'])
<section class="panel"><h2>Kasus {{ $c['case']['id'] }} · {{ $c['case']['status'] }}</h2><p>Versi kasus {{ $c['case']['version'] }} · Pelapor #{{ $c['case']['reported_by'] }}</p><p>{{ $c['case']['reason'] }}</p><p>Referensi bukti: {{ $c['case']['reference'] ?? '—' }}</p></section>
@if($c['actions']['propose_resolution'])
<form class="panel" method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy/resolutions">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $c['case']['version'] }}"><label>Jenis rencana<select name="type"><option value="return">Retur</option><option value="replacement">Pengganti</option><option value="credit">Kredit</option></select></label><x-molecules.field name="proposal" label="Rencana untuk ditinjau pimpinan mitra" maxlength="2000" :remember="false" required /><x-molecules.field name="reference" label="Referensi bukti (opsional)" maxlength="500" :remember="false" /><x-atoms.button>Ajukan rencana penyelesaian</x-atoms.button></form>
@endif
@if($c['pending_resolution'])
<x-organisms.shipment-discrepancy-plan :plan="$c['pending_resolution']" />
@foreach(['confirm'=>'Konfirmasi rencana','reject'=>'Tolak rencana','withdraw'=>'Tarik usulan pusat'] as $action=>$label)
@if($c['actions'][$action.'_resolution'])<form class="panel" method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy/resolutions/{{ $c['pending_resolution']['id'] }}/{{ $action }}">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $c['case']['version'] }}"><x-molecules.field name="reason" label="Catatan keputusan" maxlength="2000" :remember="false" :required="$action !== 'confirm'" /><x-atoms.button>{{ $label }}</x-atoms.button></form>@endif
@endforeach
@endif
<h2>Riwayat rencana yang telah diputuskan</h2>
@forelse($resolutions as $plan)<x-organisms.shipment-discrepancy-plan :plan="$plan" />@empty<p>Belum ada rencana yang diputuskan.</p>@endforelse
<x-molecules.pagination :items="$resolutions" />
@if($c['actions']['note'])<form class="panel" method="post" action="/app/orders/{{ $order }}/shipments/{{ $shipment }}/discrepancy/notes">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $c['case']['version'] }}"><x-molecules.field name="message" label="Catatan tindak lanjut (dibaca kedua pihak)" maxlength="2000" :remember="false" required /><x-molecules.field name="reference" label="Referensi bukti (opsional)" maxlength="500" :remember="false" /><x-atoms.button>Tambahkan catatan tindak lanjut</x-atoms.button></form>@endif
<h2>Riwayat catatan</h2>@forelse($notes as $note)<article class="panel"><p>Versi {{ $note['case_version'] }} · {{ $note['side'] }} · Aktor #{{ $note['actor_id'] }} · {{ $note['created_at'] }}</p><p>{{ $note['message'] }}</p><p>{{ $note['reference'] ?? '' }}</p></article>@empty<p>Belum ada catatan tindak lanjut.</p>@endforelse
<x-molecules.pagination :items="$notes" />
@elseif(!$c['actions']['report'])<p>Belum ada kasus. Pelaporan tersedia bagi penerima berwenang setelah kiriman dikonfirmasi memiliki kerusakan/kekurangan.</p>@endif
@endif
</x-templates.console>
