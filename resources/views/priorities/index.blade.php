@extends('layouts.app')
@section('title','List Order Prioritas')
@section('content')
@php($priorityFilters = \Illuminate\Support\Arr::except($filters,['state','page']))
<div class="page-heading"><div><div class="eyebrow">Monitoring terfokus · {{ $area ? 'Area' : 'Branch' }}</div><h1>List Order Prioritas</h1><p class="muted">{{ $area ? 'Pantau pilihan Area dan tindak lanjut dari setiap Branch.' : 'Pantau arahan Area dan pilihan tim Branch dalam satu daftar.' }}</p></div><div class="row"><a class="button secondary" href="{{ route('priorities.bulk.create') }}" title="Tempel hingga 50 No WO sekaligus"><x-icon name="bookmark"/>Bookmark massal</a><a class="button secondary" href="{{ route('priorities.monitoring',$filters) }}" title="Lihat rekap task dan Owner Group"><x-icon name="dashboard"/>Monitoring</a><a class="button secondary" href="{{ route('priorities.index',$filters + ['page'=>$orders->currentPage()]) }}" title="Baca ulang status monitoring terbaru"><x-icon name="sync"/>Perbarui</a><a class="button primary" href="{{ route('work-orders.index',['jenis_pekerjaan'=>'all']) }}" title="Cari No WO dan pilih ikon bookmark"><x-icon name="bookmark"/>Pilih di Work Order</a></div></div>
<p class="small muted priority-freshness">Status diperiksa pada {{ now()->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB · Klik Perbarui untuk membaca pembaruan terbaru.</p>
<div class="priority-guidance"><x-icon name="bookmark"/><div><strong>{{ $area ? 'Prioritas Area dibagikan ke Branch penanggung jawab' : 'Pilihan Branch hanya terlihat oleh tim Branch terkait' }}</strong><p class="small muted">Pilih ikon bookmark pada Work Order. Order yang sudah PS otomatis lepas dari prioritas. CLOSE lapangan yang belum PS tetap dipantau; data dan riwayat order tetap tersimpan.</p></div></div>
<div class="stats-grid priority-kpis gap-top">
@foreach(['all'=>['Total prioritas','Seluruh pilihan sesuai filter'],'outstanding'=>['Perlu tindak lanjut','Order yang masih berjalan'],'issues'=>['Kendala aktif','Outstanding dengan kendala'],'waiting_bima'=>['Menunggu BIMA','Branch CLOSE, belum PS']] as $key=>$copy)
<a class="stat kpi-link {{ request('state','all')===$key ? 'selected' : '' }}" href="{{ route('priorities.index',array_replace($priorityFilters,['state'=>$key])) }}"><span class="small muted">{{ $copy[0] }}</span><strong>{{ number_format($summary[$key]) }}</strong><span class="small muted">{{ $copy[1] }}</span></a>
@endforeach
</div><p class="small muted gap-top">Ringkasan mengikuti seluruh filter kecuali status monitoring; pilihan status mengatur daftar di bawah. Semua jenis pekerjaan ditampilkan.</p>
<section class="panel gap-top">
@include('priorities.filters')
<div class="panel-head order-list-heading">
    <div><h2>{{ number_format($orders->total()) }} order prioritas</h2><span class="small muted">{{ $area ? 'Pilihan Area' : 'Sesuai cakupan Branch Anda' }} · Waktu WIB</span>
        <p class="small muted" id="priority-reset-help">{{ number_format($resetPreview['count']) }} bookmark {{ $area ? 'Area' : 'lokal Branch' }} dapat direset · Reset mencakup semua halaman, tanpa mengikuti filter daftar.{{ $area ? '' : ' Arahan Area tetap tersimpan.' }}</p>
    </div>
    <div class="dashboard-table-tools" role="group" aria-label="Aksi daftar prioritas">
        <x-excel-download label="Order Prioritas" :url="route('priorities.export', $filters)"/>
        @include('priorities.reset')
    </div>
</div>
<div class="table-wrap priority-desktop" tabindex="0" aria-label="Monitoring order prioritas, geser untuk melihat semua kolom"><table class="priority-table"><thead><tr><th>No WO / Pelanggan</th><th>Branch / STO</th><th>Sumber prioritas</th><th>Status monitoring</th><th>Update terakhir</th><th>Aksi</th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><a class="mono strong" href="{{ route('work-orders.show',$order) }}">{{ $order->no_tq_sc_id }}</a><div class="small">{{ $order->nama_olo ?: 'Pelanggan belum tersedia' }}</div>@include('priorities.bookmark',['showPriorityLabels'=>false])</td><td>{{ $order->assignedBranch?->name ?? 'Belum dipetakan' }}<div class="small muted">STO {{ $order->sto ?: '—' }}</div><div class="small muted">{{ $order->umur_hari===null ? 'Umur belum tersedia' : $order->umur_hari.' hari' }}</div></td><td>@include('priorities.selection-meta')</td><td>@include('priorities.monitoring-status')</td><td>{{ $order->last_progress_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? 'Belum diperbarui' }}<div class="small muted">{{ $order->last_progress_at ? 'Pembaruan Branch' : 'Perlu progress pertama' }}</div></td><td>@include('work-orders.row-actions')</td></tr>
@empty<tr><td colspan="6">@include('priorities.empty')</td></tr>@endforelse
</tbody></table></div>
<div class="priority-mobile">@forelse($orders as $order)<article class="wo-mobile-card"><a class="mono strong" href="{{ route('work-orders.show',$order) }}">{{ $order->no_tq_sc_id }}</a><p class="strong">{{ $order->nama_olo ?: 'Pelanggan belum tersedia' }}</p><p class="small muted">{{ $order->assignedBranch?->name ?? 'Belum dipetakan' }} · STO {{ $order->sto ?: '—' }} · {{ $order->umur_hari===null ? 'Umur belum tersedia' : $order->umur_hari.' hari' }}</p>@include('priorities.bookmark')<div class="priority-mobile-state">@include('priorities.monitoring-status')</div>@include('priorities.selection-meta')<p class="small muted">Update: {{ $order->last_progress_at ? $order->last_progress_at->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB' : 'Belum diperbarui' }}</p>@include('work-orders.row-actions')</article>@empty @include('priorities.empty') @endforelse</div>
<div class="panel-body">{{ $orders->links() }}</div>
</section>
@endsection
