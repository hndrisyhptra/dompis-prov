@extends('layouts.app')
@section('title', 'Peta Order / KML')
@section('content')
<a class="back-link" href="{{ route('work-orders.show',$order) }}">← Detail Order · {{ $order->no_tq_sc_id }}</a>
<div class="page-heading"><div><h1>Peta Order / KML</h1><p class="muted">{{ $order->assignedBranch?->name }} · {{ $order->no_tq_sc_id }} · {{ $order->nama_olo }}</p></div>@if($kml)<a class="button secondary" href="{{ route('work-orders.kml.download',$order) }}" title="Unduh file KML asli">Unduh KML</a>@endif</div>
<section class="panel"><div class="panel-head"><div><h2>{{ $kml ? 'Preview peta pekerjaan' : 'Belum ada KML' }}</h2><p class="small muted">{{ $kml ? $kml->original_name.' · Versi '.$kml->revision.' · '.$kml->feature_count.' objek · '.number_format($kml->vertex_count,0,',','.').' koordinat' : 'Upload KML untuk menampilkan lokasi, jalur, dan area pekerjaan.' }}</p></div></div>
@if($kml)
<div class="panel-body" data-kml-preview data-url="{{ route('work-orders.kml.data',$order) }}" data-tile-url="{{ config('maps.tile_url') }}" data-attribution="{{ config('maps.attribution') }}">
<div class="row kml-toolbar"><button class="button secondary" type="button" data-kml-fit disabled title="Tampilkan seluruh objek">Lihat seluruh objek</button><span class="small muted">Geser peta dan gunakan + / − untuk memperbesar.</span></div>
<p class="small muted" data-kml-status role="status" aria-live="polite">Memuat data peta…</p><button type="button" class="button secondary" data-kml-retry hidden>Coba lagi</button>
<div class="kml-map gap-top" data-kml-map role="region" aria-label="Peta KML order {{ $order->no_tq_sc_id }}" aria-busy="true"></div>
<noscript><p class="notice warning gap-top">Aktifkan JavaScript untuk preview peta. File KML tetap dapat diunduh.</p></noscript>
<p class="small muted">Peta dasar memerlukan internet. Jika peta dasar gagal dimuat, geometri KML tetap dapat ditinjau. Koordinat mengikuti file yang diunggah.</p></div>
@else<div class="panel-body"><p class="muted">Belum ada lokasi yang dapat ditampilkan.</p></div>@endif</section>
<div class="grid two gap-top">
@can('uploadKml',$order)
<section class="panel"><div class="panel-head"><h2>{{ $kml ? 'Upload revisi KML' : 'Upload KML' }}</h2></div><form class="panel-body" method="POST" enctype="multipart/form-data" action="{{ route('work-orders.kml.store',$order) }}" data-loading="Memvalidasi dan menyimpan KML…">@csrf
<input type="hidden" name="order_revision" value="{{ $order->revision }}"><input type="hidden" name="kml_revision" value="{{ $kml?->revision ?? 0 }}">
<label for="kml-file">File KML pekerjaan</label><input id="kml-file" type="file" name="file" accept=".kml" data-upload-limit="{{ $uploadLimitBytes }}" required><p class="small muted" data-upload-feedback>Maksimal {{ number_format($uploadLimitBytes/1024/1024,1,',','.') }} MB · 200 objek · 10.000 koordinat.</p>
<p class="small muted">Mendukung Point, LineString, Polygon, dan MultiGeometry. Ekspor dari Google Earth sebagai .kml. File KMZ dan NetworkLink belum didukung.</p>
@if($kml)<p class="small muted">Revisi lama tetap tercatat. Upload tidak mengubah progress, status BIMA, atau nilai BOQ.</p>@endif
<div class="form-actions gap-top"><button class="button primary" type="submit">{{ $kml ? 'Simpan revisi & Preview' : 'Upload & Preview peta' }}</button></div></form></section>
@endcan
<section class="panel"><div class="panel-head"><h2>Riwayat KML</h2></div><div class="panel-body">@forelse($history as $version)<article class="timeline-event"><strong>Versi {{ $version->revision }} · {{ $version->original_name }}</strong><div class="small muted">{{ $version->created_at->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB · {{ $version->actor?->name ?? 'Pengguna tidak tersedia' }}</div><p class="small muted">{{ $version->feature_count }} objek · {{ number_format($version->vertex_count,0,',','.') }} koordinat</p></article>@empty<p class="muted">Belum ada upload KML.</p>@endforelse{{ $history->links() }}</div></section>
</div>
@endsection
