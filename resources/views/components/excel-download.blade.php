@props(['label', 'url'])
<div class="excel-download-control">
    <a class="button secondary compact-button" href="{{ $url }}" data-excel-download title="Download Excel · {{ $label }} sesuai filter aktif" aria-label="Download Excel {{ $label }} sesuai filter aktif"><x-icon name="download"/><span>Download Excel</span></a>
    <span class="small export-status" data-excel-status role="status" aria-live="polite"></span>
</div>
