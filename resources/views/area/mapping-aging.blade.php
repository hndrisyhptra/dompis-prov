@php
    $agingReport = $mappingOrders['aging'];
    $agingFilters = array_replace($scopeFilters, ['mapping_order'=>'OPEN']);
@endphp
<section class="panel gap-top area-compact-report" aria-labelledby="mapping-aging-title">
    <div class="panel-head priority-table-heading"><div><h2 id="mapping-aging-title">Mapping Aging Order</h2><p class="small muted">{{ number_format($agingReport['total']) }} order OPEN · Umur per {{ \Carbon\CarbonImmutable::parse($agingReport['date'])->format('d M Y') }} WIB · Buka Region untuk melihat Branch.</p></div>@if($agingReport['total'])<div class="priority-table-toolbar"><button type="button" class="button secondary compact-button" data-region-expand aria-pressed="false" title="Buka atau tutup semua Branch pada tabel aging">Buka semua</button></div>@endif</div>
    <div class="table-wrap area-compact-scroll"><table class="dashboard-table mapping-aging-table">
        <caption class="sr-only">Mapping Aging Order OPEN per Region dan Branch, umur kalender dari TGL ORDER</caption>
        <thead><tr><th class="branch-sticky" scope="col">Mapping / Region / Branch</th>@foreach($agingReport['columns'] as $label)<th class="numeric" scope="col">{{ $label }}</th>@endforeach<th class="numeric" scope="col">Grand Total</th></tr></thead>
        @if($agingReport['total'])
        <tbody><tr class="transaction-category"><th class="branch-sticky" scope="row">OPEN</th>@foreach($agingReport['columns'] as $bucket=>$label)<td class="numeric"><x-order-count :count="$agingReport['counts'][$bucket]" :filters="array_replace($agingFilters,['aging_bucket'=>$bucket,'aging_on'=>$agingReport['date']])" :label="'OPEN · '.$label"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$agingReport['total']" :filters="$agingFilters" label="Total OPEN · seluruh umur"/></td></tr></tbody>
        @foreach($agingReport['regions'] as $region)
        @php
            $agingRegionFilters = array_replace($agingFilters,$region['filters']);
            $agingBodyId = 'aging-region-'.$loop->index;
        @endphp
        <tbody><tr class="region-band"><th class="branch-sticky" scope="row"><button type="button" class="region-toggle" data-region-toggle aria-expanded="false" aria-controls="{{ $agingBodyId }}"><span class="region-chevron" aria-hidden="true">›</span><span>{{ $region['name'] }}<small>{{ count($region['branches']) }} kelompok Branch</small></span></button></th>@foreach($agingReport['columns'] as $bucket=>$label)<td class="numeric"><x-order-count :count="$region['counts'][$bucket]" :filters="array_replace($agingRegionFilters,['aging_bucket'=>$bucket,'aging_on'=>$agingReport['date']])" :label="'OPEN · '.$region['name'].' · '.$label"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$region['total']" :filters="$agingRegionFilters" :label="'OPEN · '.$region['name'].' · seluruh umur'"/></td></tr></tbody>
        <tbody id="{{ $agingBodyId }}" hidden>
        @foreach($region['branches'] as $branchRow)
        @php
            $agingBranchFilters = array_replace($agingRegionFilters,$branchRow['filters']);
        @endphp
        <tr><th class="branch-sticky region-branch-name" scope="row">{{ $branchRow['name'] }}</th>@foreach($agingReport['columns'] as $bucket=>$label)<td class="numeric"><x-order-count :count="$branchRow['counts'][$bucket]" :filters="array_replace($agingBranchFilters,['aging_bucket'=>$bucket,'aging_on'=>$agingReport['date']])" :label="'OPEN · '.$branchRow['name'].' · '.$label"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$branchRow['total']" :filters="$agingBranchFilters" :label="'OPEN · '.$branchRow['name'].' · seluruh umur'"/></td></tr>
        @endforeach</tbody>
        @endforeach
        @else<tbody><tr><td colspan="{{ count($agingReport['columns']) + 2 }}"><div class="empty"><h3>Belum ada order OPEN dalam filter ini</h3><p>Periksa filter atau klasifikasi Mapping Order yang tersedia.</p></div></td></tr></tbody>@endif
        <tfoot><tr><th class="branch-sticky" scope="row">Grand Total</th>@foreach($agingReport['columns'] as $bucket=>$label)<td class="numeric"><x-order-count :count="$agingReport['counts'][$bucket]" :filters="array_replace($agingFilters,['aging_bucket'=>$bucket,'aging_on'=>$agingReport['date']])" :label="'Grand Total OPEN · '.$label"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$agingReport['total']" :filters="$agingFilters" label="Grand Total Mapping Aging Order OPEN"/></td></tr></tfoot>
    </table></div>
    <div class="area-table-note small muted">Umur dihitung dari TGL ORDER sampai tanggal acuan WIB, mengikuti batas &gt; 7, &gt; 14 dan &gt; 28 hari. Tanggal kosong ditampilkan terpisah dan tetap masuk total. Klik angka untuk melihat order dan Detail.</div>
</section>
