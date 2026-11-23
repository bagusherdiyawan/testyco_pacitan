@props(['store' => null, 'organizationInfo' => null, 'erpContext' => null, 'erpQuery' => []])
@php
    $content = (string) $slot;
    $title = preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $content, $match) ? trim(strip_tags(html_entity_decode($match[1]))) : 'Workspace';
    $nav = [
        ['/app', 'Beranda', 'ni-shop', request()->is('app')],
        ['/app/supply/orders', 'Pesanan pusat', 'ni-box-2', request()->is('app/supply/orders*')],
        ['/app/inbox', 'Inbox', 'ni-email-83', request()->is('app/inbox*')],
        ['/app/account', 'Kata sandi', 'ni-key-25', request()->is('app/account*')],
    ];
    $routeOrg = request()->route('organization');
    $routeStore = request()->route('store');
    $isCenter = ($organizationInfo['type'] ?? null) === 'center';
    $showAssets = (bool) config('console.show_assets', false);
    $showCommercial = (bool) config('console.show_commercial', false);
    $showAccounting = (bool) config('console.show_advanced_accounting', false);
    $showRecalls = (bool) config('console.show_coordinated_recalls', false);
    $showManufacturing = (bool) config('console.show_manufacturing', true);
    $orgNav = is_string($routeOrg) ? array_values(array_filter([
        ["/app/organizations/$routeOrg", 'Ringkasan & Staf', 'ni-building', request()->is("app/organizations/$routeOrg")],
        ["/app/organizations/$routeOrg/catalog", 'Katalog & Harga', 'ni-tag', request()->is("app/organizations/$routeOrg/catalog*")],
        ["/app/organizations/$routeOrg/consumables", 'Kemasan & Alat Makan', 'ni-bag-17', request()->is("app/organizations/$routeOrg/consumables*")],
        ($showManufacturing && (! isset($organizationInfo) || $isCenter)) ? ["/app/organizations/$routeOrg/erp", 'Bahan Baku & Produksi', 'ni-settings-gear-65', request()->is("app/organizations/$routeOrg/erp") && request()->query('section') !== 'posting'] : null,
        $showAccounting ? ["/app/organizations/$routeOrg/erp?section=posting", 'Pembukuan & Jurnal', 'ni-books', request()->is("app/organizations/$routeOrg/erp") && request()->query('section') === 'posting'] : null,
        ["/app/organizations/$routeOrg/reports/cash-sales", 'Laporan Penjualan', 'ni-chart-bar-32', request()->is("app/organizations/$routeOrg/reports/cash-sales*")],
        ["/app/organizations/$routeOrg/reports/commercial", 'Laporan Laba, Piutang & HPP', 'ni-money-coins', request()->is("app/organizations/$routeOrg/reports/commercial*")],
        ["/app/organizations/$routeOrg/vouchers", 'Voucher Promo', 'ni-badge', request()->is("app/organizations/$routeOrg/vouchers*")],
        ["/app/organizations/$routeOrg/contracts", 'Kontrak Kemitraan', 'ni-paper-diploma', request()->is("app/organizations/$routeOrg/contracts*")],
        $showCommercial ? ["/app/organizations/$routeOrg/commercial-agreements", 'Konsinyasi & Sponsor', 'ni-briefcase-24', request()->is("app/organizations/$routeOrg/commercial-agreements*")] : null,
        $showAssets ? ["/app/organizations/$routeOrg/assets", 'Aset & Freezer', 'ni-tv-2', request()->is("app/organizations/$routeOrg/assets*")] : null,
        ["/app/organizations/$routeOrg/food-safety", 'SOP & Kedaluwarsa', 'ni-shield-check', request()->is("app/organizations/$routeOrg/food-safety*")],
        $showRecalls ? ["/app/organizations/$routeOrg/recalls", 'Recall Terkoordinasi', 'ni-bell-55', request()->is("app/organizations/$routeOrg/recalls*")] : null,
        ["/app/organizations/$routeOrg/audit", 'Jejak Audit', 'ni-bullet-list-67', request()->is("app/organizations/$routeOrg/audit*")],
    ])) : [];
    $erpNav = [];
    if (is_string($routeOrg) && is_array($erpContext)) {
        $allowed = array_flip($erpContext['allowed_sections'] ?? array_keys(\App\Modules\Manufacturing\Presentation\ErpController::SECTIONS));
        $currentSection = $erpContext['section'] ?? ($erpQuery['section'] ?? 'materials');
        $inProduction = in_array($currentSection, \App\Modules\Manufacturing\Presentation\ErpController::PRODUCTION_SECTIONS, true);
        $sectionsToShow = ($showAccounting || ! $inProduction)
            ? \App\Modules\Manufacturing\Presentation\ErpController::SECTIONS
            : array_intersect_key(\App\Modules\Manufacturing\Presentation\ErpController::SECTIONS, array_flip(\App\Modules\Manufacturing\Presentation\ErpController::PRODUCTION_SECTIONS));
        foreach (array_intersect_key($sectionsToShow, $allowed) as $key => $label) {
            $href = url("/app/organizations/$routeOrg/erp").'?'.http_build_query(['section' => $key, ...(($erpContext['can_manage'] ?? false) ? [] : ['store_id' => $erpQuery['store_id'] ?? ''])]);
            $erpNav[] = [$href, $label, 'ni-bullet-list-67', $key === $currentSection];
        }
    }
    $storeNav = is_string($routeStore) ? array_values(array_filter([
        ["/app/stores/$routeStore/operations", 'Ringkasan Toko', 'ni-shop', request()->is("app/stores/$routeStore/operations")],
        (! isset($store) || ($store['can_pos'] ?? false)) ? ["/app/stores/$routeStore/pos", 'POS & Shift Kas', 'ni-credit-card', request()->is("app/stores/$routeStore/pos*")] : null,
        (! isset($store) || ($store['can_manage_stock'] ?? false) || ($store['can_process_stock'] ?? false)) ? ["/app/stores/$routeStore/stock-control", 'Buka, Kukus & Opname', 'ni-settings', request()->is("app/stores/$routeStore/stock-control*")] : null,
        (! isset($store) || (($store['can_order'] ?? false) && ($store['active'] ?? true))) ? ["/app/stores/$routeStore/orders/new", 'Buat Pengajuan Stok', 'ni-cart', request()->is("app/stores/$routeStore/orders/new")] : null,
        (! isset($store) || ($store['active'] ?? true)) ? ["/app/stores/$routeStore/consumables", 'Kemasan & Alat Makan', 'ni-bag-17', request()->is("app/stores/$routeStore/consumables*")] : null,
        (! isset($store) || ($store['active'] ?? true)) ? ["/app/stores/$routeStore/stock-minimums", 'Stok Minimum', 'ni-bell-55', request()->is("app/stores/$routeStore/stock-minimums*")] : null,
        (! isset($store) || ($store['can_manage_stock'] ?? false)) ? ["/app/stores/$routeStore/inventory/ledger", 'Riwayat Stok', 'ni-collection', request()->is("app/stores/$routeStore/inventory/ledger*")] : null,
        ["/app/stores/$routeStore/inventory/batch-expiry", 'Kedaluwarsa Batch', 'ni-calendar-grid-58', request()->is("app/stores/$routeStore/inventory/batch-expiry*")],
        (isset($store['organization_id']) && in_array($store['role'] ?? '', ['owner', 'admin', 'warehouse', 'store_manager'], true)) ? ["/app/organizations/{$store['organization_id']}/erp?section=productions&store_id=$routeStore", 'Produksi ERP Gudang', 'ni-settings-gear-65', false] : null,
    ])) : [];
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
<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3 bg-white" id="sidenav-main" aria-label="Menu utama">
    <div class="sidenav-header">
        <button type="button" class="sidenav-close d-xl-none" id="iconSidenav" aria-label="Tutup menu">&times;</button>
        <a class="navbar-brand m-0 d-flex align-items-center" href="/app">
            <span class="brand-mark" aria-hidden="true">T</span>
            <span class="ms-2 brand-name">Testyco · Operasional</span>
        </a>
    </div>
    <hr class="horizontal dark mt-0 mb-2">
    <div class="collapse navbar-collapse w-auto h-auto" id="sidenav-collapse-main">
        <ul class="navbar-nav">
            <li class="nav-item mt-1"><span class="sidenav-section">Menu Utama</span></li>
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
            @if($orgNav)
                <li class="nav-item mt-3"><span class="sidenav-section">Kelola Organisasi</span></li>
                @foreach($orgNav as [$href, $label, $icon, $active])
                    <li class="nav-item">
                        <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $href }}" @if($active) aria-current="page" @endif>
                            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                                <i class="ni {{ $icon }} text-sm opacity-10" aria-hidden="true"></i>
                            </div>
                            <span class="nav-link-text ms-1">{{ $label }}</span>
                        </a>
                    </li>
                @endforeach
            @endif
            @if($erpNav)
                <li class="nav-item mt-3"><span class="sidenav-section">Bagian ERP &amp; Jurnal</span></li>
                @foreach($erpNav as [$href, $label, $icon, $active])
                    <li class="nav-item">
                        <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $href }}" @if($active) aria-current="page" @endif>
                            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                                <i class="ni {{ $icon }} text-sm opacity-10" aria-hidden="true"></i>
                            </div>
                            <span class="nav-link-text ms-1">{{ $label }}</span>
                        </a>
                    </li>
                @endforeach
            @endif
            @if($storeNav)
                <li class="nav-item mt-3"><span class="sidenav-section">Operasional Toko</span></li>
                @foreach($storeNav as [$href, $label, $icon, $active])
                    <li class="nav-item">
                        <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $href }}" @if($active) aria-current="page" @endif>
                            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                                <i class="ni {{ $icon }} text-sm opacity-10" aria-hidden="true"></i>
                            </div>
                            <span class="nav-link-text ms-1">{{ $label }}</span>
                        </a>
                    </li>
                @endforeach
            @endif
        </ul>
    </div>
