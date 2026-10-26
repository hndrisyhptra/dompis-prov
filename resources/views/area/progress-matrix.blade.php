<section class="panel gap-top" aria-labelledby="progress-summary-title">
    <div class="panel-head dashboard-table-heading"><div><h2 id="progress-summary-title">Progress Region &amp; Branch</h2><p class="small muted">{{ count($groups) }} Region · {{ $branch_count }} Branch sesuai filter. Klik angka untuk melihat order.</p></div><div class="dashboard-table-tools"><button type="button" class="button secondary compact-button" data-region-expand aria-pressed="false" title="Buka atau tutup semua Region pada tabel progress">Buka semua</button><x-dashboard-export table="progress" label="Progress Region dan Branch" :filters="$filters"/></div></div>
    @if(!$summary['total'])<div class="notice area-empty-notice">Belum ada order dalam filter ini. Rekap menampilkan angka nol; periksa filter atau hasil import order.</div>@endif
    <div class="table-wrap area-matrix-wrap dashboard-table-scroll" tabindex="0" aria-label="Rekap progress Branch, geser horizontal untuk melihat semua kolom"><table class="area-matrix dashboard-table">
        <caption class="sr-only">Kategori progress per Region dan Branch, beserta Grand Total</caption>
        <thead><tr><th rowspan="2" scope="col" class="branch-sticky">Region / Branch</th><th colspan="6" scope="colgroup" class="matrix-group-ongoing">On Going</th><th colspan="2" scope="colgroup" class="matrix-group-complete">Complete</th><th rowspan="2" scope="col">New Order Hari ini</th><th rowspan="2" scope="col">PS Hari ini</th><th rowspan="2" scope="col" class="total-cell">Total Order</th></tr><tr>@foreach(array_slice($columns, 0, 8, true) as $key => $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
        @forelse($groups as $group)
            @php
                $bodyId = 'progress-region-'.$loop->index;
                $regionFilters = array_replace($scopeFilters, $group['region_id'] ? ['region' => $group['region_id']] : ['without_region' => 1]);
                $metricFilters = fn ($base, $key) => array_replace($base, match ($key) { 'incoming_today' => ['queue' => 'today'], 'ps_today' => ['queue' => 'ps_today'], 'total' => [], default => ['bucket' => $key] });
            @endphp
            <tbody><tr class="region-band"><th scope="row" class="branch-sticky"><button type="button" class="region-toggle" data-region-toggle aria-expanded="false" aria-controls="{{ $bodyId }}"><span class="region-chevron" aria-hidden="true">›</span><span>{{ $group['name'] }}<small>{{ count($group['rows']) }} Branch</small></span></button></th>
                @foreach($columns as $key => $label)<td class="numeric"><x-order-count :count="$group['totals'][$key]" :filters="$metricFilters($regionFilters, $key)" :label="$group['name'].' · '.$label"/></td>@endforeach
            </tr></tbody>
            <tbody id="{{ $bodyId }}" data-region-body hidden>
                @foreach($group['rows'] as $row)
                    @php($rowFilters = array_replace($scopeFilters, $row['branch_id'] ? ['branch' => $row['branch_id']] : ['unassigned' => 1]))
                    <tr><th scope="row" class="branch-sticky region-branch-name">{{ $row['name'] }} @if($row['inactive'])<span class="small muted">· nonaktif</span>@endif</th>
                    @foreach($columns as $key => $label)<td class="numeric {{ $key === 'ps_today' ? 'ps-cell' : '' }} {{ $key === 'total' ? 'total-cell' : '' }}"><x-order-count :count="$row['counts'][$key]" :filters="$metricFilters($rowFilters, $key)" :label="$row['name'].' · '.$label"/></td>@endforeach</tr>
                @endforeach
            </tbody>
        @empty<tbody><tr><td colspan="12"><div class="empty"><h3>Tidak ada Branch sesuai filter</h3><p>Ubah pilihan Region atau Branch untuk melihat rekap.</p></div></td></tr></tbody>@endforelse
        <tfoot><tr><th scope="row" class="branch-sticky">Grand Total Jawa–Bali</th>@foreach($columns as $key => $label)@php($grandFilters = array_replace($scopeFilters, match ($key) { 'incoming_today' => ['queue' => 'today'], 'ps_today' => ['queue' => 'ps_today'], 'total' => [], default => ['bucket' => $key] }))<td class="numeric"><x-order-count :count="$grand[$key]" :filters="$grandFilters" :label="'Grand Total · '.$label"/></td>@endforeach</tr></tfoot>
    </table></div>
    <div class="area-table-note small muted"><strong>OGP BIMA:</strong> OGP BIMA + RESUBMIT AO. <strong>Kendala:</strong> KENDALA, HOLD, BUTUH QE, PERIZINAN. <strong>Lainnya:</strong> progress belum diisi, CNDC, KONFIRMASI PIC, DELIVERY MATERIAL, CLOSE ON DESK, dan status lama lainnya. Delapan kategori progress menjumlah ke Total Order. New Order Hari ini memakai TGL ORDER hari ini (WIB); PS Hari ini memakai aturan PS dan TGL PS hari ini. Label Complete tidak mengubah ketentuan PS.</div>
</section>
