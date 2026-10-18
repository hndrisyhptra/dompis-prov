@extends('layouts.app')
@section('title', 'Dashboard Area')
@section('content')
@php
    $scopeFilters = array_filter(['region' => request('region'), 'branch' => request('branch'), 'service' => request('service'), 'job_type' => request('job_type', 'all')], fn ($v) => $v !== null && $v !== '');
    $columns = \App\Support\ProgressSummary::LABELS;
    $listUrl = fn ($extra = []) => route('work-orders.index', array_replace($scopeFilters, $extra));
@endphp
<div class="page-heading area-heading">
    <div><div class="eyebrow">OLO · Jawa–Bali</div><h1>Dashboard Area</h1><p class="muted">Pantau distribusi order, progress cabang, dan perolehan PS dalam satu tampilan.</p></div>
    <div class="row">@can('sync')<a href="{{ route('sync.index') }}" class="button secondary"><x-icon name="sync"/> Sync Center</a>@endcan<a href="{{ $listUrl() }}" class="button primary">Lihat order</a></div>
</div>
<div class="area-context"><span>{{ $today->locale('id')->translatedFormat('l, d F Y') }} · WIB</span><span>Data diterima: {{ $latest_pull ? $latest_pull->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB' : 'Belum ada hasil pull dalam cakupan ini' }}</span></div>
<div class="stats-grid branch-kpis area-kpis">
    <a class="stat kpi-link" href="{{ $listUrl() }}"><span class="small muted">Total order</span><strong>{{ number_format($summary['total']) }}</strong><span class="small muted">Akumulasi sesuai filter</span></a>
    <a class="stat kpi-link" href="{{ $listUrl(['queue' => 'complete']) }}"><span class="small muted">PS terkonfirmasi</span><strong>{{ number_format($summary['ps_total']) }}</strong><span class="small muted">{{ $completion_rate }}% dari total order</span></a>
    <a class="stat kpi-link" href="{{ $listUrl(['queue' => 'ps_today']) }}"><span class="small muted">PS hari ini</span><strong>{{ number_format($summary['ps_today']) }}</strong><span class="small muted">TGL PS · {{ $today->format('d M Y') }}</span></a>
    <a class="stat kpi-link" href="{{ $listUrl(['queue' => 'outstanding']) }}"><span class="small muted">Outstanding</span><strong>{{ number_format($summary['outstanding']) }}</strong><span class="small muted">Order yang belum ditutup</span></a>
</div>
<section class="panel gap-top"><form method="GET" class="filters area-filter-form" data-loading="Memuat dashboard…">
    <div><label for="region">Regional</label><select id="region" name="region"><option value="">Semua regional</option>@foreach($regions as $region)<option value="{{ $region->getKey() }}" @selected((string) request('region') === (string) $region->getKey())>{{ $region->name }}</option>@endforeach</select></div>
    <div><label for="branch">District / cabang</label><select id="branch" name="branch"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->getKey() }}" @selected((string) request('branch') === (string) $branch->getKey())>{{ $branch->name }}{{ ! $branch->active || $branch->trashed() ? ' · nonaktif' : '' }}</option>@endforeach</select></div>
    <div><label for="job_type">Jenis pekerjaan</label><select id="job_type" name="job_type"><option value="all" @selected(request('job_type', 'all') === 'all')>Semua jenis</option>@foreach($types as $type)<option value="{{ $type }}" @selected(request('job_type') === $type)>{{ $type }}</option>@endforeach</select></div>
    <div><label for="service">Layanan</label><select id="service" name="service"><option value="">Semua layanan</option>@foreach($services as $service)<option value="{{ $service }}" @selected(request('service') === $service)>{{ $service }}</option>@endforeach</select></div>
    <div><label for="month">Bulan grafik PS</label><input id="month" type="month" name="month" min="2000-01" max="2100-12" value="{{ $month->format('Y-m') }}" required></div>
    <button class="button primary" type="submit">Terapkan</button><a class="button secondary" href="{{ route('area.dashboard') }}">Reset</a>
