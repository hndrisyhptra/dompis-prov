@extends('layouts.app')
@section('title', ($record->exists ? 'Edit ' : 'Tambah ').$definition['label'])
@section('content')
<a class="back-link" href="{{ route('master-data.index', $masterType) }}">← Master Data · {{ $definition['label'] }}</a>
<div class="page-heading master-heading"><div><div class="eyebrow">Master Data</div><h1>{{ $record->exists ? 'Edit' : 'Tambah' }} {{ $definition['label'] }}</h1><p class="muted">Hubungkan nilai sumber BIMA dengan kategori yang tepat.</p></div></div>
<div class="grid master-form-grid">
    <section class="panel">
        <div class="panel-head"><div><h2>Aturan mapping</h2><p class="small muted">Kolom bertanda * wajib diisi. Huruf besar/kecil pada kunci mengikuti sumber.</p></div></div>
        <form class="panel-body master-form" method="POST" action="{{ $record->exists ? route('master-data.update', [$masterType,$record->getKey()]) : route('master-data.store',$masterType) }}" data-loading="Menyimpan mapping…">
            @csrf
            @if($record->exists)@method('PUT')<input type="hidden" name="version" value="{{ old('version',$version) }}">@endif
            <label for="mapping-key">{{ $definition['code_label'] }} *</label>
            <input id="mapping-key" name="{{ $definition['code'] }}" value="{{ old($definition['code'],$record->{$definition['code']}) }}" maxlength="{{ $masterType === 'mapping-orders' ? 511 : 255 }}" required spellcheck="false" aria-describedby="mapping-key-help" @if($errors->has($definition['code'])) aria-invalid="true" @endif>
            <p id="mapping-key-help" class="small muted">{{ $masterType === 'mapping-orders' ? 'Salin gabungan task dan status secara lengkap, misalnya Activate Service-STARTWORK. Task kosong dapat memakai -COMPWORK.' : 'Salin nama Owner Group persis dari kolom Owner Group pada export BIMA.' }}</p>
            @error($definition['code'])<p class="master-field-error">{{ $message }}</p>@enderror
            <label for="mapping-category">{{ $definition['value_label'] }} *</label>
            <select id="mapping-category" name="{{ $definition['value'] }}" required @if($errors->has($definition['value'])) aria-invalid="true" @endif><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(old($definition['value'],$record->{$definition['value']})===$category)>{{ $category }}</option>@endforeach</select>
            @error($definition['value'])<p class="master-field-error">{{ $message }}</p>@enderror
            <label for="mapping-active">Status *</label>
            <select id="mapping-active" name="active"><option value="1" @selected((string)old('active',$record->exists ? (int)$record->active : 1)==='1')>Aktif</option><option value="0" @selected((string)old('active',$record->exists ? (int)$record->active : 1)==='0')>Nonaktif</option></select>
            @error('active')<p class="master-field-error">{{ $message }}</p>@enderror
            <div class="master-form-actions"><x-master-action :href="route('master-data.index',$masterType)" tooltip="Kembali ke daftar tanpa menyimpan">Batal</x-master-action><x-master-action type="submit" tooltip="Simpan aturan mapping" icon="check" variant="primary">{{ $record->exists ? 'Simpan perubahan' : 'Tambah mapping' }}</x-master-action></div>
        </form>
    </section>
    <aside class="panel"><div class="panel-head"><h2>Pengaruh perubahan</h2></div><div class="panel-body">
        <p>{{ $masterType === 'mapping-orders' ? 'Kategori Work Order BIMA yang sesuai akan diperbarui saat mapping disimpan. Progress Branch, status sumber dan tanggal PS tetap.' : 'Rekap Owner Grup mengikuti mapping aktif terbaru tanpa mengubah nilai Owner Group pada Work Order.' }}</p>
        <p class="small muted">Mapping Order berlabel PS adalah klasifikasi. Perhitungan PS tetap mengikuti syarat Status Progress dan Status Bima yang sudah ditetapkan.</p>
        <div class="master-guide-item"><strong>Mapping yang digunakan</strong><p class="small muted">Kunci sumber tidak dapat diganti atau dihapus saat masih digunakan. Anda dapat mengubah kategorinya atau memilih Nonaktif.</p></div>
    </div></aside>
</div>
@endsection
