@props(['modules'])
<section class="module-grid" aria-label="Peta modul">@foreach($modules as $module)<x-molecules.module-card :module="$module" />@endforeach</section>
