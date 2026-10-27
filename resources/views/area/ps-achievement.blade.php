<section class="panel gap-top" aria-labelledby="area-chart-title"><div class="panel-head dashboard-table-heading"><div><h2 id="area-chart-title">Perolehan PS harian</h2><p class="small muted">{{ $month->locale('id')->translatedFormat('F Y') }} · {{ number_format($month_ps) }} PS · berdasarkan TGL PS BIMA</p></div></div><div class="panel-body">
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

</div></section>

@include('area.ps-daily-table')
