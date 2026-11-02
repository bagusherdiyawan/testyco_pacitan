<x-templates.console>
<a href="/app/organizations/{{ $organization }}">← Organisasi</a><h1>Tinjau reaktivasi akun</h1>
@if($pending !== null)
<aside>Hasil request belum pasti. Tinjauan dan request yang sama ditahan dalam session. Ulangi tanpa membuat pengganti.</aside>
<x-organisms.user-access-summary :data="$pending['review']" />
<p>Alasan yang dikirim: {{ $pending['body']['reason'] }}</p>
<form method="post" action="/app/organizations/{{ $organization }}/users/{{ $user }}/reactivation/retry">@csrf<x-atoms.button>Ulangi request yang sama</x-atoms.button></form>
@else
<x-organisms.user-access-summary :data="$data" />
@if($data['can_reactivate'])
<p>Aktivasi mengembalikan akses sesuai role dan penugasan yang tercatat. Status akun berlaku global; sesi lama tidak dipulihkan dan pengguna harus login kembali. Periksa seluruh penugasan sebelum melanjutkan.</p>
<form class="panel" method="post" action="/app/organizations/{{ $organization }}/users/{{ $user }}/reactivate">@csrf<x-molecules.operation-key :remember="false" /><input type="hidden" name="version" value="{{ $data['user']['access_version'] }}"><x-molecules.field name="reason" label="Alasan reaktivasi" maxlength="2000" :remember="false" required /><x-atoms.button>Konfirmasi reaktivasi akun</x-atoms.button></form>
@else<p>Reaktivasi tidak tersedia pada status atau hak akses ini. Periksa status akun dan organisasi.</p>@endif
@endif
</x-templates.console>
