@extends('layouts.app')
@section('title', $definition['label'])
@section('content')
<div class="page-heading master-heading">
    <div><div class="eyebrow">Master Data</div><h1>{{ $definition['label'] }}</h1><p class="muted">{{ isset($definition['value']) ? 'Kelola aturan klasifikasi order berdasarkan nilai sumber BIMA yang sesuai.' : 'Kelola referensi lokasi untuk mendukung distribusi Work Order.' }}</p></div>
    <x-master-action :href="route('master-data.create', $masterType)" :tooltip="'Tambahkan '.$definition['label'].' baru'" icon="plus" variant="primary">Tambah {{ $definition['label'] }}</x-master-action>
</div>
<section class="panel gap-top master-list">
    <div class="panel-head master-list-heading"><div><h2>Daftar {{ $definition['label'] }}</h2><p class="small muted">{{ number_format($records->total()) }} data sesuai filter</p></div><span class="badge">{{ isset($definition['value']) ? 'Aturan klasifikasi' : ($masterType === 'service-areas' ? 'Mapping STO' : 'Referensi lokasi') }}</span></div>
    <form class="filters master-filters" method="GET" data-loading="Memuat data…">
        <div class="master-search"><label for="q">Cari {{ $definition['label'] }}</label><input id="q" name="q" value="{{ request('q') }}" maxlength="100" placeholder="{{ isset($definition['value']) ? 'Kunci atau kategori mapping…' : 'Kode atau nama…' }}"></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">Semua status</option><option value="active" @selected(request('status')==='active')>Aktif</option><option value="inactive" @selected(request('status')==='inactive')>Nonaktif</option></select></div>
        @if(in_array($masterType, ['branches','service-areas']))
            <div><label for="region">Region</label><select id="region" name="region"><option value="">Semua Region</option>@foreach($regions as $region)<option value="{{ $region->getKey() }}" @selected((string)request('region')===(string)$region->getKey())>{{ $region->name }}</option>@endforeach</select></div>
        @endif
        @if($masterType === 'service-areas')
            <div><label for="branch">Branch</label><select id="branch" name="branch"><option value="">Semua Branch</option>@foreach($branches as $branch)<option value="{{ $branch->getKey() }}" @selected((string)request('branch')===(string)$branch->getKey())>{{ $branch->name }}</option>@endforeach</select></div>
        @endif
        @if(isset($definition['value']))<div><label for="category">{{ $definition['value_label'] }}</label><select id="category" name="category"><option value="">Semua kategori</option>@foreach($categories as $category)<option @selected(request('category')===$category)>{{ $category }}</option>@endforeach</select></div>@endif
        <div class="master-filter-actions">
            <x-master-action type="submit" tooltip="Tampilkan data sesuai pencarian dan filter" icon="search">Terapkan</x-master-action>
            <x-master-action :href="route('master-data.index', $masterType)" tooltip="Hapus pencarian dan semua filter">Reset</x-master-action>
        </div>
    </form>
    <div class="table-wrap" tabindex="0" aria-label="Daftar {{ $definition['label'] }}, geser untuk melihat semua kolom">
        <table class="master-table"><thead><tr>
            <th scope="col">{{ $definition['code_label'] ?? ($masterType === 'service-areas' ? 'Kode STO' : 'Kode') }}</th><th scope="col">{{ $definition['value_label'] ?? 'Nama '.$definition['label'] }}</th>
            @if(in_array($masterType, ['branches','service-areas']))<th scope="col">Region</th>@endif
            @if($masterType === 'service-areas')<th scope="col">Branch</th>@endif
            <th scope="col">Status</th><th scope="col" class="master-actions-heading">Aksi</th>
        </tr></thead><tbody>
        @forelse($records as $record)
            <tr>
                <td><span class="master-code mono">{{ $record->{$definition['code']} }}</span></td>
                <td class="strong">{{ $record->{$definition['value'] ?? 'name'} }}</td>
                @if(in_array($masterType, ['branches','service-areas']))<td>{{ $record->region?->name ?? 'Belum dipetakan' }}</td>@endif
                @if($masterType === 'service-areas')<td>{{ $record->branch?->name ?? 'Belum dipetakan' }}</td>@endif
                <td><span class="badge master-status {{ $record->{$definition['active']} ? 'good' : 'warn' }}"><span aria-hidden="true"></span>{{ $record->{$definition['active']} ? 'Aktif' : 'Nonaktif' }}</span></td>
                <td><div class="master-row-actions">
                    <x-master-action :href="route('master-data.edit', [$masterType, $record->getKey()])" tooltip="Edit" icon="pencil" :compact="true" :aria-label="'Edit '.$record->{$definition['code']}"></x-master-action>
                    <x-master-action :href="route('master-data.confirm-delete', [$masterType, $record->getKey()])" tooltip="Delete" icon="trash" variant="secondary master-action-danger" :compact="true" :aria-label="'Delete '.$record->{$definition['code']}"></x-master-action>
                </div></td>
            </tr>
        @empty
            <tr><td colspan="{{ $masterType === 'service-areas' ? 6 : ($masterType === 'branches' ? 5 : 4) }}"><div class="empty master-empty">
                <span class="master-empty-icon"><x-icon name="search"/></span><h3>Belum ada {{ $definition['label'] }} sesuai filter</h3><p>Coba kata pencarian lain, reset filter, atau tambahkan data baru.</p>
                <x-master-action :href="route('master-data.create', $masterType)" :tooltip="'Buka form tambah '.$definition['label']" icon="plus" variant="primary">Tambah {{ $definition['label'] }}</x-master-action>
            </div></td></tr>
        @endforelse
        </tbody></table>
    </div>
    <div class="master-list-footer"><span class="small muted">{{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} dari {{ number_format($records->total()) }} data</span>{{ $records->links() }}</div>
</section>
<div class="master-footnote"><x-icon name="alert"/><p>{{ isset($definition['value']) ? 'Mapping yang masih dipakai Work Order dilindungi dari penghapusan. Gunakan status Nonaktif untuk menghentikan aturan.' : 'Data yang masih dipakai user atau Work Order dilindungi dari penghapusan. Data yang dihapus akan diarsipkan.' }}</p></div>
@endsection
