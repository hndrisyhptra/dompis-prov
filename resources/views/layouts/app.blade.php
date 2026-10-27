<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Monitoring') · Wibsy Area Jawa Bali</title>
    <script>try{document.documentElement.classList.toggle('dark',(localStorage.getItem('olo-theme')|| (matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'))==='dark')}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@auth
    <div class="mobile-bar"><button class="button secondary" id="menu-toggle" aria-controls="sidebar" aria-expanded="false">Menu</button><div><strong>Wibsy</strong><div class="small muted">Area Jawa Bali</div></div></div>
    <button class="drawer-backdrop" aria-label="Tutup menu" hidden></button>
    <aside class="sidebar" id="sidebar">
        <a class="brand" aria-label="Wibsy Area Jawa Bali" href="{{ route(auth()->user()->role->areaScope() ? 'area.dashboard' : 'branch.dashboard') }}"><span class="brand-mark">W</span><span>Wibsy<small>Area Jawa Bali</small></span></a>
        <div class="nav-label">Operasional</div>
        <nav aria-label="Navigasi utama">
            @if(auth()->user()->role->areaScope())
            <a class="nav-link {{ request()->routeIs('area.dashboard') ? 'active' : '' }}" href="{{ route('area.dashboard') }}"><x-icon name="dashboard"/> Dashboard Area</a>
            @else
            <a class="nav-link {{ request()->routeIs('branch.dashboard') ? 'active' : '' }}" href="{{ route('branch.dashboard') }}"><x-icon name="dashboard"/> Dashboard Branch</a>
            @endif
            <a class="nav-link {{ request()->routeIs('work-orders.*', 'boqs.*') ? 'active' : '' }}" href="{{ route('work-orders.index') }}"><x-icon name="orders"/> {{ auth()->user()->role->areaScope() ? 'Work Orders' : 'Work Order' }}</a>
            @can('revenue-overview')
            <a class="nav-link {{ request()->routeIs('revenue.*') ? 'active' : '' }}" href="{{ route('revenue.index') }}"><x-icon name="revenue"/> Revenue Overview</a>
            @endcan
            @can('priority-orders')
            <details class="nav-master" @if(request()->routeIs('priorities.*')) open @endif>
                <summary class="nav-link {{ request()->routeIs('priorities.*') ? 'active' : '' }}"><x-icon name="bookmark"/><span class="nav-master-label">Order Prioritas</span><span class="nav-master-chevron" aria-hidden="true">⌄</span></summary>
                <div class="nav-submenu" aria-label="Submenu Order Prioritas">
                    @foreach(['priorities.monitoring'=>'Monitoring Order Prioritas', 'priorities.index'=>'List Order Prioritas'] as $route => $label)
                    <a class="nav-sublink {{ request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </details>
            @endcan
            @can('material-reports')<a class="nav-link {{ request()->routeIs('materials.*', 'branch.material') ? 'active' : '' }}" href="{{ route('materials.index') }}"><x-icon name="material"/> Laporan Material</a>@endcan
            @can('sync')
            <a class="nav-link {{ request()->routeIs('sync.*', 'imports.*') ? 'active' : '' }}" href="{{ route('sync.index') }}"><x-icon name="sync"/> Sync Center</a>
            @endcan
            @can('manage-master-data')
            <details class="nav-master" @if(request()->routeIs('master-data.*')) open @endif>
                <summary class="nav-link {{ request()->routeIs('master-data.*') ? 'active' : '' }}"><x-icon name="branches"/><span class="nav-master-label">Master Data</span><span class="nav-master-chevron" aria-hidden="true">⌄</span></summary>
                <div class="nav-submenu" aria-label="Submenu Master Data">
                    @foreach(['regions'=>'Region', 'branches'=>'Branch', 'service-areas'=>'Service Area', 'mapping-orders'=>'Mapping Order', 'mapping-owner-groups'=>'Mapping Owner Grup'] as $submenu => $label)
                        <a class="nav-sublink {{ request()->route('masterType') === $submenu ? 'active' : '' }}" href="{{ route('master-data.index', $submenu) }}" @if(request()->route('masterType') === $submenu) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </details>
            @endcan
            @can('administer')
            <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}"><x-icon name="users"/> User Management</a>
            @endcan
        </nav>
        <div class="sidebar-foot"><div class="small muted">{{ auth()->user()->role->areaScope() ? 'Operasional Area' : 'Ruang kerja Branch' }}</div><strong>{{ auth()->user()->name }}</strong><div class="small muted">{{ str_replace('_', ' ', auth()->user()->role->value) }}</div></div>
    </aside>
@endauth
<main class="{{ auth()->check() ? 'main' : 'guest-main' }}">
    @auth<div class="topbar"><span>{{ auth()->user()->role->areaScope() ? 'Operasional Area' : 'Operasional Branch' }} <span class="muted">/ @yield('title', 'Monitoring')</span></span><div class="topbar-actions"><span class="small muted topbar-date">{{ now()->timezone('Asia/Jakarta')->translatedFormat('d M Y') }} · WIB</span><button type="button" class="button secondary icon-button" data-theme-toggle aria-label="Aktifkan mode gelap" title="Aktifkan mode gelap"><span class="theme-moon"><x-icon name="moon"/></span><span class="theme-sun"><x-icon name="sun"/></span></button><a class="button secondary logout-trigger" href="{{ route('logout.confirm') }}" data-logout-open aria-haspopup="dialog" aria-controls="logout-confirmation" title="Logout"><x-icon name="log-out"/><span>Logout</span></a></div></div>@endauth
    <div class="page">
        @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice danger" role="alert"><strong>Periksa kembali input.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </div>
</main>
@auth
<dialog id="logout-confirmation" class="logout-dialog" aria-labelledby="logout-title" aria-describedby="logout-description">
    <div class="logout-dialog-heading"><x-icon name="log-out"/><h2 id="logout-title">Konfirmasi Logout</h2></div>
    <p id="logout-description" class="muted">Apakah Anda yakin ingin Logout?</p>
    <form method="POST" action="{{ route('logout') }}" data-loading="Logout…" data-logout-confirm>
        @csrf
        <div class="logout-dialog-actions"><button class="button secondary" type="button" data-logout-cancel autofocus>Tidak</button><button class="button primary" type="submit"><x-icon name="log-out"/>Ya, Logout</button></div>
    </form>
</dialog>
<dialog id="data-confirmation" class="logout-dialog" aria-labelledby="data-confirm-title" aria-describedby="data-confirm-description">
    <div class="logout-dialog-heading"><x-icon name="alert"/><h2 id="data-confirm-title" data-confirm-title>Konfirmasi simpan data</h2></div>
    <p id="data-confirm-description" class="muted" data-confirm-description>Apakah Anda yakin ingin menyimpan data ini?</p>
    <p class="small muted" data-confirm-context></p>
    <div class="logout-dialog-actions"><button class="button secondary" type="button" data-confirm-no autofocus>Tidak</button><button class="button primary" type="button" data-confirm-yes>Ya, Simpan</button></div>
</dialog>
@endauth
</body>
</html>
