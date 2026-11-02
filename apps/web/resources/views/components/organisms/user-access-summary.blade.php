@props(['data'])
<section class="panel">
<h2>{{ $data['user']['name'] }}</h2>
<p>{{ $data['user']['email'] }} · {{ $data['user']['role'] }} · {{ $data['user']['active'] ? 'Aktif' : 'Nonaktif' }}</p>
<p>Organisasi {{ $data['organization']['name'] }} · Versi akses {{ $data['user']['access_version'] }}</p>
<p>Identitas ini tercatat pada {{ $data['user']['membership_count'] }} organisasi. Status berlaku global; penugasan di bawah hanya untuk organisasi ini.</p>
<h3>Penugasan pada organisasi ini</h3>
<ul>@forelse($data['user']['stores'] as $store)<li>{{ $store['name'] }} · {{ $store['active'] ? 'Aktif' : 'Nonaktif' }}</li>@empty<li>Tidak ada penugasan toko.</li>@endforelse</ul>
</section>
