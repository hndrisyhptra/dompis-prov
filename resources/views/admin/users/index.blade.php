@extends('layouts.app')
@section('title', 'User Management')
@section('content')
<div class="page-heading"><div><div class="eyebrow">Administrasi</div><h1>User Management</h1><p class="muted">Kelola akun, peran, dan cakupan Branch pengguna.</p></div><a class="button primary" href="{{ route('users.create') }}"><x-icon name="users"/> Tambah user</a></div>
<section class="panel">
    <div class="panel-head"><h2>Daftar pengguna</h2><span class="badge">{{ $users->total() }} pengguna</span></div>
    <form class="filters" method="GET" action="{{ route('users.index') }}" data-loading="Mencari…">
        <div><label for="q">Nama, username, atau email</label><input id="q" name="q" value="{{ request('q') }}" maxlength="120" placeholder="Cari pengguna…"></div>
        <div><label for="role">Role</label><select id="role" name="role"><option value="">Semua role</option>@foreach($roles as $role)<option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ $role->label() }}</option>@endforeach</select></div>
        <div><label for="active">Status</label><select id="active" name="active"><option value="">Semua status</option><option value="1" @selected(request('active') === '1')>Aktif</option><option value="0" @selected(request('active') === '0')>Nonaktif</option></select></div>
        <button class="button secondary" type="submit">Terapkan</button><a class="button secondary" href="{{ route('users.index') }}">Reset</a>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Pengguna</th><th>Role</th><th>Cakupan Branch</th><th>Lokasi utama</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    @forelse($users as $user)
        <tr><td><strong>{{ $user->name }}</strong><div class="small muted">{{ $user->username ? '@'.$user->username : 'Tanpa username' }}</div><div class="small muted">{{ $user->email ?? 'Tanpa email' }}</div></td>
        <td>{{ $user->role->label() }}@if($user->is(auth()->user()))<div class="small muted">Akun Anda</div>@endif</td>
        <td>
            @if($user->role->areaScope())
                Seluruh Area
            @elseif($user->branches->isEmpty())
                <span class="muted">Belum ditugaskan</span>
            @else
                {{ $user->branches->pluck('name')->join(', ') }}
            @endif
        </td>
        <td>{{ $user->region?->name ?? 'Belum ditentukan' }}<div class="small muted">{{ $user->serviceArea ? $user->serviceArea->workzone.' · '.$user->serviceArea->name : 'Tanpa STO utama' }}</div></td>
        <td><span class="badge {{ $user->active ? 'good' : 'warn' }}">{{ $user->active ? 'Aktif' : 'Nonaktif' }}</span></td>
        <td><a class="button secondary" href="{{ route('users.edit', $user) }}" aria-label="Edit {{ $user->name }}">Edit</a></td></tr>
    @empty
        <tr><td colspan="6"><div class="empty"><h3>Tidak ada pengguna yang sesuai</h3><p>Ubah filter pencarian atau tambahkan pengguna baru.</p><a href="{{ route('users.create') }}">Tambah user</a></div></td></tr>
    @endforelse
    </tbody></table></div>
    <div class="panel-body">{{ $users->links() }}</div>
</section>
@endsection