</form><div class="area-filter-hint small muted">Rekap order bersifat akumulatif. Pilihan bulan hanya mengatur grafik PS. Klik angka untuk membuka daftar order terkait.</div></section>
@if($summary['open_issues'] || $summary['waiting_bima'] || $summary['missing_ps_date'] || $summary['future_ps_date'] || $summary['unmapped'])
<div class="area-attention gap-top" aria-label="Perlu perhatian">
    @if($summary['open_issues'])<a href="{{ $listUrl(['queue' => 'issues']) }}"><strong>{{ $summary['open_issues'] }}</strong><span>Order dengan kendala aktif<small>Perlu tindak lanjut cabang</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['waiting_bima'])<a href="{{ $listUrl(['queue' => 'waiting_bima']) }}"><strong>{{ $summary['waiting_bima'] }}</strong><span>CLOSE menunggu BIMA<small>Status BIMA belum CLOSE/COMPLETE</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['missing_ps_date'])<a href="{{ $listUrl(['queue' => 'missing_ps_date']) }}"><strong>{{ $summary['missing_ps_date'] }}</strong><span>PS tanpa tanggal<small>Belum masuk grafik harian</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['future_ps_date'])<a href="{{ $listUrl(['queue' => 'future_ps_date']) }}"><strong>{{ $summary['future_ps_date'] }}</strong><span>Periksa tanggal PS<small>Tanggal dari BIMA melewati hari ini</small></span><span aria-hidden="true">→</span></a>@endif
    @if($summary['unmapped'])<a href="{{ $listUrl(['queue' => 'unmapped']) }}"><strong>{{ $summary['unmapped'] }}</strong><span>Belum terpetakan<small>Perlu penetapan cabang oleh Area</small></span><span aria-hidden="true">→</span></a>@endif
