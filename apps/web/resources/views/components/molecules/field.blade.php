@props(['name','label','type'=>'text','value'=>null,'remember'=>true])
<label class="field">{{ $label }}<input name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : ($remember ? old($name, $value) : $value) }}" {{ $attributes }}></label>
