@php($activeIssues = $order->issues->where('status', 'OPEN'))
@if($order->issues->isNotEmpty())
<details class="progress-issues gap-bottom"><summary class="small">Kendala aktif ({{ $activeIssues->count() }}) · {{ $order->issues->count() }} tercatat</summary>
@foreach($order->issues as $issue)<div class="timeline-event"><span class="badge {{ $issue->status === 'OPEN' ? 'warn' : 'good' }}">{{ $issue->status === 'OPEN' ? 'Aktif' : 'Selesai' }}</span><p class="preserve-lines">{{ $issue->description }}</p><div class="small muted">PIC: {{ $issue->pic }} · {{ $issue->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</div>
@foreach($issue->notes as $note)<p class="small preserve-lines">{{ $note->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB · {{ $note->note }}</p>@endforeach
@if($issue->status === 'RESOLVED')<p class="small preserve-lines">Diselesaikan {{ $issue->resolved_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB · {{ $issue->resolution }}</p>@endif</div>@endforeach
</details>
@endif
