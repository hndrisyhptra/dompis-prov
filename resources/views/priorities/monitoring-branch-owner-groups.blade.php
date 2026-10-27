<section class="panel priority-monitor-panel" aria-labelledby="priority-branch-owner-title">
    <div class="panel-head priority-table-heading"><div><h2 id="priority-branch-owner-title">Branch per Owner Group</h2><p class="small muted">Jumlah order prioritas per Branch dan kelompok Owner Group. Klik angka untuk membuka daftar order.</p></div><span class="small muted">{{ count($branchOwners['rows']) }} kelompok Branch · {{ number_format($branchOwners['total']) }} order · Seluruh hasil filter</span></div>
    <div class="table-wrap dashboard-table-scroll priority-monitor-scroll" tabindex="0" aria-label="Order prioritas per Branch dan Owner Group"><table class="dashboard-table priority-branch-owner-matrix">
        <caption class="sr-only">Branch per Owner Group, seluruh order prioritas sesuai filter dan akses akun</caption>
        <thead><tr><th scope="col">Branch</th>@foreach($report['groups'] as $group)<th class="numeric" scope="col">{{ \App\Services\PriorityMonitoringService::ownerLabel($group) }}</th>@endforeach<th class="numeric total-cell" scope="col">Grand Total</th></tr></thead>
        <tbody>
        @foreach($branchOwners['rows'] as $row)
        @php($branchSelection = $row['branch_id'] === null ? ['branch'=>null, 'unassigned'=>1] : ['branch'=>$row['branch_id'], 'unassigned'=>null])
        <tr><th scope="row">{{ $row['branch_id'] === null ? 'Belum ditugaskan' : $row['name'] }}</th>@foreach($report['groups'] as $group)<td class="numeric"><x-priority-count :count="$row['counts'][$group]" :filters="$filters" :selection="$branchSelection + ['owner_group'=>$group]" :label="$row['name'].' · '.\App\Services\PriorityMonitoringService::ownerLabel($group)"/></td>@endforeach<td class="numeric total-cell"><x-priority-count :count="$row['total']" :filters="$filters" :selection="$branchSelection" :label="$row['name'].' · seluruh Owner Group sesuai filter'"/></td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><th scope="row">Grand Total</th>@foreach($report['groups'] as $group)<td class="numeric"><x-priority-count :count="$branchOwners['totals'][$group]" :filters="$filters" :selection="['owner_group'=>$group]" :label="\App\Services\PriorityMonitoringService::ownerLabel($group).' · seluruh Branch sesuai filter'"/></td>@endforeach<td class="numeric total-cell"><x-priority-count :count="$branchOwners['total']" :filters="$filters" label="Seluruh prioritas sesuai filter"/></td></tr></tfoot>
    </table></div>
</section>
