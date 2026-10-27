@php
    $mappingArea = $mappingOrders['area'];
    $mappingTitle = $mappingArea ? 'Mapping Order Region' : 'Mapping Order Branch / STO';
    $mappingLabel = fn ($category) => $category === 'unmapped' ? 'Belum dipetakan' : $category;
@endphp
<section class="panel gap-top" aria-labelledby="mapping-order-title">
    <div class="panel-head dashboard-table-heading"><div><h2 id="mapping-order-title">{{ $mappingTitle }}</h2><p class="small muted">{{ number_format($mappingOrders['total']) }} order sesuai filter · {{ $mappingArea ? 'Klasifikasi per Region' : 'Buka Branch untuk melihat rincian STO' }}</p></div>@if(!$mappingArea && $mappingOrders['rows'])<div class="dashboard-table-tools"><button class="button secondary compact-button" type="button" data-region-expand aria-pressed="false" title="Buka atau tutup semua rincian STO">Buka semua</button></div>@endif</div>
    <div class="table-wrap dashboard-table-scroll" tabindex="0" aria-label="{{ $mappingTitle }}, geser horizontal bila diperlukan"><table class="dashboard-table mapping-order-table">
        <caption class="sr-only">{{ $mappingTitle }} berdasarkan klasifikasi mapping_order, beserta total baris dan kolom</caption>
        <thead><tr><th class="branch-sticky" scope="col">{{ $mappingArea ? 'Region' : 'Branch / STO' }}</th>@foreach($mappingOrders['columns'] as $category)<th scope="col" class="numeric">{{ $mappingLabel($category) }}</th>@endforeach<th scope="col" class="numeric">Grand Total</th></tr></thead>
        @forelse($mappingOrders['rows'] as $row)
        @php($mappingRowFilters = array_replace($mappingScopeFilters, $row['filters']))
        <tbody><tr @class(['region-band' => !$mappingArea])><th class="branch-sticky" scope="row">@if(!$mappingArea)<button class="region-toggle" type="button" data-region-toggle aria-expanded="true" aria-controls="mapping-sto-{{ $loop->index }}"><span class="region-chevron" aria-hidden="true">›</span><span>{{ $row['name'] }}<small>{{ count($row['children']) }} kelompok STO</small></span></button>@else{{ $row['name'] }}@endif</th>
        @foreach($mappingOrders['columns'] as $category)<td @class(['numeric', 'mapping-order-focus' => in_array($category,['OPEN','STUCK'],true)])><x-order-count :count="$row['counts'][$category]" :filters="array_replace($mappingRowFilters,['mapping_order'=>$category])" :label="$row['name'].' · Mapping '.$mappingLabel($category)"/></td>@endforeach
        <td class="numeric total-cell"><x-order-count :count="$row['total']" :filters="$mappingRowFilters" :label="$row['name'].' · Total Mapping Order'"/></td></tr></tbody>
        @if(!$mappingArea)
        <tbody id="mapping-sto-{{ $loop->index }}">
        @foreach($row['children'] as $stoRow)
        @php($mappingStoFilters = array_replace($mappingScopeFilters,$stoRow['filters']))
        <tr><th class="branch-sticky region-branch-name" scope="row">{{ $stoRow['name'] }}</th>@foreach($mappingOrders['columns'] as $category)<td @class(['numeric', 'mapping-order-focus' => in_array($category,['OPEN','STUCK'],true)])><x-order-count :count="$stoRow['counts'][$category]" :filters="array_replace($mappingStoFilters,['mapping_order'=>$category])" :label="$row['name'].' · '.$stoRow['name'].' · Mapping '.$mappingLabel($category)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$stoRow['total']" :filters="$mappingStoFilters" :label="$row['name'].' · '.$stoRow['name'].' · Total Mapping Order'"/></td></tr>
        @endforeach</tbody>
        @endif
        @empty<tbody><tr><td colspan="{{ count($mappingOrders['columns']) + 2 }}"><div class="empty"><h3>Belum ada order dalam filter ini</h3><p>Periksa filter dan penugasan Branch. Klasifikasi akan tampil dari data work order yang tersedia.</p></div></td></tr></tbody>@endforelse
        <tfoot><tr><th class="branch-sticky" scope="row">Grand Total</th>@foreach($mappingOrders['columns'] as $category)<td class="numeric"><x-order-count :count="$mappingOrders['totals'][$category]" :filters="array_replace($mappingScopeFilters,['mapping_order'=>$category])" :label="'Grand Total · Mapping '.$mappingLabel($category)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$mappingOrders['total']" :filters="$mappingScopeFilters" label="Grand Total Mapping Order"/></td></tr></tfoot>
    </table></div>
    <div class="area-table-note small muted">Klasifikasi mengikuti kolom mapping_order pada work order. PS di tabel ini adalah kategori Mapping Order; KPI PS harian tetap mengikuti Status Progress, status BIMA dan TGL PS. Nilai kosong/tidak dikenal masuk Belum dipetakan. Klik angka untuk melihat daftar dan Detail Order.</div>
</section>
