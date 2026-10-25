@extends('layouts.app')
@section('title', 'Dashboard Area')
@section('content')
@php
    $scopeFilters = array_filter(['region' => request('region'), 'branch' => request('branch'), 'service' => request('service'), 'jenis_pekerjaan' => request('jenis_pekerjaan', 'all')], fn ($v) => $v !== null && $v !== '');
    $columns = \App\Support\ProgressSummary::LABELS;
    $listUrl = fn ($extra = []) => route('work-orders.index', array_replace($scopeFilters, $extra));
@endphp
<div class="page-heading area-heading">
    <div><div class="eyebrow">OLO · Jawa–Bali</div><h1>Dashboard Area</h1><p class="muted">Pantau distribusi order, progress Branch, dan perolehan PS dalam satu tampilan.</p></div>
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
@include('area.operations-handoff')
<section class="panel gap-top"><form method="GET" class="filters area-filter-form" data-loading="Memuat dashboard…">
    <div><label for="region">Regional</label><select id="region" name="region"><option value="">Semua regional</option>@foreach($regions as $region)<option value="{{ $region->getKey() }}" @selected((string) request('region') === (string) $region->getKey())>{{ $region->name }}</option>@endforeach</select></div>
    <div><label for="branch">Branch</label><select id="branch" name="branch"><option value="">Semua Branch</option>@foreach($branches as $branch)<option value="{{ $branch->getKey() }}" @selected((string) request('branch') === (string) $branch->getKey())>{{ $branch->name }}{{ ! $branch->active || $branch->trashed() ? ' · nonaktif' : '' }}</option>@endforeach</select></div>
    <div><label for="jenis_pekerjaan">Jenis pekerjaan</label><select id="jenis_pekerjaan" name="jenis_pekerjaan"><option value="all" @selected(request('jenis_pekerjaan', 'all') === 'all')>Semua jenis</option>@foreach($types as $type)<option value="{{ $type }}" @selected(request('jenis_pekerjaan') === $type)>{{ $type }}</option>@endforeach</select></div>
    <div><label for="service">Layanan</label><select id="service" name="service"><option value="">Semua layanan</option>@foreach($services as $service)<option value="{{ $service }}" @selected(request('service') === $service)>{{ $service }}</option>@endforeach</select></div>
    <div><label for="month">Bulan grafik PS</label><input id="month" type="month" name="month" min="2000-01" max="2100-12" value="{{ $month->format('Y-m') }}" required></div>
    <button class="button primary" type="submit">Terapkan</button><a class="button secondary" href="{{ route('area.dashboard') }}">Reset</a>
</form><div class="area-filter-hint small muted">Rekap order bersifat akumulatif. Pilihan bulan hanya mengatur grafik PS. Klik angka untuk membuka daftar order terkait.</div></section>
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
@include('owner-groups.summary', ['ownerScopeFilters' => $scopeFilters])
@include('work-orders.order-month-summary', ['monthScopeFilters' => $scopeFilters])
<section class="panel gap-top" aria-labelledby="type-recap-title"><div class="panel-head"><div><h2 id="type-recap-title">Rekap jenis pekerjaan</h2><p class="small muted">Volume order dan penyelesaian sesuai filter aktif</p></div><span class="badge">{{ $summary['incoming_today'] }} order hari ini</span></div>
    <div class="table-wrap" tabindex="0" aria-label="Rekap jenis pekerjaan, geser horizontal"><table class="area-type-table"><thead><tr><th scope="col" class="branch-sticky">Keterangan</th>@foreach($recap as $type => $values)<th scope="col">{{ $type }}</th>@endforeach<th scope="col">Total</th></tr></thead><tbody>
    @foreach(['total' => 'WO total', 'incoming_today' => 'WO hari ini', 'ps_total' => 'Completed total', 'ps_today' => 'Completed hari ini', 'outstanding' => 'Order open'] as $metric => $label)
        <tr><th scope="row" class="branch-sticky">{{ $label }}</th>@foreach($recap as $type => $values)<td class="numeric"><x-order-count :count="$values[$metric]" :filters="array_replace($scopeFilters, ['jenis_pekerjaan' => $type, 'queue' => match($metric) {'incoming_today' => 'today', 'ps_total' => 'complete', 'ps_today' => 'ps_today', 'outstanding' => 'outstanding', default => 'all'}])" :label="$type.' · '.$label"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$summary[$metric]" :filters="array_replace($scopeFilters, ['queue' => match($metric) {'incoming_today' => 'today', 'ps_total' => 'complete', 'ps_today' => 'ps_today', 'outstanding' => 'outstanding', default => 'all'}])" :label="'Total · '.$label"/></td></tr>
    @endforeach
    </tbody></table></div>
    <div class="area-table-note small muted">Completed / PS = progress order <strong>CLOSE</strong> dan <strong>status_order memenuhi daftar 14 kombinasi PS</strong>. Close hanya berarti instalasi lapangan selesai. CLOSE ON DESK dan status BIMA di luar daftar tidak dihitung PS.</div>
