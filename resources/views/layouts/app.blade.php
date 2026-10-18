<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Monitoring') · OLO Command Center</title>
    <script>try{document.documentElement.classList.toggle('dark',(localStorage.getItem('olo-theme')|| (matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'))==='dark')}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@auth
    <div class="mobile-bar"><button class="button secondary" id="menu-toggle" aria-controls="sidebar" aria-expanded="false">Menu</button><strong>OLO Command Center</strong></div>
    <button class="drawer-backdrop" aria-label="Tutup menu" hidden></button>
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route(auth()->user()->role->areaScope() ? 'area.dashboard' : 'branch.dashboard') }}"><span class="brand-mark">OLO</span><span>Command Center<small>AREA JAWA–BALI</small></span></a>
        <div class="nav-label">Operasional</div>
        <nav aria-label="Navigasi utama">
            @if(auth()->user()->role->areaScope())
            <a class="nav-link {{ request()->routeIs('area.dashboard') ? 'active' : '' }}" href="{{ route('area.dashboard') }}"><x-icon name="dashboard"/> Dashboard Area</a>
            @else
            <a class="nav-link {{ request()->routeIs('branch.dashboard') ? 'active' : '' }}" href="{{ route('branch.dashboard') }}"><x-icon name="dashboard"/> Dashboard Branch</a>
            @endif
            <a class="nav-link {{ request()->routeIs('work-orders.*') ? 'active' : '' }}" href="{{ route('work-orders.index') }}"><x-icon name="orders"/> {{ auth()->user()->role->areaScope() ? 'Work Orders' : 'Order Cabang' }}</a>
            @if(! auth()->user()->role->areaScope())<a class="nav-link {{ request()->routeIs('branch.material') ? 'active' : '' }}" href="{{ route('branch.material') }}"><x-icon name="material"/> Laporan Material</a>@endif
            @can('sync')
            <a class="nav-link {{ request()->routeIs('sync.*') ? 'active' : '' }}" href="{{ route('sync.index') }}"><x-icon name="sync"/> Sync Center</a>
            <a class="nav-link {{ request()->routeIs('mappings.*') ? 'active' : '' }}" href="{{ route('mappings.index') }}"><x-icon name="branches"/> Branch & Mapping</a>
            @endcan
            @can('administer')
            <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}"><x-icon name="users"/> User Management</a>
            @endcan
        </nav>
        <div class="sidebar-foot"><div class="small muted">{{ auth()->user()->role->areaScope() ? 'Operasional Area' : 'Ruang kerja cabang' }}</div><strong>{{ auth()->user()->name }}</strong><div class="small muted">{{ str_replace('_', ' ', auth()->user()->role->value) }}</div><div class="row gap-top"><form method="POST" action="{{ route('logout') }}">@csrf<button class="button secondary">Keluar</button></form></div></div>
    </aside>
@endauth
<main class="{{ auth()->check() ? 'main' : 'guest-main' }}">
    @auth<div class="topbar"><span>{{ auth()->user()->role->areaScope() ? 'Operasional Area' : 'Operasional Branch' }} <span class="muted">/ @yield('title', 'Monitoring')</span></span><div class="topbar-actions"><span class="small muted">{{ now()->timezone('Asia/Jakarta')->translatedFormat('d M Y') }} · WIB</span><button type="button" class="button secondary icon-button" data-theme-toggle aria-label="Aktifkan mode gelap" title="Aktifkan mode gelap"><span class="theme-moon"><x-icon name="moon"/></span><span class="theme-sun"><x-icon name="sun"/></span></button></div></div>@endauth
    <div class="page">
        @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice danger" role="alert"><strong>Periksa kembali input.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </div>
</main>
</body>
</html>
