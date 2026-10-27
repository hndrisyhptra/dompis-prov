@extends('layouts.app')
@section('title','Bookmark massal')
@section('content')
<div class="page-heading"><div><div class="eyebrow">Order Prioritas · {{ $area ? 'Area' : 'Branch' }}</div><h1>Bookmark massal</h1><p class="muted">Tempel No WO dari Excel, periksa hasil, lalu simpan pilihan yang siap.</p></div><a class="button secondary" href="{{ route('priorities.index') }}">Kembali ke List</a></div>
<div class="priority-bulk-layout">
<section class="panel"><div class="panel-head"><h2>1. Masukkan No WO</h2><span class="badge">Maksimal 50</span></div>
<form class="panel-body" method="POST" action="{{ route('priorities.bulk.preview') }}" data-confirm-skip data-loading="Memeriksa daftar…">
@csrf
<label for="bulk-numbers">No WO / TQ-SC-ID</label>
<p id="bulk-number-help" class="small muted">Satu nomor per baris. Bisa juga dipisahkan tab, koma atau titik koma. Tempel nilai tanpa header; nomor harus sama persis, termasuk huruf besar/kecil dan nol di depan. Maksimal 50 entri termasuk duplikat.</p>
<textarea class="mono priority-bulk-input" id="bulk-numbers" name="numbers" rows="12" maxlength="10000" required aria-describedby="bulk-number-help" @error('numbers') aria-invalid="true" @enderror placeholder="801Mg000018hMjLIAU&#10;801Mg000018yI5QIAQ">{{ old('numbers', $numbers) }}</textarea>
@error('numbers')<p class="field-error">{{ $message }}</p>@enderror
<p class="small muted gap-top">{{ $area ? 'Pilihan disimpan sebagai Prioritas Area dan terlihat oleh Branch penanggung jawab.' : 'Pilihan disimpan sebagai Prioritas lokal Branch penanggung jawab. Pilihan lokal tidak muncul pada akun Area.' }} Tidak mengubah progress atau data sumber.</p>
<div class="row gap-top"><button class="button primary" type="submit"><x-icon name="search"/>Periksa daftar</button></div>
</form></section>
<section class="panel"><div class="panel-head"><h2>2. Periksa & simpan</h2></div>
@if($preview)
<div class="panel-body"><div class="priority-bulk-summary" role="status"><strong>{{ $preview['counts']['ready'] }} siap ditambahkan</strong><span class="small muted">{{ count($preview['rows']) }} entri diperiksa · {{ count($preview['rows']) - $preview['counts']['ready'] }} dilewati</span></div><p class="small muted">Hanya baris Siap yang akan ditambahkan. Preview berlaku 15 menit; akses, Branch dan status PS diperiksa ulang saat menyimpan.</p></div>
<div class="table-wrap"><table class="priority-bulk-table"><thead><tr><th scope="col">No WO / TQ-SC-ID</th><th scope="col">Branch</th><th scope="col">Hasil pemeriksaan</th></tr></thead><tbody>
@foreach($preview['rows'] as $row)
<tr><td class="mono">{{ $row['number'] }}</td><td>{{ $row['branch'] ?? '—' }}</td><td><span class="badge {{ $row['state'] === 'ready' ? 'good' : '' }}">{{ ['ready'=>'Siap', 'selected'=>'Sudah dipilih', 'ps'=>'Sudah PS', 'missing'=>'Tidak ditemukan dalam akses Anda', 'duplicate'=>'Duplikat input', 'ambiguous'=>'Nomor ambigu · perlu diperiksa'][$row['state']] }}</span></td></tr>
@endforeach
</tbody></table></div>
<div class="panel-body">
@if($preview['token'])
<form method="POST" action="{{ route('priorities.bulk.store') }}" data-loading="Menyimpan bookmark…" data-confirm-message="Tambahkan {{ $preview['counts']['ready'] }} order yang siap ke Prioritas {{ $area ? 'Area' : 'Branch' }}?">
@csrf<input type="hidden" name="token" value="{{ $preview['token'] }}"><textarea name="original_numbers" hidden>{{ $numbers }}</textarea><button class="button primary" type="submit"><x-icon name="bookmark"/>Simpan {{ $preview['counts']['ready'] }} bookmark</button>
</form>
@else<div class="notice">Tidak ada bookmark baru yang siap disimpan. Perbaiki daftar lalu klik Periksa daftar kembali.</div>@endif
</div>
@else<div class="empty"><x-icon name="bookmark"/><h3>Hasil pemeriksaan belum tersedia</h3><p>Masukkan daftar No WO lalu klik Periksa daftar. Order yang sudah dipilih, sudah PS, duplikat atau di luar akses Anda akan dilewati.</p></div>@endif
</section>
</div>
@endsection
