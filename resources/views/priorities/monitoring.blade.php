@extends('layouts.app')
@section('title','Monitoring Order Prioritas')
@section('content')
@php($listFilters = \Illuminate\Support\Arr::except($filters, ['tab']))
<div class="priority-monitor-page">
<div class="page-heading"><div><div class="eyebrow">Order Prioritas · {{ $area ? 'Area' : 'Branch' }}</div><h1>Monitoring Order Prioritas</h1><p class="muted">Pantau posisi task BIMA dan penanggung jawab order yang telah dipilih.</p></div><div class="row"><a class="button secondary" href="{{ route('priorities.bulk.create') }}" title="Tempel hingga 50 No WO sekaligus"><x-icon name="bookmark"/>Bookmark massal</a><a class="button secondary" href="{{ route('priorities.monitoring',$filters) }}" title="Baca ulang data terbaru"><x-icon name="sync"/>Perbarui</a><a class="button primary" href="{{ route('priorities.index',$listFilters) }}" title="Buka daftar dan tindak lanjuti order"><x-icon name="orders"/>List Order Prioritas</a></div></div>
<p class="small muted priority-freshness">Diperiksa {{ now()->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB · {{ $area ? 'Pilihan Area' : 'Pilihan Area dan tim Branch sesuai akses Anda' }} · Semua jenis pekerjaan</p>
<section class="panel">@include('priorities.filters')</section>
<div class="priority-monitor-summary gap-top"><div><span class="small muted">Total order prioritas</span><strong>{{ number_format($report['total']) }}</strong></div><p class="small muted">Tabel ditampilkan utuh untuk screenshot. Klik angka untuk membuka order sesuai filter. Order yang sudah PS otomatis lepas dari prioritas. COMPLETE adalah status task BIMA; CLOSE lapangan yang belum PS tetap dipantau.</p></div>
<nav class="area-dashboard-tabs gap-top priority-monitor-tabs" aria-label="Tab Monitoring Order Prioritas">
    @foreach(['branch' => 'Order Branch', 'owner-group' => 'Owner Group', 'issues' => 'Order Kendala'] as $tab => $label)
    <a id="priority-tab-{{ $tab }}" class="area-dashboard-tab {{ $activeTab === $tab ? 'active' : '' }}" href="{{ route('priorities.monitoring', array_replace($filters, ['tab' => $tab])) }}" @if($activeTab === $tab) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
<div id="priority-panel-{{ $activeTab }}" role="region" aria-labelledby="priority-tab-{{ $activeTab }}">
@if($activeTab === 'issues')
@include('priorities.monitoring-issues')
@elseif(!$report['total'])
<section class="panel gap-top"><div class="panel-body">@include('priorities.empty')</div></section>
@elseif($activeTab === 'owner-group')
@include('priorities.monitoring-owner-groups')
@else
@include('priorities.monitoring-branches')
@endif
</div>
</div>
@endsection
