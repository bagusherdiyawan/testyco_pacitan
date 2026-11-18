@props(['module'])
<article class="module-card"><x-atoms.badge>PRD {{ $module['prd'] }}</x-atoms.badge><h2>{{ $module['name'] }}</h2><p class="status-chip {{ $module['status'] === 'available' ? 'is-available' : '' }}">{{ $module['status'] === 'available' ? 'Tersedia · fitur awal' : 'Direncanakan · belum aktif' }}</p></article>
