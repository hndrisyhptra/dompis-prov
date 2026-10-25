@if(\App\Services\Boq\BoqImportService::available())
    @if($order->boq || app(\App\Services\Boq\BoqImportService::class)->eligible($order))
    <section class="panel gap-top"><div class="panel-head"><div><h2>RAB / BOQ</h2><p class="small muted">{{ $order->boq ? 'Dokumen dan nilai BOQ terakhir tersimpan.' : 'Belum diunggah. Progress JT tetap tersimpan dan BOQ dapat dilengkapi nanti.' }}</p></div><div class="row">@if($order->boq)<a class="button secondary" href="{{ route('boqs.show', $order) }}" title="Preview BOQ"><x-icon name="eye"/>Preview BOQ</a>@endif @can('importBoq',$order)<a class="button primary" href="{{ route('boqs.create',$order) }}" title="{{ $order->boq ? 'Upload revisi RAB/BOQ' : 'Upload RAB/BOQ' }}">{{ $order->boq ? 'Upload revisi BOQ' : 'Upload RAB/BOQ' }}</a>@endcan</div></div></section>
    @endif
@endif
