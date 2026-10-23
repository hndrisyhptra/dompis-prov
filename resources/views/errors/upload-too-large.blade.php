@extends('layouts.app')
@section('title', 'File terlalu besar')
@section('content')
<section class="panel"><div class="panel-body"><h1>File terlalu besar</h1><p role="alert">{{ $message }}</p><p class="muted">File belum diproses. Pilih ulang file dari Sync Center untuk melanjutkan.</p><a class="button primary" href="{{ route('sync.index') }}">Kembali ke Sync Center</a></div></section>
@endsection
