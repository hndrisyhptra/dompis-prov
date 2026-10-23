@php $deviceErrors = $errors->hasAny(array_keys(\App\Services\WorkOrderDeviceService::FIELDS)); @endphp
<details id="order-devices" class="panel gap-top panel-disclosure" @if($deviceErrors) open @endif>
<summary class="panel-head"><h2>Data perangkat</h2><span class="button secondary">@can('editDevices', $order) Isi / edit perangkat @else Lihat perangkat @endcan <span class="disclosure-indicator" aria-hidden="true">⌄</span></span></summary>
<div class="panel-body"><p class="small muted">Lengkapi perangkat agar HD Area dapat melanjutkan proses PS. Perubahan di sini dipertahankan saat upload BIMA berikutnya.</p>
@can('editDevices', $order)
<form method="POST" action="{{ route('work-orders.devices', $order) }}" data-loading="Menyimpan perangkat…">
@csrf @method('PATCH')
<input type="hidden" name="revision" value="{{ $order->revision }}">
<div class="grid device-fields gap-top">
@foreach(\App\Services\WorkOrderDeviceService::FIELDS as $field=>$label)
<div><label for="device-{{ $field }}">{{ $label }}</label><input id="device-{{ $field }}" name="{{ $field }}" value="{{ old($field, $order->$field) }}" maxlength="255" autocomplete="off" @if($errors->has($field)) aria-invalid="true" aria-describedby="error-{{ $field }}" @endif>
@error($field)<p id="error-{{ $field }}" class="small" role="alert">{{ $message }}</p>@enderror</div>
@endforeach
</div><button class="button primary gap-top" type="submit">Simpan perangkat</button>
</form>
@else
<dl class="meta-list">@foreach(\App\Services\WorkOrderDeviceService::FIELDS as $field=>$label)<div><dt>{{ $label }}</dt><dd>{{ $order->$field ?: '—' }}</dd></div>@endforeach</dl>
@endcan
</div></details>
