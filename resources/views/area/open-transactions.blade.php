@php
    $openTransactions = $mappingOrders['transactions'];
    $transactionLabel = fn ($type) => ['NEW INSTALL'=>'New Install','MODIFY'=>'Modify','DISCONNECT'=>'Disconnect','SUSPEND'=>'Suspend','RESUME'=>'Resume','TANPA JENIS'=>'Tanpa jenis'][$type] ?? $type;
@endphp
<section class="panel gap-top area-compact-report" aria-labelledby="open-transactions-title">
    <div class="panel-head dashboard-table-heading"><div><h2 id="open-transactions-title">OPEN &amp; STUCK per Jenis Pekerjaan</h2><p class="small muted">{{ number_format($openTransactions['total']) }} order sesuai filter · Buka Region untuk melihat Branch.</p></div>@if($openTransactions['total'])<div class="dashboard-table-tools"><button class="button secondary compact-button" type="button" data-region-expand aria-pressed="false" title="Buka atau tutup seluruh rincian Branch pada tabel pekerjaan">Buka semua</button></div>@endif</div>
    <div class="table-wrap area-compact-scroll" tabindex="0" aria-label="Order OPEN dan STUCK menurut jenis pekerjaan, Region dan Branch"><table class="dashboard-table open-transaction-table">
        <caption class="sr-only">Klasifikasi OPEN dan STUCK per jenis pekerjaan, dengan subtotal Region, Branch dan Grand Total</caption>
        <thead><tr><th class="branch-sticky" scope="col">Mapping / Region / Branch</th>@foreach($openTransactions['columns'] as $type)<th class="numeric" scope="col">{{ $transactionLabel($type) }}</th>@endforeach<th class="numeric" scope="col">Grand Total</th></tr></thead>
        @if($openTransactions['total'])
        @foreach($openTransactions['sections'] as $category => $section)
        @php
            $sectionFilters = array_replace($scopeFilters,$section['filters']);
        @endphp
        <tbody><tr class="transaction-category"><th class="branch-sticky" scope="row">{{ $category }}</th>@foreach($openTransactions['columns'] as $type)<td class="numeric"><x-order-count :count="$section['counts'][$type]" :filters="array_replace($sectionFilters,['jenis_pekerjaan'=>$type])" :label="$category.' · '.$transactionLabel($type)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$section['total']" :filters="$sectionFilters" :label="$category.' · seluruh jenis sesuai filter'"/></td></tr></tbody>
        @foreach($section['regions'] as $region)
        @php
            $regionFilters = array_replace($sectionFilters,$region['filters']);
            $bodyId = 'transaction-'.$category.'-region-'.$loop->index;
        @endphp
        <tbody><tr class="region-band"><th class="branch-sticky" scope="row"><button class="region-toggle" type="button" data-region-toggle aria-expanded="false" aria-controls="{{ $bodyId }}"><span class="region-chevron" aria-hidden="true">›</span><span>{{ $region['name'] }}<small>{{ count($region['branches']) }} kelompok Branch</small></span></button></th>@foreach($openTransactions['columns'] as $type)<td class="numeric"><x-order-count :count="$region['counts'][$type]" :filters="array_replace($regionFilters,['jenis_pekerjaan'=>$type])" :label="$category.' · '.$region['name'].' · '.$transactionLabel($type)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$region['total']" :filters="$regionFilters" :label="$category.' · '.$region['name'].' · Total pekerjaan'"/></td></tr></tbody>
        <tbody id="{{ $bodyId }}" hidden>
        @foreach($region['branches'] as $branchRow)
        @php
            $branchFilters = array_replace($regionFilters,$branchRow['filters']);
        @endphp
        <tr><th class="branch-sticky region-branch-name" scope="row">{{ $branchRow['name'] }}</th>@foreach($openTransactions['columns'] as $type)<td class="numeric"><x-order-count :count="$branchRow['counts'][$type]" :filters="array_replace($branchFilters,['jenis_pekerjaan'=>$type])" :label="$category.' · '.$branchRow['name'].' · '.$transactionLabel($type)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$branchRow['total']" :filters="$branchFilters" :label="$category.' · '.$branchRow['name'].' · Total pekerjaan'"/></td></tr>
        @endforeach</tbody>
        @endforeach
        @endforeach
        @else<tbody><tr><td colspan="{{ count($openTransactions['columns']) + 2 }}"><div class="empty"><h3>Belum ada order OPEN/STUCK dalam filter ini</h3><p>Rekap membaca kategori Mapping Order terkini. Ubah filter untuk melihat cakupan lainnya.</p></div></td></tr></tbody>@endif
        <tfoot><tr><th class="branch-sticky" scope="row">Grand Total</th>@foreach($openTransactions['columns'] as $type)<td class="numeric"><x-order-count :count="$openTransactions['totals'][$type]" :filters="array_replace($scopeFilters,['mapping_order'=>'open_stuck','jenis_pekerjaan'=>$type])" :label="'OPEN & STUCK · '.$transactionLabel($type)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$openTransactions['total']" :filters="array_replace($scopeFilters,['mapping_order'=>'open_stuck'])" label="Grand Total OPEN & STUCK"/></td></tr></tfoot>
    </table></div>
    <div class="area-table-note small muted">Jenis pekerjaan mengikuti data BIMA. Hanya kategori Mapping Order OPEN dan STUCK yang dihitung; jenis tambahan dan tanpa jenis tetap masuk total. Rekap akumulatif mengikuti filter aktif.</div>
</section>
