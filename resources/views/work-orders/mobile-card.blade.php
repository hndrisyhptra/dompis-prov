<article class="wo-mobile-card">
<div class="wo-mobile-heading"><a class="mono strong" href="{{ route('work-orders.show', $order) }}">{{ $order->no_tq_sc_id }}</a></div>
<strong>{{ $order->nama_olo ?: 'Pelanggan belum tersedia' }}</strong><p class="small muted">{{ $order->assignedBranch?->name ?? 'Belum dipetakan' }} · STO {{ $order->sto ?: '—' }} · {{ $order->layanan }}</p>
<dl class="wo-mobile-meta"><div><dt>Status Progress</dt><dd><span class="badge {{ ($order->branch_completed_at || $order->progress_category === 'CLOSE') ? 'good' : (in_array($order->progress_category, ['KENDALA','HOLD','BUTUH QE','PERIZINAN','CANCEL']) ? 'warn' : '') }}">{{ $order->progressLabel() }}</span></dd></div><div><dt>Umur order</dt><dd>{{ $order->umur_hari === null ? '—' : $order->umur_hari.' hari' }}</dd></div><div class="wo-mobile-bima"><dt>Status Bima</dt><dd class="bima-order-status">{{ $order->status_order ?: 'Belum tersedia' }}</dd></div></dl>
@include('work-orders.row-actions')
@if(request()->filled('owner_group'))<p class="small muted">Owner Group: {{ $order->owner_grup ?: 'Belum diimpor' }}</p>@endif
</article>