</section>
<section class="panel gap-top" aria-labelledby="area-chart-title"><div class="panel-head"><div><h2 id="area-chart-title">Perolehan PS harian</h2><p class="small muted">{{ $month->locale('id')->translatedFormat('F Y') }} · berdasarkan TGL PS BIMA</p></div><span class="badge large">{{ number_format($month_ps) }} PS</span></div><div class="panel-body">
    @if(! $month_ps)<p class="small muted area-chart-empty">Belum ada perolehan PS dalam bulan ini. Grafik akan terisi setelah kedua status dan TGL PS BIMA memenuhi ketentuan.</p>@endif
    @php
        $step = 910 / max(1, count($series) - 1);
        $points = collect($series)->filter(fn($p) => ! $p['future'])->values()->map(fn($p, $i) => (56 + $i * $step).','. (212 - $p['count'] / $chart_max * 164))->join(' ');
    @endphp
    <div class="area-chart-container"><svg class="area-line-chart" viewBox="0 0 1000 266" role="img" aria-labelledby="area-ps-chart-title area-ps-chart-desc"><title id="area-ps-chart-title">PS harian {{ $month->locale('id')->translatedFormat('F Y') }}</title><desc id="area-ps-chart-desc">{{ $month_ps }} PS pada bulan terpilih. Tanggal tanpa perolehan bernilai nol. Rincian tersedia di tabel data harian.</desc>
        @foreach(array_unique([0, (int) ceil($chart_max / 2), $chart_max]) as $tick)<line x1="56" y1="{{ 212 - $tick / $chart_max * 164 }}" x2="966" y2="{{ 212 - $tick / $chart_max * 164 }}" class="chart-grid"/><text x="42" y="{{ 216 - $tick / $chart_max * 164 }}" text-anchor="end" class="chart-label">{{ $tick }}</text>@endforeach
        @if($points)<polyline points="{{ $points }}" class="area-chart-line"/>@endif
        @foreach($series as $point)@php $x = 56 + $loop->index * $step; $y = 212 - $point['count'] / $chart_max * 164; @endphp
            @if(! $point['future'])<circle cx="{{ $x }}" cy="{{ $y }}" r="4" class="area-chart-point" role="button" tabindex="0" aria-label="{{ $point['label'] }}: {{ $point['count'] }} PS" data-chart-point data-label="{{ $point['label'] }}" data-count="{{ $point['count'] }}"><title>{{ $point['label'] }}: {{ $point['count'] }} PS</title></circle>@endif
            @if($loop->first || $loop->last || ($point['day'] % 5 === 0 && count($series) - $point['day'] >= 3))<text x="{{ $x }}" y="244" text-anchor="middle" class="chart-label">{{ $point['label'] }}</text>@endif
        @endforeach
    </svg></div><p class="small muted" data-chart-caption aria-live="polite">Pilih titik grafik untuk melihat perolehan harian. Tanggal setelah hari ini belum dihitung.</p>
    <details class="gap-top"><summary>Lihat data PS harian</summary><div class="table-wrap gap-top"><table><thead><tr><th scope="col">Tanggal PS</th><th scope="col">Jumlah PS</th></tr></thead><tbody>@foreach($series as $point)<tr><td>{{ $point['date'] }}</td><td>@if($point['future'])Belum berjalan@else<x-order-count :count="$point['count']" :filters="array_replace($scopeFilters, ['ps_date' => $point['date']])" :label="'PS · '.$point['label']"/>@endif</td></tr>@endforeach</tbody></table></div></details>
</div></section>
@include('dashboard.order-dialog')
@endsection
