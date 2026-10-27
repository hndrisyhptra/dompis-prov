@extends('layouts.app')
@section('title', 'Masuk')
@section('content')
<div class="login-shell"><section class="login-intro" aria-labelledby="login-product"><div class="login-brand"><span class="brand-mark">DP</span><div><strong id="login-product">Dompis Prov</strong><div class="muted small">WIBS Area Jawa Bali</div></div></div>
<div class="login-intro-copy"><div class="eyebrow">Monitoring Operasional</div><h2>Prov WIBS Area 3</h2><p class="muted">Ruang kerja Area dan Branch untuk mengelola Work Order, Update Progress dan Update Kendala.</p>
<ul class="login-features"><li><span class="login-feature-icon"><x-icon name="orders"/></span><div><strong>Work Order</strong><span>Informasi order dan riwayat pembaruan dalam satu tempat.</span></div></li><li><span class="login-feature-icon"><x-icon name="pin"/></span><div><strong>Koordinasi Area - Branch</strong><span>Update Progress, Update Kendala dan Close Order</span></div></li><li><span class="login-feature-icon"><x-icon name="revenue"/></span><div><strong>Revenue Overview</strong><span>Pantau RAB dan Pengeluaran Material.</span></div></li></ul></div><p class="small muted login-intro-foot">WIBS · Area Jawa Bali</p></section>
<section class="panel login-panel"><div class="panel-body"><div class="eyebrow">Akses akun</div><h1>Selamat datang</h1><p class="muted">Masuk untuk melanjutkan pekerjaan Anda.</p>
<form method="POST" action="{{ url('/login') }}" data-loading="Memeriksa akun…">@csrf
<label for="login">Username atau email</label><input id="login" name="login" type="text" value="{{ old('login', old('email')) }}" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
<label for="password">Kata sandi</label><div class="password-field"><input id="password" name="password" type="password" required autocomplete="current-password"><x-password-toggle target="password"/></div>
<label class="check login-remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya</label>
<button class="button primary full" type="submit">LOGIN <span aria-hidden="true">→</span></button></form><p class="small muted login-help">Belum memiliki akses atau perlu bantuan akun?<br>Hubungi administrator / HD Area.</p></div></section>
</div>
@endsection
