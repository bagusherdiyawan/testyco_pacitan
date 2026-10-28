@props(['remember'=>true])
<input type="hidden" name="operation_id" value="{{ ($remember ? old('operation_id') : null) ?? (string) Illuminate\Support\Str::uuid() }}">
