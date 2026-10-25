<?php

namespace App\Console\Commands;

use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RetryTelegramMaterial extends Command
{
    protected $signature = 'olo:telegram-retry {--manual : Tambahan retry balasan, batas total 10 percobaan}';

    protected $description = 'Antrekan ulang inbox material pending/failed dan balasan Telegram yang sudah jatuh tempo';

    public function handle(): int
    {
        if (! config('telegram.enabled') || ! Schema::hasTable('telegram_updates')) {
            $this->info('Telegram material belum diaktifkan. Tidak ada pesan dikirim.');

            return self::SUCCESS;
        }
        $inbox = TelegramUpdate::whereIn('state', ['pending', 'failed'])->where('attempts', '<', $this->option('manual') ? 10 : 5)->where('updated_at', '<=', now()->subMinutes(2))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id_telegram_updates')->limit(100)->pluck('id_telegram_updates');
        $outbox = TelegramOutbox::whereIn('state', ['pending', 'failed'])->where('attempts', '<', $this->option('manual') ? 10 : 5)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id_telegram_outbox')->limit(100)->pluck('id_telegram_outbox');
        foreach ($inbox as $id) {
            ProcessTelegramUpdate::dispatch($id);
        }
        foreach ($outbox as $id) {
            DeliverTelegramMessage::dispatch($id);
        }
        $this->info(count($inbox).' inbox dan '.count($outbox).' balasan diantrekan.');

        return self::SUCCESS;
    }
}
