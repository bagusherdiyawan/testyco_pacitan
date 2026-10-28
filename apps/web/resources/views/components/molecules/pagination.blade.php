@props(['items'])
@if($items->hasPages())<nav class="topnav" aria-label="Halaman daftar">@if($items->previousPageUrl())<a href="{{ $items->previousPageUrl() }}">← Sebelumnya</a>@endif<span>Halaman {{ $items->currentPage() }} dari {{ $items->lastPage() }}</span>@if($items->nextPageUrl())<a href="{{ $items->nextPageUrl() }}">Berikutnya →</a>@endif</nav>@endif
