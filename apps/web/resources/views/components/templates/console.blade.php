@php
    $content = (string) $slot;
    $title = preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $content, $match) ? trim(strip_tags(html_entity_decode($match[1]))) : 'Workspace';
    $nav = [
        ['/app', 'Beranda', 'ni-shop', request()->is('app')],
        ['/app/supply/orders', 'Pesanan pusat', 'ni-box-2', request()->is('app/supply/orders*')],
        ['/app/inbox', 'Inbox', 'ni-email-83', request()->is('app/inbox*')],
        ['/app/account', 'Kata sandi', 'ni-key-25', request()->is('app/account*')],
    ];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="theme-color" content="#a21f16">
<title>Testyco · Workspace</title>
<link rel="stylesheet" href="/soft-ui/css/nucleo-icons.css">
<link rel="stylesheet" href="/soft-ui/css/nucleo-svg.css">
<link rel="stylesheet" href="/soft-ui/css/soft-ui-dashboard.min.css">
<link rel="stylesheet" href="/tokens.css">
<link rel="stylesheet" href="/soft-brand.css">
<link rel="stylesheet" href="/console.css">
</head>
<body class="g-sidenav-show bg-gray-100">
<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3" id="sidenav-main" aria-label="Menu utama">
    <div class="sidenav-header">
        <button type="button" class="sidenav-close d-xl-none" id="iconSidenav" aria-label="Tutup menu">&times;</button>
        <a class="navbar-brand m-0 d-flex align-items-center" href="/app">
            <span class="brand-mark" aria-hidden="true">T</span>
            <span class="ms-2 brand-name">Testyco · Operasional</span>
        </a>
    </div>
    <hr class="horizontal dark mt-0">
    <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
        <ul class="navbar-nav">
            @foreach($nav as [$href, $label, $icon, $active])
                <li class="nav-item">
                    <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $href }}" @if($active) aria-current="page" @endif>
                        <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni {{ $icon }} text-sm opacity-10" aria-hidden="true"></i>
                        </div>
                        <span class="nav-link-text ms-1">{{ $label }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
    <div class="sidenav-footer mx-3 mt-auto">
        <div class="sidenav-footer-card">
            <p>Pusat, mitra dan booth dalam satu tempat.</p>
            <a class="btn btn-sm bg-white text-dark mb-0" href="/app/inbox">Buka inbox</a>
        </div>
    </div>
</aside>

<main class="main-content position-relative max-height-vh-100 h-100 mt-1 border-radius-lg">
    <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" aria-label="Bilah atas">
        <div class="container-fluid py-1 px-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                    <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="/app">Testyco</a></li>
                    <li class="breadcrumb-item text-sm active" aria-current="page">{{ $title }}</li>
                </ol>
            </nav>
            <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                <ul class="navbar-nav ms-md-auto justify-content-end align-items-center flex-row gap-2">
                    <li class="nav-item"><span class="user-chip">{{ session('api_user.name') }}</span></li>
                    <li class="nav-item">
                        <form method="post" action="/logout">@csrf<button type="submit" class="btn btn-logout">Keluar</button></form>
                    </li>
                </ul>
            </div>
            <ul class="navbar-nav justify-content-end">
                <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
                    <a href="#" class="nav-link text-body p-0" id="iconNavbarSidenav" aria-label="Buka menu">
                        <div class="sidenav-toggler-inner"><i class="sidenav-toggler-line"></i><i class="sidenav-toggler-line"></i><i class="sidenav-toggler-line"></i></div>
                    </a>
                </li>
            </ul>
        </div>
    </nav>
    <div class="container-fluid py-3 console-content">
        <x-organisms.feedback />
        {!! $content !!}
        <footer class="console-footer no-print">Testyco Pacitan · jaringan mitra, toko dan POS dimsum</footer>
    </div>
</main>

<script src="/soft-ui/js/bootstrap.bundle.min.js"></script>
<script src="/soft-ui/js/perfect-scrollbar.min.js"></script>
<script src="/soft-ui/js/smooth-scrollbar.min.js"></script>
<script src="/soft-ui/js/chartjs.min.js"></script>
@stack('scripts')
<script src="/soft-ui/js/soft-ui-dashboard.min.js"></script>
</body>
</html>
