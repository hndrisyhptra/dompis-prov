@props(['table' => null, 'label', 'url' => null, 'filters' => []])
@php($downloadUrl = $url ?? route(request()->routeIs('area.*') ? 'area.dashboard.export' : 'branch.dashboard.export', ['table' => $table] + ($filters ?? [])))
<div class="dashboard-export-control">
    <a class="button secondary compact-button" href="{{ $downloadUrl }}" data-dashboard-export title="Download Excel · {{ $label }} sesuai filter aktif" aria-label="Download Excel {{ $label }} sesuai filter aktif"><x-icon name="download"/><span>Download Excel</span></a>
    <span class="small export-status" data-export-status role="status" aria-live="polite"></span>
</div>
