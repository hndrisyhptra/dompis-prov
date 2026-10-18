@can('update', $order)
@php $locked = $order->terminal() && ! auth()->user()->role->areaScope(); @endphp
@if($locked && ! $order->progress_category)<div class="notice">Order ini sudah ditutup. Koreksi status memerlukan Admin Area.</div>
@else
<form method="POST" action="{{ route('work-orders.progress', $order) }}" data-loading="Menyimpan progress…" data-progress-form>@csrf @method('PATCH')
<input type="hidden" name="revision" value="{{ $order->revision }}">
<label for="progress_category">Kategori progress</label><select id="progress_category" name="progress_category" required @if($locked) aria-describedby="closed-progress-hint" @endif><option value="">Pilih kategori progress</option>@foreach(\App\Enums\ProgressCategory::cases() as $category)@if(! $locked || $order->progress_category === $category->value)<option value="{{ $category->value }}" @selected(old('progress_category', $order->progress_category) === $category->value)>{{ $category->value }}</option>@endif @endforeach</select>
@if($locked)<p id="closed-progress-hint" class="small muted">Order sudah ditutup. Anda dapat menambah catatan; perubahan kategori memerlukan Admin Area.</p>@endif
<label for="progress-notes">Catatan progress / detail kendala</label><textarea id="progress-notes" name="notes" required maxlength="2000" placeholder="Tuliskan kondisi pekerjaan dan tindak lanjut…">{{ old('notes') }}</textarea>
<p class="small muted" data-progress-hint>Catatan tersimpan pada riwayat order. Pilih KENDALA untuk mencatat hambatan pekerjaan.</p>
<div class="form-actions gap-top"><button class="button primary" type="submit">Simpan progress</button></div></form>
@endif
@if(auth()->user()->role->areaScope() && in_array($order->operational_status, ['FIELD_DONE','PENDING_VALIDATION']) && $order->verification_status !== 'VERIFIED')<details class="gap-top"><summary>Verifikasi penyelesaian oleh Area</summary><form method="POST" action="{{ route('work-orders.progress', $order) }}" data-loading="Memverifikasi…">@csrf @method('PATCH')<input type="hidden" name="revision" value="{{ $order->revision }}"><input type="hidden" name="operational_status" value="COMPLETED"><label for="verification-notes">Catatan verifikasi</label><textarea id="verification-notes" name="notes" required maxlength="2000"></textarea><button type="submit" class="button secondary gap-top">Verifikasi selesai</button></form></details>@endif
@endcan
