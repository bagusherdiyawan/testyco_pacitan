<x-templates.console>
<a href="/app/organizations/{{ $organization }}">← Organisasi</a>
<h1>Kemasan dan alat makan</h1>
<p>Atur barang non-makanan yang dipakai booth dan berapa banyak yang berkurang untuk tiap jenis penjualan. Pengurangan terjadi otomatis saat pembayaran. Stok yang habis tidak menolak penjualan, hanya ditandai kurang.</p>

<form class="panel" method="post" action="/app/organizations/{{ $organization }}/consumables/items">@csrf<x-molecules.operation-key :remember="false" />
<h2>Tambah barang</h2>
<div class="form-grid">
<x-molecules.field name="name" label="Nama barang (misalnya Kotak, Sendok)" maxlength="120" :remember="false" required />
<x-molecules.field name="unit" label="Satuan (misalnya pcs)" maxlength="30" :remember="false" required />
</div>
<x-atoms.button>Simpan barang</x-atoms.button>
</form>

<section class="panel">
<h2>Daftar barang</h2>
@forelse($items as $item)
<form method="post" action="/app/organizations/{{ $organization }}/consumables/items/{{ $item['id'] }}/status" class="item-row">@csrf<x-molecules.operation-key :remember="false" />
<input type="hidden" name="version" value="{{ $item['version'] }}"><input type="hidden" name="active" value="{{ $item['active'] ? 0 : 1 }}"><input type="hidden" name="reason" value="{{ $item['active'] ? 'Dinonaktifkan pengelola' : 'Diaktifkan pengelola' }}">
<strong>{{ $item['name'] }}</strong> <span class="status-chip {{ $item['active'] ? 'is-available' : '' }}">{{ $item['active'] ? 'Aktif' : 'Nonaktif' }}</span> · {{ $item['unit'] }}
<x-atoms.button class="button--lite">{{ $item['active'] ? 'Nonaktifkan' : 'Aktifkan' }}</x-atoms.button>
</form>
@empty
<p>Belum ada barang. Tambahkan barang pertama di atas.</p>
@endforelse
</section>

<section class="panel">
<h2>Aturan pengurangan per jenis penjualan</h2>
<p>Pilih jenis penjualan, barang, dan cara menghitung. Isi jumlah 0 untuk menghapus aturan.</p>
<div class="module-grid">
@foreach($serviceTypes as $type => $label)
<article class="module-card"><h3>{{ $label }}</h3>
@php($typeRules = collect($rules)->where('service_type', $type))
@forelse($typeRules as $rule)
<p>{{ collect($items)->firstWhere('id', $rule['consumable_item_id'])['name'] ?? 'Barang dihapus' }} · {{ $rule['quantity'] }} {{ $bases[$rule['basis']] ?? $rule['basis'] }}</p>
@empty
<p>Belum ada aturan.</p>
@endforelse
</article>
@endforeach
</div>
<form method="post" action="/app/organizations/{{ $organization }}/consumables/rules">@csrf<x-molecules.operation-key :remember="false" />
<div class="form-grid">
<label>Jenis penjualan<select name="service_type" required><option value="">Pilih</option>@foreach($serviceTypes as $type => $label)<option value="{{ $type }}">{{ $label }}</option>@endforeach</select></label>
<label>Barang<select name="consumable_item_id" required><option value="">Pilih</option>@foreach($items as $item)@if($item['active'])<option value="{{ $item['id'] }}">{{ $item['name'] }} ({{ $item['unit'] }})</option>@endif @endforeach</select></label>
<label>Cara menghitung<select name="basis" required>@foreach($bases as $basis => $label)<option value="{{ $basis }}">{{ ucfirst($label) }}</option>@endforeach</select></label>
<x-molecules.field name="quantity" label="Jumlah (0 untuk menghapus)" type="number" min="0" max="1000" value="1" :remember="false" required />
</div>
<x-atoms.button>Simpan aturan</x-atoms.button>
</form>
</section>
</x-templates.console>
