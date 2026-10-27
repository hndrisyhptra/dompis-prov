@extends('layouts.app')
@section('title', 'Dashboard Area')
@section('content')
@php
    $scopeFilters = array_filter(['region' => request('region'), 'branch' => request('branch'), 'service' => request('service'), 'jenis_pekerjaan' => request('jenis_pekerjaan', 'all')], fn ($v) => $v !== null && $v !== '');
    $columns = \App\Support\ProgressSummary::LABELS;
    $listUrl = fn ($extra = []) => route('work-orders.index', array_replace($scopeFilters, $extra));
@endphp
<div class="page-heading area-heading">
    <div><div class="eyebrow">OLO · Jawa–Bali</div><h1>Dashboard Area</h1><p class="muted">Monitoring distribusi order, progress Branch, dan perolehan PS dalam satu tampilan.</p></div>
    <div class="row">@can('sync')<a href="{{ route('sync.index') }}" class="button secondary"><x-icon name="sync"/> Sync Center</a>@endcan<a href="{{ $listUrl() }}" class="button primary">Lihat order</a></div>
</div>
<div class="area-context"><span>{{ $today->locale('id')->translatedFormat('l, d F Y') }} · WIB</span><span>Data diterima: {{ $latest_pull ? $latest_pull->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB' : 'Belum ada data order dalam cakupan ini' }}</span></div>
<div class="stats-grid branch-kpis area-kpis dashboard-kpis">
    <a class="stat kpi-link" href="{{ $listUrl() }}"><span class="small muted">Total order</span><strong>{{ number_format($summary['total']) }}</strong><span class="small muted">Akumulasi sesuai filter</span></a>
    <a class="stat kpi-link" href="{{ $listUrl(['queue' => 'ps_today']) }}"><span class="small muted">PS hari ini</span><strong>{{ number_format($summary['ps_today']) }}</strong><span class="small muted">TGL PS · {{ $today->format('d M Y') }}</span></a>
    <a class="stat kpi-link" href="{{ $listUrl(['queue' => 'outstanding']) }}"><span class="small muted">Outstanding</span><strong>{{ number_format($summary['outstanding']) }}</strong><span class="small muted">Order yang belum ditutup</span></a>
    <a class="stat kpi-link" href="{{ $listUrl(['queue' => 'potential_ps']) }}"><span class="small muted">Potensi PS</span><strong>{{ number_format($summary['potential_ps']) }}</strong><span class="small muted">Task &amp; status BIMA sesuai kriteria</span></a>
    <a class="stat kpi-link" href="{{ $listUrl(['queue' => 'new_order']) }}"><span class="small muted">New Order</span><strong>{{ number_format($summary['new_order']) }}</strong><span class="small muted">Review Order TSQ sesuai status BIMA</span></a>
</div>
<section class="panel gap-top"><form method="GET" class="filters area-filter-form" data-loading="Memuat dashboard…">
    <input type="hidden" name="tab" value="{{ $activeTab }}">
    @if(isset($filters['ps_view']))<input type="hidden" name="ps_view" value="{{ $filters['ps_view'] }}">@endif
    <div><label for="region">Regional</label><select id="region" name="region"><option value="">Semua regional</option>@foreach($regions as $region)<option value="{{ $region->getKey() }}" @selected((string) request('region') === (string) $region->getKey())>{{ $region->name }}</option>@endforeach</select></div>
    <div><label for="branch">Branch</label><select id="branch" name="branch"><option value="">Semua Branch</option>@foreach($branches as $branch)<option value="{{ $branch->getKey() }}" @selected((string) request('branch') === (string) $branch->getKey())>{{ $branch->name }}{{ ! $branch->active || $branch->trashed() ? ' · nonaktif' : '' }}</option>@endforeach</select></div>
    <div><label for="jenis_pekerjaan">Jenis pekerjaan</label><select id="jenis_pekerjaan" name="jenis_pekerjaan"><option value="all" @selected(request('jenis_pekerjaan', 'all') === 'all')>Semua jenis</option>@foreach($types as $type)<option value="{{ $type }}" @selected(request('jenis_pekerjaan') === $type)>{{ $type }}</option>@endforeach</select></div>
    <div><label for="service">Layanan</label><select id="service" name="service"><option value="">Semua layanan</option>@foreach($services as $service)<option value="{{ $service }}" @selected(request('service') === $service)>{{ $service }}</option>@endforeach</select></div>
    @if($activeTab === 'ach-ps')<div><label for="month">Bulan grafik PS</label><input id="month" type="month" name="month" min="2000-01" max="2100-12" value="{{ $month->format('Y-m') }}" required></div>@else<input type="hidden" name="month" value="{{ $month->format('Y-m') }}">@endif
    <button class="button primary" type="submit">Terapkan</button><a class="button secondary" href="{{ route('area.dashboard', ['tab' => $activeTab]) }}">Reset</a>
</form><div class="area-filter-hint small muted">Rekap order bersifat akumulatif. {{ $activeTab === 'ach-ps' ? 'Pilihan bulan hanya mengatur grafik PS. ' : '' }}Klik angka untuk membuka daftar order terkait.</div></section>
<nav class="area-dashboard-tabs gap-top" aria-label="Tab dashboard Area">
    @foreach(['progress' => 'Progress', 'owner-group' => 'Owner Group', 'ach-ps' => 'Ach PS'] as $tab => $label)
    <a id="area-tab-{{ $tab }}" class="area-dashboard-tab {{ $activeTab === $tab ? 'active' : '' }}" href="{{ route('area.dashboard', array_replace(['month' => $month->format('Y-m')], $filters, ['tab' => $tab])) }}" @if($activeTab === $tab) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
<div id="area-dashboard-{{ $activeTab }}" role="region" aria-labelledby="area-tab-{{ $activeTab }}">
@if($activeTab === 'owner-group')
@include('owner-groups.summary', ['ownerScopeFilters' => $scopeFilters])
@elseif($activeTab === 'ach-ps')
@include('area.ps-achievement')
@else
@if($summary['open_issues'] || $summary['waiting_bima'] || $summary['missing_ps_date'] || $summary['future_ps_date'] || $summary['unmapped'])
<div class="area-attention gap-top" aria-label="Perlu perhatian">
    @if($summary['open_issues'])<a href="{{ $listUrl(['queue' => 'issues']) }}"><strong>{{ $summary['open_issues'] }}</strong><span>Order dengan kendala aktif<small>Perlu tindak lanjut Branch</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['waiting_bima'])<a href="{{ $listUrl(['queue' => 'waiting_bima']) }}"><strong>{{ $summary['waiting_bima'] }}</strong><span>CLOSE menunggu BIMA<small>Status BIMA belum memenuhi daftar PS</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['missing_ps_date'])<a href="{{ $listUrl(['queue' => 'missing_ps_date']) }}"><strong>{{ $summary['missing_ps_date'] }}</strong><span>PS tanpa tanggal<small>Belum masuk grafik harian</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['future_ps_date'])<a href="{{ $listUrl(['queue' => 'future_ps_date']) }}"><strong>{{ $summary['future_ps_date'] }}</strong><span>Periksa tanggal PS<small>Tanggal dari BIMA melewati hari ini</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['unmapped'])<a href="{{ $listUrl(['queue' => 'unmapped']) }}"><strong>{{ $summary['unmapped'] }}</strong><span>Belum terpetakan<small>Perlu penetapan Branch oleh Area</small></span><span aria-hidden="true">→</span></a>@endif
</div>
@endif
@include('area.progress-matrix')
@include('dashboard.mapping-orders', ['mappingScopeFilters' => $scopeFilters])
@include('area.open-transactions')
@include('area.mapping-aging')
@include('work-orders.order-month-summary', ['monthScopeFilters' => $scopeFilters])

@endif
</div>
@include('dashboard.order-dialog')
@endsection
