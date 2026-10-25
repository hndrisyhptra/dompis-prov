<div class="panel-body dashboard-order-meta"><strong>{{ number_format($orders->total()) }} order</strong><span class="small muted">Halaman {{ $orders->currentPage() }} dari {{ $orders->lastPage() }} · Pilih Detail untuk membuka order.</span></div>
<div class="table-wrap dashboard-dialog-table" tabindex="0" aria-label="Daftar order hasil pilihan dashboard">
    <table><thead><tr><th>No WO</th><th>Branch / STO</th><th>Nama OLO</th><th>Status Progress</th><th>Status BIMA</th><th>Aksi</th></tr></thead><tbody>
    @forelse($orders as $order)
        <tr><td class="mono">{{ $order->no_tq_sc_id }}</td><td>{{ $order->assignedBranch?->name ?? 'Belum terpetakan' }}<div class="small muted">{{ $order->sto ?: '—' }}</div></td><td>{{ $order->nama_olo ?: '—' }}</td><td>{{ $order->progressLabel() }}</td><td>{{ $order->status_order ?: '—' }}</td><td><a class="button secondary compact-button" href="{{ route('work-orders.show', $order) }}" title="Detail Order {{ $order->no_tq_sc_id }}" aria-label="Detail Order {{ $order->no_tq_sc_id }}"><x-icon name="eye"/>Detail</a></td></tr>
    @empty
        <tr><td colspan="6"><div class="empty"><h3>Belum ada order untuk pilihan ini</h3><p>Data dapat berubah setelah pembaruan atau import. Tutup modal untuk memilih kategori lain.</p></div></td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="panel-body dashboard-order-pagination" data-order-list-url="{{ $listUrl }}">
    @if($orders->previousPageUrl())<a class="button secondary" data-order-page href="{{ $orders->previousPageUrl() }}">Sebelumnya</a>@endif
    @if($orders->hasMorePages())<a class="button secondary" data-order-page href="{{ $orders->nextPageUrl() }}">Berikutnya</a>@endif
</div>