</div>
@endif
<section class="panel gap-top" aria-labelledby="progress-summary-title">
    <div class="panel-head"><div><h2 id="progress-summary-title">Progress regional & cabang</h2><p class="small muted">Jawa–Bali · Distribusi kategori progress operasional</p></div><span class="badge">{{ count($groups) }} regional · {{ $branch_count }} cabang sesuai filter</span></div>
    @if(! $summary['total'])<div class="notice area-empty-notice">Belum ada order dalam filter ini. Rekap menampilkan angka nol; periksa filter atau hasil pull BIMA_RAW.</div>@endif
    <div class="table-wrap area-matrix-wrap" tabindex="0" aria-label="Rekap progress cabang, geser horizontal untuk melihat semua kolom"><table class="area-matrix"><caption class="sr-only">Kategori progress per cabang, subtotal regional dan grand total Jawa–Bali</caption>
        <thead><tr><th rowspan="2" scope="col" class="branch-sticky">Regional / district</th><th colspan="6" scope="colgroup">Dalam proses</th><th colspan="2" scope="colgroup">Penutupan</th><th rowspan="2" scope="col">PS hari ini</th><th rowspan="2" scope="col">Total order</th></tr><tr>@foreach(array_slice($columns, 0, 8, true) as $key => $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($groups as $group)
            <tr class="region-band"><th colspan="11" scope="rowgroup">{{ $group['name'] }} <span class="small muted">· {{ count($group['rows']) }} cabang</span></th></tr>
            @foreach($group['rows'] as $row)
            <tr><th scope="row" class="branch-sticky">{{ $row['name'] }} @if($row['inactive'])<span class="small muted">· nonaktif</span>@endif</th>
                @foreach($columns as $key => $label)@php
                    $extra = $row['branch_id'] ? ['branch' => $row['branch_id']] : ['queue' => 'unmapped'];
                    if ($key === 'ps_today') $extra['queue'] = 'ps_today';
                    elseif ($key !== 'total') $extra['bucket'] = $key;
                @endphp
                <td class="numeric {{ $key === 'ps_today' ? 'ps-cell' : '' }} {{ $key === 'total' ? 'total-cell' : '' }}">@if($row['counts'][$key] && $row['branch_id'])<a href="{{ $listUrl($extra) }}" aria-label="{{ $row['name'] }} · {{ $label }} · {{ $row['counts'][$key] }} order">{{ number_format($row['counts'][$key]) }}</a>@else<span class="{{ $row['counts'][$key] ? '' : 'zero-count' }}">{{ number_format($row['counts'][$key]) }}</span>@endif</td>
                @endforeach
            </tr>
            @endforeach
            <tr class="regional-total"><th scope="row" class="branch-sticky">Total {{ $group['name'] }}</th>@foreach($columns as $key => $label)<td class="numeric">{{ number_format($group['totals'][$key]) }}</td>@endforeach</tr>
        @empty<tr><td colspan="11"><div class="empty"><h3>Tidak ada cabang sesuai filter</h3><p>Ubah pilihan regional atau cabang untuk melihat rekap.</p></div></td></tr>@endforelse
        </tbody><tfoot><tr><th scope="row" class="branch-sticky">Grand total Jawa–Bali</th>@foreach($columns as $key => $label)<td class="numeric">{{ number_format($grand[$key]) }}</td>@endforeach</tr></tfoot>
    </table></div>
    <div class="area-table-note small muted"><strong>Proses BIMA:</strong> OGP BIMA + RESUBMIT AO. <strong>Lainnya:</strong> progress belum diisi, PERIZINAN, KONFIRMASI PIC, DELIVERY MATERIAL, HOLD, BUTUH QE, INSTALASI, CLOSE ON DESK, dan status lama lainnya. Setiap order masuk tepat satu kelompok; PS hari ini adalah bagian dari Close.</div>
</section>
<section class="panel gap-top" aria-labelledby="type-recap-title"><div class="panel-head"><div><h2 id="type-recap-title">Rekap jenis pekerjaan</h2><p class="small muted">Volume order dan penyelesaian sesuai filter aktif</p></div><span class="badge">{{ $summary['incoming_today'] }} order hari ini</span></div>
    <div class="table-wrap" tabindex="0" aria-label="Rekap jenis pekerjaan, geser horizontal"><table class="area-type-table"><thead><tr><th scope="col" class="branch-sticky">Keterangan</th>@foreach($recap as $type => $values)<th scope="col">{{ $type }}</th>@endforeach<th scope="col">Total</th></tr></thead><tbody>
    @foreach(['total' => 'WO total', 'incoming_today' => 'WO hari ini', 'ps_total' => 'Completed total', 'ps_today' => 'Completed hari ini', 'outstanding' => 'Order open'] as $metric => $label)
        <tr><th scope="row" class="branch-sticky">{{ $label }}</th>@foreach($recap as $type => $values)<td class="numeric">@if($values[$metric])<a href="{{ $listUrl(['job_type' => $type, 'queue' => match($metric) {'incoming_today' => 'today', 'ps_total' => 'complete', 'ps_today' => 'ps_today', 'outstanding' => 'outstanding', default => 'all'}]) }}">{{ number_format($values[$metric]) }}</a>@else<span class="zero-count">0</span>@endif</td>@endforeach<td class="numeric total-cell">{{ number_format($summary[$metric]) }}</td></tr>
    @endforeach
    </tbody></table></div>
    <div class="area-table-note small muted">Completed / PS = progress order <strong>CLOSE</strong> dan STATUS BIMA <strong>CLOSE atau COMPLETE</strong>. CLOSE ON DESK dan CLOSE yang belum dikonfirmasi BIMA tidak dihitung PS.</div>
</section>
<section class="panel gap-top" aria-labelledby="area-chart-title"><div class="panel-head"><div><h2 id="area-chart-title">Perolehan PS harian</h2><p class="small muted">{{ $month->locale('id')->translatedFormat('F Y') }} · berdasarkan TGL PS BIMA</p></div><span class="badge large">{{ number_format($month_ps) }} PS</span></div><div class="panel-body">
    @if(! $month_ps)<p class="small muted area-chart-empty">Belum ada PS terkonfirmasi dalam bulan ini. Grafik akan terisi setelah kedua status dan TGL PS BIMA memenuhi ketentuan.</p>@endif
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
    <details class="gap-top"><summary>Lihat data PS harian</summary><div class="table-wrap gap-top"><table><thead><tr><th scope="col">Tanggal PS</th><th scope="col">PS terkonfirmasi</th></tr></thead><tbody>@foreach($series as $point)<tr><td>{{ $point['date'] }}</td><td>{{ $point['future'] ? 'Belum berjalan' : $point['count'] }}</td></tr>@endforeach</tbody></table></div></details>
</div></section>
@endsection
