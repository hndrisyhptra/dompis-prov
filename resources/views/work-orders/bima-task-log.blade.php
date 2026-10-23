<section id="bima-task-log" class="panel gap-top"><div class="panel-head"><h2>Log Task BIMA</h2><span class="small muted">{{ $taskLogs->total() }} pembaruan</span></div><div class="panel-body">
<div class="notice"><span class="small muted">Status order saat ini</span><p class="preserve-lines"><strong>{{ $order->status_order ?: 'Belum tersedia' }}</strong></p><span class="small">Mapping: {{ $order->mapping_order ?: 'Belum dipetakan' }}</span><p class="small muted">Data BIMA terakhir diterima: @if($order->last_pulled_at)<time datetime="{{ $order->last_pulled_at->toIso8601String() }}">{{ $order->last_pulled_at->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB</time>@else Belum tersedia @endif</p></div>
@forelse($taskLogs as $log)
<article class="timeline-event"><div class="small muted"><time datetime="{{ $log->changed_at->toIso8601String() }}">{{ $log->changed_at->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB</time> · {{ $log->actor?->name ?? 'Sinkronisasi' }}</div>
@if($log->old_status_order !== $log->new_status_order || !$log->field_changes)
<dl class="meta-list"><div><dt>Status sebelumnya</dt><dd>{{ $log->old_status_order ?: '—' }}</dd></div><div><dt>Status setelah pembaruan</dt><dd>{{ $log->new_status_order ?: '—' }}</dd></div></dl>
@endif
@foreach($log->field_changes ?? [] as $change)
<div class="bima-field-change"><strong class="small">{{ $change['label'] }}</strong><dl class="meta-list"><div><dt>Sebelumnya</dt><dd class="preserve-lines">{{ $change['before'] ?? '—' }}</dd></div><div><dt>Menjadi</dt><dd class="preserve-lines">{{ $change['after'] ?? '—' }}</dd></div></dl></div>
@endforeach
</article>
@empty
<p class="muted gap-top">Belum ada perubahan BIMA yang tercatat. Perubahan task, status dan atribut sumber pada upload berikutnya akan muncul di sini.</p>
@endforelse
{{ $taskLogs->fragment('bima-task-log')->links() }}
</div></section>
