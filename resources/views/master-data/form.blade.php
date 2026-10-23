@extends('layouts.app')
@section('title', ($record->exists ? 'Edit ' : 'Tambah ').$definition['label'])
@section('content')
<a class="back-link" href="{{ route('master-data.index', $masterType) }}" title="Kembali ke daftar {{ $definition['label'] }}">← Master Data · {{ $definition['label'] }}</a>
<div class="page-heading master-heading"><div><div class="eyebrow">Referensi operasional</div><h1>{{ $record->exists ? 'Edit' : 'Tambah' }} {{ $definition['label'] }}</h1><p class="muted">{{ $masterType === 'service-areas' ? 'Hubungkan kode STO dengan Branch dan Region yang sesuai.' : 'Lengkapi identitas dan lokasi untuk referensi operasional.' }}</p></div></div>
<div class="grid master-form-grid gap-top">
    <section class="panel">
        <div class="panel-head"><div><h2>Informasi {{ $definition['label'] }}</h2><p class="small muted">Kolom bertanda * wajib diisi.</p></div></div>
        <form class="panel-body master-form" method="POST" action="{{ $record->exists ? route('master-data.update', [$masterType, $record->getKey()]) : route('master-data.store', $masterType) }}" data-loading="Menyimpan data…" data-master-form>
            @csrf
            @if($record->exists)@method('PUT')<input type="hidden" name="version" value="{{ old('version', $version) }}">@endif
            <div class="master-fields">
                <div>
                    <label for="master-code">{{ $masterType === 'service-areas' ? 'Kode STO / Workzone' : 'Kode '.$definition['label'] }} *</label>
                    <input id="master-code" name="{{ $definition['code'] }}" value="{{ old($definition['code'], $record->{$definition['code']}) }}" required maxlength="{{ $masterType === 'branches' ? 40 : ($masterType === 'service-areas' ? 20 : 255) }}" autocapitalize="characters" spellcheck="false" aria-describedby="master-code-help" @if($errors->has($definition['code'])) aria-invalid="true" @endif>
                    <p id="master-code-help" class="small muted">Kode unik, disimpan dalam huruf besar. Kode arsip tidak dapat digunakan ulang.</p>
                    @error($definition['code'])<p class="master-field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="master-name">Nama {{ $definition['label'] }} *</label>
                    <input id="master-name" name="name" value="{{ old('name', $record->name) }}" required maxlength="{{ $masterType === 'service-areas' ? 100 : 255 }}" @if($errors->has('name')) aria-invalid="true" @endif>
                    @error('name')<p class="master-field-error">{{ $message }}</p>@enderror
                </div>
            </div>
            @if($masterType !== 'regions')
                @php($regionField = $masterType === 'branches' ? 'region_id' : 'id_regions')
                <div class="master-form-section"><h3>Lokasi {{ $masterType === 'service-areas' ? 'dan mapping STO' : 'Branch' }}</h3><p class="small muted">Pilih referensi lokasi yang sesuai.</p></div>
                <div class="master-fields">
                    <div>
                        <label for="master-region">Region {{ $masterType === 'branches' ? '*' : '(wajib jika aktif)' }}</label>
                        <select id="master-region" name="{{ $regionField }}" @required($masterType === 'branches') @if($errors->has($regionField)) aria-invalid="true" @endif>
                            <option value="">Pilih Region</option>
                            @foreach($regions as $region)<option value="{{ $region->getKey() }}" @selected((string)old($regionField,$record->$regionField)===(string)$region->getKey())>{{ $region->name }}{{ $region->is_active ? '' : ' · Nonaktif' }}</option>@endforeach
                        </select>
                        @error($regionField)<p class="master-field-error">{{ $message }}</p>@enderror
                    </div>
                    @if($masterType === 'service-areas')
                        <div>
                            <label for="master-branch">Branch (wajib jika aktif)</label>
                            <select id="master-branch" name="id_branches" aria-describedby="master-branch-help" @if($errors->has('id_branches')) aria-invalid="true" @endif>
                                <option value="">Pilih Branch</option>
                                @foreach($branches as $branch)<option value="{{ $branch->getKey() }}" data-region="{{ $branch->region_id }}" @selected((string)old('id_branches',$record->id_branches)===(string)$branch->getKey())>{{ $branch->name }}{{ $branch->active ? '' : ' · Nonaktif' }}</option>@endforeach
                            </select>
                            @error('id_branches')<p class="master-field-error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
                @if($masterType === 'service-areas')<p id="master-branch-help" class="small muted" data-master-branch-hint aria-live="polite">Service Area aktif wajib memiliki Branch dan Region yang saling sesuai.</p>@endif
            @endif
            <div class="master-form-section"><h3>Status penggunaan</h3><p class="small muted">Gunakan Nonaktif jika referensi belum siap digunakan.</p></div>
            <label for="master-active">Status *</label>
            <select id="master-active" name="{{ $definition['active'] }}" @if($errors->has($definition['active'])) aria-invalid="true" @endif>
                <option value="1" @selected((string)old($definition['active'],$record->exists ? (int)$record->{$definition['active']} : 1)==='1')>Aktif</option>
                <option value="0" @selected((string)old($definition['active'],$record->exists ? (int)$record->{$definition['active']} : 1)==='0')>Nonaktif</option>
            </select>
            @error($definition['active'])<p class="master-field-error">{{ $message }}</p>@enderror
            <div class="master-form-actions">
                <x-master-action :href="route('master-data.index', $masterType)" tooltip="Kembali ke daftar tanpa menyimpan perubahan">Batal</x-master-action>
                <x-master-action type="submit" :tooltip="$record->exists ? 'Simpan perubahan data '.$definition['label'] : 'Simpan '.$definition['label'].' baru'" icon="check" variant="primary">{{ $record->exists ? 'Simpan perubahan' : 'Tambah '.$definition['label'] }}</x-master-action>
            </div>
        </form>
    </section>
    <aside class="panel master-guidance">
        <div class="panel-head"><span class="master-tab-icon"><x-icon name="branches"/></span><h2>Panduan data</h2></div>
        <div class="panel-body">
            <h3>{{ $masterType === 'service-areas' ? 'STO mengikuti Branch' : 'Referensi operasional' }}</h3>
            <p class="small muted">{{ $masterType === 'service-areas' ? 'Pilih Region terlebih dahulu, lalu Branch. Pilihan Branch mengikuti Region yang dipilih.' : 'Nama dan kode digunakan untuk membantu identifikasi lokasi dan penugasan user.' }}</p>
            <div class="master-guide-item"><strong>Periksa status induk</strong><p class="small muted">Data aktif membutuhkan Region dan Branch induk yang aktif. Nonaktifkan anak yang terkait sebelum menonaktifkan induknya.</p></div>
            <div class="master-guide-item"><strong>Riwayat tetap tersimpan</strong><p class="small muted">Perubahan dicatat pada audit. Work Order yang sudah ada tidak dipindahkan otomatis.</p></div>
            @if($record->exists)
                <div class="master-guide-item"><strong>Hapus data</strong><p class="small muted">Periksa referensi terlebih dahulu. Data yang masih digunakan tidak dapat dihapus.</p><x-master-action :href="route('master-data.confirm-delete', [$masterType,$record->getKey()])" tooltip="Periksa penggunaan data sebelum menghapus" icon="trash" variant="secondary master-action-danger">Periksa penghapusan</x-master-action></div>
            @endif
        </div>
    </aside>
</div>
@endsection
