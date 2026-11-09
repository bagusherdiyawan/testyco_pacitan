@props(['form','organization','query'])
<details class="panel"><summary>{{ $form['label'] }}</summary>
<form method="post" action="/app/organizations/{{ $organization }}/erp/review">@csrf
<input type="hidden" name="form_key" value="{{ $form['key'] }}"><input type="hidden" name="fixed_json" value="{{ json_encode($form['fixed']) }}">
@foreach($query as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
@foreach($form['fields'] as $field)
@if(in_array($field['kind'],['quantity_map','money_map']))
<fieldset><legend>{{ $field['label'] }}</legend>@foreach($field['options'] as $id=>$label)
<label class="field">{{ $label }}<input type="text" inputmode="{{ $field['kind']==='money_map'?'numeric':'decimal' }}" name="values[{{ $field['key'] }}][{{ $id }}]" autocomplete="off"></label>
@endforeach</fieldset>
@elseif($field['kind']==='choice')<label class="field">{{ $field['label'] }}<select name="values[{{ $field['key'] }}]" @required(!$field['optional'])><option value="">Pilih</option>@foreach($field['options'] as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
@elseif($field['kind']==='consent')<label><input type="checkbox" name="values[{{ $field['key'] }}]" value="1" required>{{ $field['label'] }}</label>
@else<label class="field">{{ $field['label'] }}<input type="{{ $field['kind']==='date'?'date':($field['kind']==='integer'?'number':'text') }}" name="values[{{ $field['key'] }}]" @required(!$field['optional']) @if($field['kind']==='integer') min="0" step="1" @endif autocomplete="off"></label>@endif
@endforeach<x-atoms.button>Tinjau sebelum mengirim</x-atoms.button></form></details>
