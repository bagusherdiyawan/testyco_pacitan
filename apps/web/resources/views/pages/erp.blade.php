<x-templates.console :erpContext="$context ?? null" :erpQuery="$q ?? []">
@php($finance=in_array($context['section']??$q['section']??'materials',['closings','income_statement','balance_sheet','invoice_variances','distribution_settlements','consignor_values','consignor_ledger','distribution_costs','accounts','periods','journals','trial_balance','posting','posting_events','valuations','valuation_stock','cost_ledger']))
<h1>{{ $finance?'Pembukuan dan jurnal':'Bahan baku, pembelian dan produksi' }}</h1>
@if($finance)<p>Pemetaan akun disetujui pengelola lain. Periksa antrean dan tanggal sumber sebelum menutup periode. Pembelian wholesale ditampung sebelum rekonsiliasi persediaan.</p>@else
<p>Hasil produksi masuk karantina sampai owner/admin pusat menyelesaikan pemeriksaan. Jumlah bahan menggunakan satuan yang tercantum; harga dan biaya dalam rupiah. Tidak ada resep, biaya, atau masa simpan bawaan.</p>
@endif
@if($pending || $candidate)
@php($review=$pending??$candidate)<section class="panel"><h2>{{ $pending?'Hasil belum pasti':'Tinjauan sebelum dikirim' }} · {{ $review['form']['label'] }}</h2>
<pre class="audit-snapshot">{{ $review['snapshot'] }}</pre><dl>@foreach($review['display'] as $label=>$value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach</dl>
@if($pending)<p>Jangan membuat pengganti. Ulangi permintaan yang sama sampai hasil diketahui, lalu periksa riwayat terbaru.</p><form method="post" action="/app/organizations/{{ $organization }}/erp/retry">@csrf<x-atoms.button>Ulangi permintaan yang sama</x-atoms.button></form>
@else<form method="post" action="/app/organizations/{{ $organization }}/erp/confirm">@csrf<x-atoms.button>Konfirmasi dan kirim</x-atoms.button></form><form method="post" action="/app/organizations/{{ $organization }}/erp/edit">@csrf<x-atoms.button>Kembali ke formulir</x-atoms.button></form>@endif</section>
@else
<form method="get" class="panel"><input type="hidden" name="section" value="{{ $context['section'] }}">@if(!$finance)<label>Gudang<select name="store_id">@if($context['can_manage']??false)<option value="">Semua</option>@endif
@foreach($context['filter_stores'] as $id=>$name)<option value="{{ $id }}" @selected(($q['store_id']??'')===$id)>{{ $name }}</option>@endforeach</select></label>@endif
@if(in_array($context['section'],['lots','planning']))<label>Bahan<select name="material_id"><option value="">Semua</option>@foreach($context['filter_materials'] as $id=>$name)<option value="{{ $id }}" @selected(($q['material_id']??'')===$id)>{{ $name }}</option>@endforeach</select></label>@endif
@if(in_array($context['section'],['journals','trial_balance','closings','income_statement','balance_sheet']))<label>Periode<select name="period_id"><option value="">Pilih</option>@foreach($context['filter_periods']??[] as $id=>$name)<option value="{{ $id }}" @selected(($q['period_id']??'')===$id)>{{ $name }}</option>@endforeach</select></label>@endif
@if($context['section']==='report')<label>Dari (WIB)<input type="date" name="from" value="{{ $q['from']??'' }}" required></label><label>Sampai inklusif (WIB)<input type="date" name="to" value="{{ $q['to']??'' }}" required></label>@endif
<x-atoms.button>Terapkan filter</x-atoms.button></form>
@if(isset($context['notice']))<p>{{ $context['notice'] }}</p>@endif
@foreach($context['forms'] as $form)<x-organisms.erp-form :form="$form" :organization="$organization" :query="$q" />@endforeach
@if(count($items))
<div class="panel">
<table>
    <thead>
        <tr>
            <th>Ringkasan Data</th>
            <th>Tindakan</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $row)
        <tr>
            <td>
                <pre class="audit-snapshot">{{ $row['summary'] }}</pre>
                @if($context['section']==='lots' && ($context['can_manage']??false))
                    <div class="pill-actions">
                        <a class="button button--lite" href="{{ url()->current().'?'.http_build_query(['section'=>'trace','lot_id'=>$row['id']]) }}">Jejak pemakaian bahan</a>
                        <a class="button button--lite" href="{{ url()->current().'?'.http_build_query(['section'=>'ledger','lot_id'=>$row['id']]) }}">Pergerakan lot</a>
                    </div>
                @endif
            </td>
            <td>
                @forelse($row['forms'] as $form)
                    <x-organisms.erp-form :form="$form" :organization="$organization" :query="$q" />
                @empty
                    <span>—</span>
                @endforelse
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@else
<p class="panel">Belum ada data pada bagian ini.</p>
@endif
<x-molecules.pagination :items="$items" />
@endif</x-templates.console>
