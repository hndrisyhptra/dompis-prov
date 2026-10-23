<div class="wo-actions">
<a class="button secondary wo-action" href="{{ route('work-orders.show', $order) }}" aria-label="Detail Order" title="Detail Order" data-tooltip="Detail Order" data-master-tooltip="Detail Order"><x-icon name="eye"/></a>
@can('update', $order)
@if(!$order->terminal() || auth()->user()->role->areaScope())
<a class="button secondary wo-action" href="{{ route('work-orders.action.edit', [$order, 'progress']) }}" aria-label="Update Progress" title="Update Progress" data-tooltip="Update Progress" data-master-tooltip="Update Progress"><x-icon name="pencil"/></a>
<a class="button secondary wo-action" href="{{ route('work-orders.action.edit', [$order, 'issue']) }}" aria-label="Kendala" title="Kendala" data-tooltip="Kendala" data-master-tooltip="Kendala"><x-icon name="alert"/></a>
<a class="button secondary wo-action wo-action-close" href="{{ route('work-orders.action.edit', [$order, 'close']) }}" aria-label="Close" aria-description="Tutup instalasi lapangan; belum tentu PS" title="Close" data-tooltip="Close" data-master-tooltip="Close"><x-icon name="check"/></a>
@endif
@endcan
</div>
