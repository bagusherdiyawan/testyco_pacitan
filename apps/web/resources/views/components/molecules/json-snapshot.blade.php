@props(['label', 'value' => null])
@php
    $formatted = 'Tidak ada snapshot';
    if ($value !== null) {
        $decoded = $value;
        $valid = true;
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $valid = false;
            }
        }
        $formatted = $valid ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
    }
@endphp
<section><h3>{{ $label }}</h3><pre class="audit-snapshot">{{ $formatted }}</pre></section>
