@can('prioritize', $order)
@php
$priorityArea = auth()->user()->role->areaScope();
$ownKey = $priorityArea ? 'area' : 'branch:'.$order->id_branches;
$ownPriority = $order->priorities->firstWhere('scope_key',$ownKey);
$priorityScopeLabel = $priorityArea ? 'Area' : 'Branch';
$bookmarkLabel = $ownPriority ? 'Lepas Prioritas '.$priorityScopeLabel : 'Pilih Prioritas '.$priorityScopeLabel;
@endphp
<div class="priority-control">
    <form method="POST" action="{{ route($ownPriority ? 'priorities.destroy' : 'priorities.store',$order) }}" data-loading="…" data-confirm-message="{{ $ownPriority ? 'Lepas WO '.$order->no_tq_sc_id.' dari Prioritas '.$priorityScopeLabel.'? Work Order tetap tersimpan.' : 'Pantau WO '.$order->no_tq_sc_id.' sebagai Prioritas '.$priorityScopeLabel.'?' }}">
        @csrf
        @if($ownPriority)@method('DELETE')<input type="hidden" name="selection_token" value="{{ $ownPriority->selection_token }}">@endif
        <button type="submit" class="button secondary priority-bookmark {{ $ownPriority ? 'selected' : '' }}" aria-label="{{ $bookmarkLabel }} untuk {{ $order->no_tq_sc_id }}" title="{{ $bookmarkLabel }}" data-master-tooltip="{{ $bookmarkLabel }}" aria-pressed="{{ $ownPriority ? 'true' : 'false' }}"><x-icon name="bookmark"/></button>
    </form>
    @if($showPriorityLabels ?? true)
        @if($order->priorities->contains('scope_key','area'))<span class="badge priority-area-label">Prioritas Area</span>@endif
        @if(!$priorityArea && $ownPriority)<span class="badge">Prioritas Branch</span>@endif
    @endif
</div>
@endcan
