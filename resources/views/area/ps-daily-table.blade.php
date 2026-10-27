@php
    $dailyView = $psDailyTable;
    $dailyPeriod = $dailyView['period_filters'];
    $dailyRange = \Carbon\CarbonImmutable::parse($dailyView['start'])->format('d M').' – '.\Carbon\CarbonImmutable::parse($dailyView['end'])->format('d M Y');
    $dailyDetailUrl = route('area.dashboard', array_replace(['month'=>$month->format('Y-m')], $filters, ['tab'=>'ach-ps','ps_view'=>$dailyView['full_month'] ? 'recent' : 'month'])).'#ps-daily-branch-title';
@endphp
<section class="panel gap-top" aria-labelledby="ps-daily-branch-title">
    <div class="panel-head dashboard-table-heading"><div><h2 id="ps-daily-branch-title">PS harian per Branch</h2><p class="small muted">{{ $dailyRange }} · {{ count($dailyView['dates']) }} tanggal · {{ number_format($dailyView['total']) }} PS pada tabel</p></div><div class="dashboard-table-tools"><a class="button secondary compact-button" href="{{ $dailyDetailUrl }}" title="{{ $dailyView['full_month'] ? 'Kembali ke maksimal 15 hari terakhir dalam bulan terpilih' : 'Lihat seluruh tanggal bulan terpilih beserta total bulan' }}"><x-icon name="{{ $dailyView['full_month'] ? 'arrow-left' : 'eye' }}"/>{{ $dailyView['full_month'] ? 'Ringkas 15 hari' : 'Detail bulan' }}</a></div></div>
    <div class="table-wrap dashboard-table-scroll" tabindex="0" aria-label="PS harian per Branch, geser horizontal untuk melihat seluruh tanggal"><table class="dashboard-table ps-branch-daily-matrix">
        <caption class="sr-only">PS harian per Branch untuk {{ $dailyRange }}; Grand Total menjumlahkan tanggal yang ditampilkan</caption>
        <thead><tr><th class="branch-sticky" scope="col">Branch</th>@foreach($dailyView['dates'] as $date)<th class="numeric" scope="col"><time datetime="{{ $date['date'] }}">{{ $date['label'] }}</time></th>@endforeach<th class="numeric" scope="col">Grand Total</th></tr></thead>
        <tbody>
        @forelse($dailyView['rows'] as $row)
        @php
            $dailyRowFilters = array_replace($scopeFilters,$row['filters']);
        @endphp
        <tr><th class="branch-sticky" scope="row">{{ $row['name'] }}</th>@foreach($dailyView['dates'] as $date)<td class="numeric">@if($date['future'])<span class="muted" title="Tanggal belum berjalan" aria-label="Tanggal belum berjalan">—</span>@else<x-order-count :count="$row['counts'][$date['date']]" :filters="array_replace($dailyRowFilters,['ps_date'=>$date['date']])" :label="$row['name'].' · PS '.$date['label']"/>@endif</td>@endforeach<td class="numeric total-cell"><x-order-count :count="$row['total']" :filters="array_replace($dailyRowFilters,$dailyPeriod)" :label="$row['name'].' · Total PS '.$dailyRange"/></td></tr>
        @empty<tr><td colspan="{{ count($dailyView['dates']) + 2 }}"><div class="empty"><h3>Belum ada PS untuk Branch pada bulan ini</h3><p>Periksa bulan dan filter aktif. Tabel memakai Status Progress CLOSE, status BIMA sesuai ketentuan PS dan TGL PS yang sudah berjalan.</p></div></td></tr>@endforelse
        </tbody>
        <tfoot><tr><th class="branch-sticky" scope="row">Grand Total</th>@foreach($dailyView['dates'] as $date)<td class="numeric">@if($date['future'])<span class="muted" title="Tanggal belum berjalan" aria-label="Tanggal belum berjalan">—</span>@else<x-order-count :count="$dailyView['totals'][$date['date']]" :filters="array_replace($scopeFilters,['ps_date'=>$date['date']])" :label="'Total PS · '.$date['label']"/>@endif</td>@endforeach<td class="numeric total-cell"><x-order-count :count="$dailyView['total']" :filters="array_replace($scopeFilters,$dailyPeriod)" :label="'Grand Total PS '.$dailyRange"/></td></tr></tfoot>
    </table></div>
    <div class="area-table-note small muted">Grand Total hanya menjumlahkan tanggal pada tabel. Total seluruh bulan sesuai filter: <strong>{{ number_format($dailyView['month_total']) }} PS</strong>. {{ $dailyView['full_month'] ? 'Seluruh tanggal bulan ditampilkan.' : 'Ringkasan maksimal 15 hari kalender dalam bulan terpilih, sampai hari ini untuk bulan berjalan.' }} Hari tanpa PS bernilai 0; tanggal masa depan bertanda — dan tidak dihitung. Klik angka untuk melihat order beserta aksi Detail.</div>
</section>
