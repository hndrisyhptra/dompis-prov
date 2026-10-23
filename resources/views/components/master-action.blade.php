@props(['href' => null, 'tooltip', 'icon' => null, 'variant' => 'secondary', 'type' => 'button', 'compact' => false])
@if($href)
<a href="{{ $href }}" title="{{ $tooltip }}" data-master-tooltip="{{ $tooltip }}" {{ $attributes->class(['button', $variant, 'master-action', 'master-action-compact' => $compact]) }}>
    @if($icon)<x-icon :name="$icon"/>@endif{{ $slot }}
</a>
@else
<button type="{{ $type }}" title="{{ $tooltip }}" data-master-tooltip="{{ $tooltip }}" {{ $attributes->class(['button', $variant, 'master-action', 'master-action-compact' => $compact]) }}>
    @if($icon)<x-icon :name="$icon"/>@endif{{ $slot }}
</button>
@endif
