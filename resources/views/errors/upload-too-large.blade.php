@extends('layouts.app')
@section('title', 'File terlalu besar')
@section('content')
<section class="panel"><div class="panel-body"><h1>File terlalu besar</h1><p role="alert">{{ $message }}</p><p class="muted">{{ ($kmlUpload ?? false) ? 'File belum diproses. Buka kembali order dan pilih ulang file KML.' : (($boqUpload ?? false) ? 'File belum diproses. Buka kembali order dan pilih ulang file RAB/BOQ.' : 'File belum diproses. Pilih ulang file dari Sync Center untuk melanjutkan.') }}</p><a class="button primary" href="{{ ($boqUpload ?? false) ? route('work-orders.index') : route('sync.index') }}">{{ ($boqUpload ?? false) ? 'Kembali ke Work Order' : 'Kembali ke Sync Center' }}</a></div></section>
@endsection
