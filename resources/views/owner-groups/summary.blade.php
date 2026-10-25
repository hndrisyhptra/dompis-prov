<section class="panel gap-top" aria-labelledby="owner-group-title">
    <div class="panel-head"><div><h2 id="owner-group-title">Distribusi Owner Group</h2><p class="small muted">Klik Region untuk melihat Branch dan kelompok penanggung jawab.</p></div><span class="badge">{{ number_format($ownerGroups['total']) }} order</span></div>
    @if(!$ownerGroups['has_mapping'])<div class="notice owner-group-note">Referensi mapping Owner Group belum tersedia. Menunggu file Excel mapping; order tetap dihitung pada kolom Belum dipetakan.</div>@endif
    <div class="table-wrap" tabindex="0" aria-label="Rekap Owner Group per Region dan Branch, geser horizontal untuk melihat semua kelompok"><table class="owner-group-matrix">
        <caption class="sr-only">Distribusi order berdasarkan Owner Group per Region dan Branch beserta Grand Total</caption>
        <thead><tr><th scope="col" class="branch-sticky">Region / Branch</th>@foreach($ownerGroups['groups'] as $group)<th scope="col">{{ $group }}</th>@endforeach<th scope="col">Belum dipetakan</th><th scope="col">Grand Total</th></tr></thead>
        @forelse($ownerGroups['regions'] as $region)
            @php
                $bodyId = 'owner-region-'.$loop->index;
                $regionFilters = array_replace($ownerScopeFilters, $region['region_id'] ? ['region' => $region['region_id']] : ['without_region' => 1]);
            @endphp
            <tbody><tr class="region-band"><th scope="row" class="branch-sticky"><button type="button" class="region-toggle" data-region-toggle aria-expanded="false" aria-controls="{{ $bodyId }}"><span class="region-chevron" aria-hidden="true">›</span><span>{{ $region['name'] }}<small>{{ count($region['rows']) }} Branch</small></span></button></th>
                @foreach($region['totals'] as $group => $count)<td class="numeric"><x-order-count :count="$count" :filters="array_replace($regionFilters, ['owner_group' => $group])" :label="$region['name'].' · '.($group === 'unmapped' ? 'Belum dipetakan' : $group)"/></td>@endforeach
                <td class="numeric total-cell"><x-order-count :count="$region['total']" :filters="$regionFilters" :label="$region['name'].' · Total Owner Group'"/></td>
            </tr></tbody>
            <tbody id="{{ $bodyId }}" data-region-body hidden>
                @foreach($region['rows'] as $row)
                    @php($rowFilters = array_replace($ownerScopeFilters, $row['branch_id'] ? ['branch' => $row['branch_id']] : ['unassigned' => 1]))
                    <tr><th scope="row" class="branch-sticky region-branch-name">{{ $row['name'] }}</th>@foreach($row['counts'] as $group => $count)<td class="numeric"><x-order-count :count="$count" :filters="array_replace($rowFilters, ['owner_group' => $group])" :label="$row['name'].' · '.($group === 'unmapped' ? 'Belum dipetakan' : $group)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$row['total']" :filters="$rowFilters" :label="$row['name'].' · Total Owner Group'"/></td></tr>
                @endforeach
            </tbody>
        @empty<tbody><tr><td colspan="{{ count($ownerGroups['groups']) + 3 }}"><div class="empty"><h3>Belum ada order dalam cakupan ini</h3><p>Ubah filter atau periksa hasil import dan penugasan Branch.</p></div></td></tr></tbody>@endforelse
        <tfoot><tr><th scope="row" class="branch-sticky">Grand Total</th>@foreach($ownerGroups['totals'] as $group => $count)<td class="numeric"><x-order-count :count="$count" :filters="array_replace($ownerScopeFilters, ['owner_group' => $group])" :label="'Grand Total · '.($group === 'unmapped' ? 'Belum dipetakan' : $group)"/></td>@endforeach<td class="numeric total-cell"><x-order-count :count="$ownerGroups['total']" :filters="$ownerScopeFilters" label="Grand Total Owner Group"/></td></tr></tfoot>
    </table></div>
    <div class="area-table-note small muted">Owner Group sumber berasal dari kolom J BIMA. Kelompok mengikuti master mapping; nilai kosong, belum dikenal atau aturan nonaktif masuk Belum dipetakan. Klik angka untuk melihat order dan pilih Detail untuk membuka order terkait.</div>
</section>
