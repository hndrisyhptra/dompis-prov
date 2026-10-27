<section class="panel priority-monitor-panel gap-top" aria-labelledby="priority-issues-title">
    <div class="panel-head priority-table-heading"><div><h2 id="priority-issues-title">Order Kendala</h2><p class="small muted">Buka Branch untuk melihat update progress/kendala terbaru. Jumlah per Branch mengikuti halaman ini.</p></div><div class="priority-table-toolbar"><span class="small muted">{{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} dari {{ number_format($orders->total()) }} order · Halaman {{ $orders->currentPage() }}</span>@if($orders->count())<button class="button secondary compact-button" type="button" data-region-expand aria-pressed="false" title="Buka atau tutup seluruh Branch di halaman ini">Buka semua</button>@endif</div></div>
    <div class="table-wrap dashboard-table-scroll priority-monitor-scroll"><table class="dashboard-table priority-issues-table">
        <caption class="sr-only">Order prioritas dengan update progress atau kendala aktif, dikelompokkan per Branch sesuai cakupan dan filter Anda</caption>
        <thead><tr><th scope="col">Branch</th><th scope="col">No WO / TQ-SC-ID</th><th scope="col">Status order</th><th scope="col">Update Progress/Kendala</th></tr></thead>
        @forelse($orders->getCollection()->groupBy(fn($order) => $order->id_branches ?? 'unassigned') as $branchOrders)
        <tbody><tr class="region-band"><th colspan="4" scope="rowgroup"><button class="region-toggle" type="button" data-region-toggle aria-expanded="true" aria-controls="priority-issue-branch-{{ $loop->index }}"><span class="region-chevron" aria-hidden="true">›</span><span>{{ $branchOrders->first()->assignedBranch?->name ?? 'Belum ditugaskan' }}<small>{{ $branchOrders->count() }} order di halaman ini</small></span></button></th></tr></tbody>
        <tbody id="priority-issue-branch-{{ $loop->index }}">
        @foreach($branchOrders as $order)
        @php($update = $issueUpdates->get($order->getKey()))
        <tr>
            <td>{{ $order->assignedBranch?->name ?? 'Belum ditugaskan' }}</td>
            <td><a class="mono strong" href="{{ route('work-orders.show', $order) }}" title="Detail Order {{ $order->no_tq_sc_id }}">{{ $order->no_tq_sc_id }}</a></td>
            <td>{{ $order->status_order !== null && $order->status_order !== '' ? $order->status_order : 'Status BIMA belum tersedia' }}</td>
            <td>@if($update)<div class="small strong">{{ $update['label'] }}</div>@endif<div class="priority-issue-note">{{ $update['text'] ?? 'Catatan progress/kendala belum tersedia' }}</div>@if($update['at'] ?? null)<div class="small muted priority-issue-time">Diperbarui {{ $update['at']->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB</div>@endif</td>
        </tr>
        @endforeach
        </tbody>
        @empty
        <tbody><tr><td colspan="4"><div class="empty"><h3>{{ $orders->total() ? 'Tidak ada order di halaman ini.' : 'Belum ada order prioritas dengan update progress/kendala' }}</h3><p>Hasil mengikuti pilihan prioritas, cakupan akses dan filter Anda. Catatan tampil setelah Branch memperbarui progress atau mencatat kendala.</p>@if($orders->total())<a href="{{ route('priorities.monitoring', array_replace($filters, ['tab' => 'issues'])) }}">Kembali ke halaman pertama</a>@endif</div></td></tr></tbody>
        @endforelse
        <tfoot><tr><th colspan="3" scope="row">Grand Total order dengan update · seluruh halaman</th><td>{{ number_format($orders->total()) }}</td></tr></tfoot>
    </table></div>
    <div class="panel-body">{{ $orders->links() }}</div>
</section>
