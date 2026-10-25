<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Material\TelegramMaterialService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public int $updateId) {}

    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(TelegramMaterialService $service): void
    {
        if (! config('telegram.enabled')) {
            return;
        }
        $lock = Cache::lock('telegram:inbox:'.$this->updateId, 60);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $inbox = TelegramUpdate::findOrFail($this->updateId);
            if (! in_array($inbox->state, ['pending', 'failed'], true) || $inbox->attempts >= 10 || $inbox->next_attempt_at?->isFuture()) {
                return;
            }
            $inbox->increment('attempts');
            $outbox = $service->process($this->updateId);
            if ($outbox) {
                DeliverTelegramMessage::dispatch($outbox)->afterCommit();
            }
        } catch (Throwable) {
            try {
                $attempts = TelegramUpdate::whereKey($this->updateId)->value('attempts') ?? 1;
                TelegramUpdate::whereKey($this->updateId)->whereIn('state', ['pending', 'failed'])->update(['state' => 'failed', 'error_code' => 'processing_failed', 'next_attempt_at' => now()->addSeconds(min(3600, 15 * (2 ** min($attempts, 7))))]);
            } catch (Throwable) {
                // Keep potentially sensitive database/HTTP errors out of queue logs.
            }
            throw new RuntimeException('Pemrosesan laporan Telegram belum berhasil. Periksa inbox dan lakukan retry.');
        } finally {
            $lock->release();
        }
    }
}
