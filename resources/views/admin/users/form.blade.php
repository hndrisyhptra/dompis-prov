@extends('layouts.app')
@section('title', $managedUser->exists ? 'Edit user' : 'Tambah user')
@section('content')
@php
    $editing = $managedUser->exists;
    $selectedBranches = array_map('strval', old('branches', $editing ? $managedUser->branches->modelKeys() : []));
    $selectedRegion = old('id_regions', $managedUser->id_regions);
    $selectedSto = old('id_service_areas', $managedUser->id_service_areas);
    $selectedRole = old('role', $managedUser->role?->value ?? 'branch_admin');
    $self = $editing && $managedUser->is(auth()->user());
@endphp
<a class="back-link" href="{{ route('users.index') }}">← User Management</a>
<div class="page-heading"><div><div class="eyebrow">Administrasi pengguna</div><h1>{{ $editing ? 'Edit user' : 'Tambah user' }}</h1><p class="muted">{{ $editing ? 'Perbarui identitas dan penugasan '.$managedUser->name.'.' : 'Siapkan identitas login dan hak akses pengguna baru.' }}</p></div>@if($editing)<span class="badge">ID {{ $managedUser->getKey() }}</span>@endif</div>
@if($self)<div class="notice">Anda sedang mengedit akun sendiri. Role superadmin dan status aktif akun ini harus dipertahankan.</div>@endif
<form method="POST" action="{{ $editing ? route('users.update', $managedUser) : route('users.store') }}" data-loading="Menyimpan user…" data-user-form>
    @csrf
    @if($editing) @method('PUT')<input type="hidden" name="management_revision" value="{{ old('management_revision', $managedUser->management_revision) }}"> @endif
    <div class="grid two user-form-grid">
        <section class="panel"><div class="panel-head"><h2>Identitas & akses login</h2></div><div class="panel-body">
            <label for="name">Nama lengkap <span class="muted">· wajib</span></label><input id="name" name="name" value="{{ old('name', $managedUser->name) }}" required maxlength="255" autocomplete="name" @error('name') aria-invalid="true" @enderror>
            @error('name')<p class="field-error">{{ $message }}</p>@enderror
            <label for="username">Username</label><input id="username" name="username" value="{{ old('username', $managedUser->username) }}" maxlength="80" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Contoh: admin.surabaya" @error('username') aria-invalid="true" @enderror>
            <p class="small muted">Isi username atau email. Username dapat memakai huruf, angka, titik, garis bawah, dan tanda hubung.</p>
            @error('username')<p class="field-error">{{ $message }}</p>@enderror
            <label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email', $managedUser->email) }}" maxlength="255" autocomplete="off" @error('email') aria-invalid="true" @enderror>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
            <div class="grid two compact-grid"><div><label for="role">Role <span class="muted">· wajib</span></label><select id="role" name="role" required @error('role') aria-invalid="true" @enderror>@foreach($roles as $role)<option value="{{ $role->value }}" data-area-scope="{{ $role->areaScope() ? '1' : '0' }}" @selected($selectedRole === $role->value)>{{ $role->label() }}</option>@endforeach</select></div>
            <div><label for="active">Status akun</label><select id="active" name="active"><option value="1" @selected((string) old('active', $editing ? (int) $managedUser->active : 1) === '1')>Aktif</option><option value="0" @selected((string) old('active', $editing ? (int) $managedUser->active : 1) === '0')>Nonaktif</option></select></div></div>
            <div class="form-divider"></div><h3>{{ $editing ? 'Ganti kata sandi' : 'Kata sandi awal' }}</h3>
            <p class="small muted">{{ $editing ? 'Kosongkan kedua kolom untuk mempertahankan kata sandi saat ini.' : 'Minimal 12 karakter, huruf besar, huruf kecil, dan angka.' }}</p>
            <label for="password">Kata sandi {{ $editing ? 'baru' : '' }}</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="255" @required(! $editing) @error('password') aria-invalid="true" @enderror>
            @if($editing)<p class="small muted">Kata sandi baru minimal 12 karakter, dengan huruf besar, huruf kecil, dan angka.</p>@endif
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
            <label for="password_confirmation">Konfirmasi kata sandi</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="255" @required(! $editing)>
        </div></section>
        <section class="panel"><div class="panel-head"><h2>Penugasan & lokasi utama</h2></div><div class="panel-body">
            <p class="small muted" data-role-scope-hint>Role Branch memerlukan minimal satu Branch. Role Area mempunyai akses seluruh Area.</p>
            <fieldset class="branch-fieldset"><legend>Cakupan Branch</legend><div class="branch-options">
            @forelse($branches as $branch)<label class="branch-option"><input type="checkbox" name="branches[]" value="{{ $branch->getKey() }}" @checked(in_array((string) $branch->getKey(), $selectedBranches, true))><span><strong>{{ $branch->name }}</strong><small>{{ $branch->code }} · {{ $branch->region?->name ?? $branch->regional ?? 'Region belum dipetakan' }}{{ $branch->active ? '' : ' · Nonaktif' }}</small></span></label>
            @empty<p class="small muted">Belum ada branch. Hubungi administrator untuk menyiapkan referensi Branch terlebih dahulu.</p>@endforelse
            </div></fieldset>
            @error('branches')<p class="field-error">{{ $message }}</p>@enderror
            <label for="id_regions">Region utama <span class="muted">· opsional</span></label><select id="id_regions" name="id_regions" @error('id_regions') aria-invalid="true" @enderror><option value="">Belum ditentukan</option>@foreach($regions as $region)<option value="{{ $region->getKey() }}" @selected((string) $selectedRegion === (string) $region->getKey())>{{ $region->name }}{{ $region->is_active ? '' : ' · Nonaktif' }}</option>@endforeach</select>
            @error('id_regions')<p class="field-error">{{ $message }}</p>@enderror
            <label for="id_service_areas">STO utama <span class="muted">· opsional</span></label><select id="id_service_areas" name="id_service_areas" @error('id_service_areas') aria-invalid="true" @enderror><option value="">Tanpa STO utama</option>@foreach($serviceAreas as $sto)<option value="{{ $sto->getKey() }}" data-region="{{ $sto->id_regions }}" data-branch="{{ $sto->id_branches }}" @selected((string) $selectedSto === (string) $sto->getKey())>{{ $sto->workzone }} · {{ $sto->name }} · {{ $sto->branch?->name ?? 'Branch tidak tersedia' }}{{ $sto->is_active && ! $sto->trashed() ? '' : ' · Nonaktif' }}</option>@endforeach</select>
            <p class="small muted" data-sto-status aria-live="polite">Pilihan STO mengikuti region dan cakupan Branch. Lokasi utama tidak menambah hak akses.</p>
            @error('id_service_areas')<p class="field-error">{{ $message }}</p>@enderror
            <div class="notice gap-top">Perubahan role, akses Branch, identitas login, atau kata sandi akan mengakhiri sesi lain pengguna terkait.</div>
        </div></section>
    </div>
    <div class="form-actions gap-top"><a class="button secondary" href="{{ route('users.index') }}">Batal</a><button type="submit" class="button primary">{{ $editing ? 'Simpan perubahan' : 'Tambah user' }}</button></div>
</form>
@endsection
