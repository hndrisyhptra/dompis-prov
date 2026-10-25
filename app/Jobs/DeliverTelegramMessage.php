<?php

namespace App\Jobs;

use App\Contracts\TelegramGateway;
use App\Models\TelegramOutbox;
use App\Services\Telegram\TelegramException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class DeliverTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $outboxId) {}

    public function handle(TelegramGateway $gateway): void
    {
        if (! config('telegram.enabled')) {
            return;
        }
        $lock = Cache::lock('telegram:outbox:'.$this->outboxId, 60);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $outbox = TelegramOutbox::findOrFail($this->outboxId);
            if ($outbox->state === 'sent' || $outbox->attempts >= 10 || ($outbox->next_attempt_at && $outbox->next_attempt_at->isFuture())) {
                return;
            }
            $outbox->increment('attempts');
            try {
                $id = $gateway->sendMessage((string) $outbox->telegram_chat_id, $outbox->payload['text']);
                $outbox->update(['state' => 'sent', 'telegram_message_id' => $id, 'sent_at' => now(), 'next_attempt_at' => null, 'error_code' => null]);
            } catch (TelegramException $exception) {
                $delay = max($exception->retryAfter, min(3600, 15 * (2 ** min($outbox->attempts, 7))));
                $outbox->update(['state' => 'failed', 'error_code' => 'delivery_unavailable', 'next_attempt_at' => now()->addSeconds($delay)]);
                if ($outbox->attempts < 5) {
                    $this->release($delay);
                }
            }
        } finally {
            $lock->release();
        }
    }
}
