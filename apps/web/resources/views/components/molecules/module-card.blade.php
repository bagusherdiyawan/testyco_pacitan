@props(['module'])
<article class="module-card"><x-atoms.badge>PRD {{ $module['prd'] }}</x-atoms.badge><h2>{{ $module['name'] }}</h2><p>{{ $module['status'] === 'available' ? 'Tersedia · fitur awal' : 'Direncanakan · belum aktif' }}</p></article>
