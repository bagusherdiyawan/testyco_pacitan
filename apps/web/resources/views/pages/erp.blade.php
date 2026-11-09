<x-templates.console>
@php($finance=in_array($context['section']??$q['section']??'materials',['distribution_costs','accounts','periods','journals','trial_balance','posting','posting_events','valuations','valuation_stock','cost_ledger']))
<a href="/app/organizations/{{ $organization }}">← Organisasi</a><h1>{{ $finance?'Pembukuan dan jurnal':'Bahan baku, pembelian dan produksi' }}</h1>
@if($finance)<p>Pemetaan akun disetujui pengelola lain. Periksa antrean dan tanggal sumber sebelum menutup periode. Pembelian wholesale ditampung sebelum rekonsiliasi persediaan.</p>@else
<p>Hasil produksi masuk karantina sampai owner/admin pusat menyelesaikan pemeriksaan. Jumlah bahan menggunakan satuan yang tercantum; harga dan biaya dalam rupiah. Tidak ada resep, biaya, atau masa simpan bawaan.</p>
@endif
@if($pending || $candidate)
@php($review=$pending??$candidate)<section class="panel"><h2>{{ $pending?'Hasil belum pasti':'Tinjauan sebelum dikirim' }} · {{ $review['form']['label'] }}</h2>
<pre class="audit-snapshot">{{ $review['snapshot'] }}</pre><dl>@foreach($review['display'] as $label=>$value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach</dl>
@if($pending)<p>Jangan membuat pengganti. Ulangi permintaan yang sama sampai hasil diketahui, lalu periksa riwayat terbaru.</p><form method="post" action="/app/organizations/{{ $organization }}/erp/retry">@csrf<x-atoms.button>Ulangi permintaan yang sama</x-atoms.button></form>
@else<form method="post" action="/app/organizations/{{ $organization }}/erp/confirm">@csrf<x-atoms.button>Konfirmasi dan kirim</x-atoms.button></form><form method="post" action="/app/organizations/{{ $organization }}/erp/edit">@csrf<x-atoms.button>Kembali ke formulir</x-atoms.button></form>@endif</section>
@else
<nav class="panel">@foreach(array_intersect_key(\App\Modules\Manufacturing\Presentation\ErpController::SECTIONS,array_flip($context['allowed_sections']??array_keys(\App\Modules\Manufacturing\Presentation\ErpController::SECTIONS))) as $key=>$label)<a href="{{ url()->current().'?'.http_build_query(['section'=>$key,...(($context['can_manage']??false)?[]:['store_id'=>$q['store_id']??''])]) }}">{{ $label }}</a> · @endforeach</nav>
<form method="get" class="panel"><input type="hidden" name="section" value="{{ $context['section'] }}"><label>Gudang<select name="store_id">@if($context['can_manage']??false)<option value="">Semua</option>@endif
@foreach($context['filter_stores'] as $id=>$name)<option value="{{ $id }}" @selected(($q['store_id']??'')===$id)>{{ $name }}</option>@endforeach</select></label>
@if(in_array($context['section'],['lots','planning']))<label>Bahan<select name="material_id"><option value="">Semua</option>@foreach($context['filter_materials'] as $id=>$name)<option value="{{ $id }}" @selected(($q['material_id']??'')===$id)>{{ $name }}</option>@endforeach</select></label>@endif
@if(in_array($context['section'],['journals','trial_balance']))<label>Periode<select name="period_id"><option value="">Pilih</option>@foreach($context['filter_periods']??[] as $id=>$name)<option value="{{ $id }}" @selected(($q['period_id']??'')===$id)>{{ $name }}</option>@endforeach</select></label>@endif
@if($context['section']==='report')<label>Dari (WIB)<input type="date" name="from" value="{{ $q['from']??'' }}" required></label><label>Sampai inklusif (WIB)<input type="date" name="to" value="{{ $q['to']??'' }}" required></label>@endif
<x-atoms.button>Terapkan filter</x-atoms.button></form>
@if(isset($context['notice']))<p>{{ $context['notice'] }}</p>@endif
@foreach($context['forms'] as $form)<x-organisms.erp-form :form="$form" :organization="$organization" :query="$q" />@endforeach
@foreach($items as $row)<section class="panel"><pre class="audit-snapshot">{{ $row['summary'] }}</pre>
@if($context['section']==='lots' && ($context['can_manage']??false))<a href="{{ url()->current().'?'.http_build_query(['section'=>'trace','lot_id'=>$row['id']]) }}">Jejak pemakaian bahan</a> · <a href="{{ url()->current().'?'.http_build_query(['section'=>'ledger','lot_id'=>$row['id']]) }}">Pergerakan lot</a>@endif
@foreach($row['forms'] as $form)<x-organisms.erp-form :form="$form" :organization="$organization" :query="$q" />@endforeach</section>@endforeach
<x-molecules.pagination :items="$items" />
@endif</x-templates.console>
