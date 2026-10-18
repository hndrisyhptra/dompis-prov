@extends('layouts.app')
@section('title', 'Data perlu diperbarui')
@section('content')
<section class="panel"><div class="panel-body"><div class="eyebrow">Perubahan belum disimpan</div><h1>Data sudah berubah</h1><p class="muted">{{ $exception->getMessage() ?: 'Muat ulang data terbaru sebelum melanjutkan.' }}</p>
<div class="row gap-top">@if(request()->routeIs('users.update'))<a class="button primary" href="{{ route('users.edit', request()->route('user')) }}">Muat ulang data user</a>@elseif(request()->routeIs('work-orders.progress','work-orders.issues','issues.*')) @php $order = request()->route('workOrder') ?? request()->route('issue')?->workOrder; @endphp<a class="button primary" href="{{ $order ? route('work-orders.show', $order) : route('work-orders.index') }}">Muat ulang order</a>@else<a class="button primary" href="{{ auth()->user()?->canSync() ? route('sync.index') : route('work-orders.index') }}">Kembali ke halaman kerja</a>@endif</div></div></section>
@endsection
