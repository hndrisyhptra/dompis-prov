@extends('layouts.app')
@section('title', 'Masuk')
@section('content')
<div class="login-shell"><section class="login-intro" aria-labelledby="login-product"><div class="login-brand"><span class="brand-mark">DP</span><div><strong id="login-product">Dompis Prov</strong><div class="muted small">WIBS Area Jawa Bali</div></div></div>
<div class="login-intro-copy"><div class="eyebrow">Monitoring operasional</div><h2>Order terpusat.<br>Progres terpantau.</h2><p class="muted">Ruang kerja Area dan Branch untuk mengelola Work Order, menindaklanjuti pekerjaan, dan memantau nilai BOQ.</p>
<ul class="login-features"><li><span class="login-feature-icon"><x-icon name="orders"/></span><div><strong>Work Order</strong><span>Informasi order dan riwayat pembaruan dalam satu tempat.</span></div></li><li><span class="login-feature-icon"><x-icon name="pin"/></span><div><strong>Koordinasi Branch</strong><span>Tindak lanjut pekerjaan sesuai penugasan tim.</span></div></li><li><span class="login-feature-icon"><x-icon name="revenue"/></span><div><strong>Revenue Overview</strong><span>Pantau nilai material, jasa, dan BOQ aktif.</span></div></li></ul></div><p class="small muted login-intro-foot">WIBS · Area Jawa Bali</p></section>
<section class="panel login-panel"><div class="panel-body"><div class="eyebrow">Akses akun</div><h1>Selamat datang</h1><p class="muted">Masuk untuk melanjutkan pekerjaan Anda.</p>
<form method="POST" action="{{ url('/login') }}" data-loading="Memeriksa akun…">@csrf
<label for="login">Username atau email</label><input id="login" name="login" type="text" value="{{ old('login', old('email')) }}" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
<label for="password">Kata sandi</label><div class="password-field"><input id="password" name="password" type="password" required autocomplete="current-password"><x-password-toggle target="password"/></div>
<label class="check login-remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya</label>
<button class="button primary full" type="submit">Masuk ke Dompis Prov <span aria-hidden="true">→</span></button></form><p class="small muted login-help">Belum memiliki akses atau perlu bantuan akun?<br>Hubungi administrator / HD Area.</p></div></section>
</div>
@endsection
