<section class="panel gap-top" aria-labelledby="order-month-title">
    <div class="panel-head"><div><h2 id="order-month-title">Penyelesaian berdasarkan bulan order</h2><p class="small muted">Kelompok berdasarkan TGL ORDER · Status penyelesaian terkini sesuai filter aktif</p></div></div>
    <div class="panel-body">
    @if(count($orderMonths['rows']))
    @php
        $chartRows = array_reverse($orderMonths['rows']);
        $metrics = ['total'=>['Total WO','all',''], 'closed'=>['Progress CLOSE','closed','7 4'], 'ps'=>['Sudah PS','complete','2 4'], 'outstanding'=>['Outstanding','outstanding','10 4 2 4']];
        $chartWidth = max(900, count($chartRows)*92);
        $maximum = max(4, (int)ceil(max(array_column($chartRows,'total'))/4)*4);
        $xAt = fn($i) => count($chartRows)===1 ? $chartWidth/2 : 54+$i*($chartWidth-90)/(count($chartRows)-1);
        $yAt = fn($value) => 232-$value/$maximum*200;
    @endphp
    <div class="cohort-legend" aria-label="Legenda grafik">@foreach($metrics as $key=>$definition)<span class="cohort-{{ $key }}"><i aria-hidden="true"></i>{{ $definition[0] }}</span>@endforeach</div>
    <div class="area-chart-container" tabindex="0" aria-label="Grafik garis bulanan, geser untuk melihat seluruh periode"><svg class="cohort-chart" data-order-month-chart viewBox="0 0 {{ $chartWidth }} 290" role="group" aria-labelledby="cohort-chart-title cohort-chart-desc"><title id="cohort-chart-title">Penyelesaian berdasarkan bulan order</title><desc id="cohort-chart-desc">Grafik garis Total WO, Progress CLOSE, Sudah PS dan Outstanding. Bulan berurutan dari terlama ke terbaru. Pilih titik untuk melihat daftar order; data lengkap tersedia pada bagian Lihat data bulanan.</desc>
    @for($tick=0;$tick<=4;$tick++)
    @php($value = $maximum*$tick/4)
    <line x1="54" x2="{{ $chartWidth-36 }}" y1="{{ $yAt($value) }}" y2="{{ $yAt($value) }}" class="chart-grid"/><text x="42" y="{{ $yAt($value)+4 }}" text-anchor="end" class="chart-label">{{ number_format($value) }}</text>
    @endfor
    @foreach($chartRows as $i=>$row)<text x="{{ $xAt($i) }}" y="260" text-anchor="middle" class="chart-label">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m',$row['month'])->locale('id')->translatedFormat('M Y') }}</text>@endforeach
    @foreach($metrics as $metric=>$definition)
    <polyline class="cohort-line cohort-{{ $metric }}" stroke-dasharray="{{ $definition[2] }}" points="{{ collect($chartRows)->map(fn($row,$i)=>$xAt($i).','.$yAt($row[$metric]))->implode(' ') }}"/>
    @foreach($chartRows as $i=>$row)
    <a class="cohort-link cohort-{{ $metric }}" href="{{ route('work-orders.index', array_replace($monthScopeFilters,['order_month'=>$row['month'],'queue'=>$definition[1]])) }}" data-dashboard-orders data-modal-title="{{ $row['label'].' · '.$definition[0] }}" aria-haspopup="dialog" aria-controls="dashboard-order-dialog" aria-label="{{ $row['label'].' · '.$definition[0].': '.number_format($row[$metric]).' order' }}" tabindex="0"><circle class="cohort-point" cx="{{ $xAt($i) }}" cy="{{ $yAt($row[$metric]) }}" r="4"><title>{{ $row['label'].' · '.$definition[0].': '.number_format($row[$metric]) }}</title></circle></a>
    @endforeach
    @endforeach
    </svg></div><p class="small muted">Pilih titik grafik untuk membuka daftar order sesuai bulan dan kategori.</p>
    @else<div class="empty"><h3>Belum ada order bertanggal</h3><p>Grafik terisi setelah TGL ORDER tersedia pada cakupan ini.</p></div>@endif
    <details class="gap-top"><summary>Lihat data bulanan</summary>
    <div class="table-wrap" tabindex="0" aria-label="Rekap penyelesaian per bulan order, geser untuk melihat seluruh kolom"><table class="area-type-table order-month-table"><thead><tr><th scope="col">Bulan order</th><th scope="col">Total WO</th><th scope="col">Progress CLOSE</th><th scope="col">Sudah PS</th><th scope="col">Outstanding</th></tr></thead><tbody>
    @forelse($orderMonths['rows'] as $row)<tr><th scope="row">{{ $row['label'] }}</th>
        @foreach(['total'=>'all', 'closed'=>'closed', 'ps'=>'complete', 'outstanding'=>'outstanding'] as $metric=>$queue)
        <td class="numeric"><x-order-count :count="$row[$metric]" :filters="array_replace($monthScopeFilters, ['order_month'=>$row['month'], 'queue'=>$queue])" :label="$row['label'].' · '.['total'=>'Total WO', 'closed'=>'Progress CLOSE', 'ps'=>'Sudah PS', 'outstanding'=>'Outstanding'][$metric]"/></td>
        @endforeach
    </tr>@empty<tr><td colspan="5"><div class="empty"><h3>Belum ada order bertanggal</h3><p>Rekap terisi setelah order dengan TGL ORDER tersedia pada cakupan ini.</p></div></td></tr>@endforelse
    </tbody></table></div></details></div>
    <div class="area-table-note small muted">Sudah PS adalah bagian dari Progress CLOSE dan wajib memenuhi 14 Status Bima. Status Bima tersebut otomatis menyesuaikan progress menjadi CLOSE saat import. Bulan di sini adalah bulan masuk order, bukan bulan terjadinya PS; grafik harian tetap memakai TGL PS. Waktu penyesuaian otomatis tidak dianggap tanggal PS. @if($orderMonths['undated']){{ number_format($orderMonths['undated']) }} order tanpa TGL ORDER tidak masuk rekap bulan.@endif</div>
</section>
