@props(['name','label','type'=>'text','value'=>null])
<label class="field">{{ $label }}<input name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" {{ $attributes }}></label>
