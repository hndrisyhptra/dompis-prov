@extends('layouts.app')
@section('title', 'Konfirmasi Logout')
@section('content')
<section class="panel logout-confirm-panel"><div class="panel-head"><x-icon name="log-out"/><h1>Konfirmasi Logout</h1></div><div class="panel-body"><p>Apakah Anda yakin ingin Logout?</p><form method="POST" action="{{ route('logout') }}" data-loading="Logout…" data-confirm-skip>@csrf<div class="logout-dialog-actions"><a class="button secondary" href="{{ route(auth()->user()->role->areaScope() ? 'area.dashboard' : 'branch.dashboard') }}">Tidak</a><button class="button primary" type="submit"><x-icon name="log-out"/>Ya, Logout</button></div></form></div></section>
@endsection
