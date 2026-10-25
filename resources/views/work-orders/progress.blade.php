@php
$activeIssues = $order->issues->where('status', 'OPEN');
$locked = $order->terminal() && !auth()->user()->role->areaScope();
$initialCategory = $mode === 'close' ? 'CLOSE' : (array_key_exists($order->progress_category ?? '', $definition['categories']) ? $order->progress_category : '');
$selectedCategory = old('progress_category', $initialCategory);
$deviceComplete = collect(array_keys(\App\Services\WorkOrderDeviceService::FIELDS))->filter(fn($field) => trim($order->$field ?? '') !== '')->count();
@endphp
@if($locked)<div class="notice success small">Order sudah ditutup. Anda tetap dapat menambahkan catatan tanpa mengubah status atau tanggal penyelesaian.</div>@endif
@if($mode === 'close')
<div class="notice small"><strong>Penutupan instalasi lapangan</strong><p>Gunakan CLOSE setelah instalasi dan pengujian lapangan selesai. Close belum tentu PS.</p><dl class="meta-list"><div><dt>Kendala aktif</dt><dd>{{ $activeIssues->count() ? $activeIssues->count().' perlu diselesaikan' : 'Tidak ada' }}</dd></div><div><dt>Data perangkat</dt><dd>{{ $deviceComplete }}/6 terisi @can('editDevices',$order) · <a href="{{ route('work-orders.show',$order) }}#order-devices">Lengkapi perangkat</a>@endcan</dd></div></dl><details><summary>Lihat 14 status BIMA untuk PS</summary><ul class="ps-rule-list">@foreach(\App\Support\PsEligibility::STATUSES as $psStatus)<li>{{ $psStatus }}</li>@endforeach</ul></details><p class="small muted">PS harian mengikuti TGL PS BIMA.</p></div>
@elseif($mode === 'issue')
<div class="notice small">Cancel Order mencatat pembatalan, bukan PS. Pembukaan kembali order yang sudah dibatalkan memerlukan Admin Area.</div>
@endif
<form method="POST" action="{{ route('work-orders.action.update',[$order,$mode]) }}" data-loading="Menyimpan pembaruan…">
@csrf @method('PATCH')<input type="hidden" name="revision" value="{{ old('revision',$order->revision) }}">
<label for="progress_category">{{ $mode === 'issue' ? 'Kategori kendala' : 'Status Progress' }}</label>
<select id="progress_category" name="progress_category" required @if($errors->has('progress_category')) aria-invalid="true" @endif>
@if($mode !== 'close')<option value="">{{ $mode === 'issue' ? 'Pilih kategori kendala' : 'Pilih tahap pekerjaan' }}</option>@endif
@foreach($definition['categories'] as $value=>$label)<option value="{{ $value }}" @selected($selectedCategory===$value)>{{ $label }}</option>@endforeach
</select>
@error('progress_category')<p class="master-field-error">{{ $message }}</p>@enderror
<label for="progress-notes">{{ $mode === 'issue' ? 'Detail kendala' : ($mode === 'close' ? 'Ringkasan penyelesaian' : 'Catatan progress') }}</label>
<textarea id="progress-notes" name="notes" required maxlength="2000" placeholder="{{ $mode === 'issue' ? 'Jelaskan kendala, penyebab dan tindak lanjut…' : ($mode === 'close' ? 'Tuliskan hasil instalasi dan pengujian lapangan…' : 'Tuliskan perkembangan pekerjaan dan rencana berikutnya…') }}" @if($errors->has('notes')) aria-invalid="true" @endif>{{ old('notes') }}</textarea>
@error('notes')<p class="master-field-error">{{ $message }}</p>@enderror
@if($activeIssues->isNotEmpty() && $mode !== 'issue')<div class="gap-top"><label class="check"><input type="checkbox" name="resolve_issues" value="1" @checked(old('resolve_issues')) @required($mode === 'close')> Seluruh kendala aktif sudah selesai</label><p class="small muted">Catatan di atas disimpan sebagai hasil penyelesaian kendala. Close membutuhkan penyelesaian seluruh kendala aktif.</p></div>@endif
@if($mode === 'progress' && \App\Services\Boq\BoqImportService::available())
<input type="hidden" data-boq-next-value><section class="notice gap-top" data-jt-boq-options @if($selectedCategory !== 'JT') hidden @endif><strong>RAB / BOQ · opsional</strong><p>Progress JT dapat disimpan tanpa BOQ. Upload sekarang, atau pilih Skip dulu dan unggah dari Detail Order saat RAB/BOQ sudah jadi.</p><div class="row gap-top"><button class="button primary" type="submit" name="boq_next" value="upload" @disabled($selectedCategory !== 'JT')>Simpan JT &amp; Upload RAB/BOQ</button><button class="button secondary" type="submit" name="boq_next" value="skip" @disabled($selectedCategory !== 'JT')>Simpan JT · Skip dulu</button></div></section>
@endif
<div class="form-actions progress-save-bar gap-top"><a class="button secondary" href="{{ route('work-orders.show',$order) }}" title="Kembali tanpa menyimpan">Batal</a><button class="button primary" type="submit" data-normal-progress-save title="Simpan {{ $definition['title'] }}">{{ $locked ? 'Tambahkan catatan' : ($mode === 'close' ? 'Konfirmasi CLOSE' : ($mode === 'issue' ? 'Simpan kendala' : 'Simpan progress')) }}</button></div>
</form>
@include('work-orders.issue-history')
@if($mode === 'close' && auth()->user()->role->areaScope() && in_array($order->operational_status,['FIELD_DONE','PENDING_VALIDATION']) && $order->verification_status !== 'VERIFIED')<details class="gap-top"><summary>Verifikasi penyelesaian oleh Area</summary><form method="POST" action="{{ route('work-orders.progress',$order) }}" data-loading="Memverifikasi…">@csrf @method('PATCH')<input type="hidden" name="revision" value="{{ $order->revision }}"><input type="hidden" name="operational_status" value="COMPLETED"><label for="verification-notes">Catatan verifikasi</label><textarea id="verification-notes" name="notes" required maxlength="2000"></textarea><button type="submit" class="button secondary gap-top">Verifikasi selesai</button></form></details>@endif
