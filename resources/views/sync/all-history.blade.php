@extends('layouts.app')
@section('title', 'Riwayat sinkronisasi')
@section('content')
<a href="{{ route('sync.index') }}" class="back-link">← Sync Center</a>
<div class="page-heading"><div><h1>Riwayat sinkronisasi</h1><p class="muted">Seluruh proses import dan sinkronisasi, dari yang terbaru.</p></div></div>
@include('sync.history', ['allHistory' => true])
@endsection
