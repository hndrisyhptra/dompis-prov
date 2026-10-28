<form method="POST" action="{{ route('priorities.reset') }}" data-loading="Melepas bookmark…" data-confirm-title="Reset Bookmark {{ $area ? 'Area' : 'Branch' }}" data-confirm-yes="Ya, Lepas Semua"
        data-confirm-message="Lepas seluruh {{ number_format($resetPreview['count']) }} bookmark {{ $area ? 'Area' : 'lokal tim Branch dalam penugasan aktif Anda' }} di semua halaman? Filter daftar tidak membatasi reset. {{ $area ? 'Bookmark lokal Branch tetap tersimpan.' : 'Arahan Area dan bookmark Branch lain tetap tersimpan.' }} Work Order dan seluruh riwayat tidak dihapus.">
        @csrf @method('DELETE')
        <input type="hidden" name="reset_token" value="{{ $resetPreview['token'] }}">
        <input type="hidden" name="confirmed" value="1">
        <button type="submit" class="button secondary compact-button master-delete-button" aria-describedby="priority-reset-help" @disabled(! $resetPreview['count']) title="Lepas semua bookmark {{ $area ? 'Area' : 'lokal Branch' }} · {{ number_format($resetPreview['count']) }} pilihan"><x-icon name="reset-book"/>Reset Bookmark</button>
</form>
