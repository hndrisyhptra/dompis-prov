@extends('layouts.app')
@section('title', 'Masuk')
@section('content')
<div class="login-wrap"><div class="login-brand"><span class="brand-mark">OLO</span><div><strong>Command Center</strong><div class="muted small">Area Jawa–Bali</div></div></div>
<section class="panel login-panel"><div class="panel-body"><h1>Masuk ke workspace</h1><p class="muted">Pantau work order dan koordinasikan progress Area.</p>
<form method="POST" action="{{ url('/login') }}" data-loading="Memeriksa akun…">@csrf
<label for="login">Username atau email</label><input id="login" name="login" type="text" value="{{ old('login', old('email')) }}" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
<label for="password">Kata sandi</label><input id="password" name="password" type="password" required autocomplete="current-password">
<label class="check"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
<button class="button primary full">Masuk</button></form><p class="small muted gap-top">Akun disediakan administrator. Hubungi HD Area jika belum memiliki akses.</p></div></section>
</div>
@endsection
