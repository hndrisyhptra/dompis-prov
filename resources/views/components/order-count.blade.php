@props(['count', 'filters' => [], 'label' => 'Daftar order'])
<a href="{{ route('work-orders.index', $filters) }}" data-dashboard-orders data-modal-title="{{ $label }}" aria-haspopup="dialog" aria-controls="dashboard-order-dialog" aria-label="{{ $label }} · {{ number_format($count) }} order" @class(['zero-count' => !$count])>{{ number_format($count) }}</a>