</aside>

<main class="main-content position-relative max-height-vh-100 h-100 mt-1 border-radius-lg">
    <nav class="navbar navbar-main navbar-expand-lg px-0 mx-3 mx-md-4 shadow-none border-radius-xl" id="navbarBlur" aria-label="Bilah atas">
        <div class="container-fluid py-2 px-2 px-md-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <a href="#" class="nav-link text-body p-2 d-xl-none d-inline-flex align-items-center justify-content-center nav-drawer-btn" id="iconNavbarSidenav" aria-label="Buka menu">
                    <div class="sidenav-toggler-inner"><i class="sidenav-toggler-line"></i><i class="sidenav-toggler-line"></i><i class="sidenav-toggler-line"></i></div>
                </a>
                <nav aria-label="breadcrumb" class="min-w-0">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-0 px-0 flex-nowrap">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="/app">Testyco</a></li>
                        <li class="breadcrumb-item text-sm active" aria-current="page">{{ $title }}</li>
                    </ol>
                </nav>
            </div>
            <ul class="navbar-nav ms-auto justify-content-end align-items-center flex-row gap-2">
                <li class="nav-item"><span class="user-chip">{{ session('api_user.name') }}</span></li>
                <li class="nav-item">
                    <form method="post" action="/logout">@csrf<button type="submit" class="btn btn-logout">Keluar</button></form>
                </li>
            </ul>
        </div>
    </nav>
    <div class="container-fluid py-3 px-3 px-md-4 console-content">
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
