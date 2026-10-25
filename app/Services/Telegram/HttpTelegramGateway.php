<?php

namespace App\Services\Telegram;

use App\Contracts\TelegramGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpTelegramGateway implements TelegramGateway
{
    public function sendMessage(string $chatId, string $text): string
    {
        $response = $this->request('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]]);
        if (! is_int($response->json('result.message_id'))) {
            throw new TelegramException;
        }

        return (string) $response->json('result.message_id');
    }

    public function registerWebhook(string $url, string $secret): void
    {
        $response = $this->request('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => false, 'max_connections' => 20]);
        if ($response->json('result') !== true) {
            throw new TelegramException;
        }
    }

    private function request(string $method, array $data): Response
    {
        $token = (string) config('telegram.bot_token');
        if (! config('telegram.enabled') || ! preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $token)) {
            throw new TelegramException('Telegram belum dikonfigurasi.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(15)->asJson()->post('https://api.telegram.org/bot'.$token.'/'.$method, $data);
        } catch (Throwable) {
            // Http exceptions can contain the bot token in the URL. Never retain their message/previous exception.
            throw new TelegramException;
        }
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new TelegramException(retryAfter: max(10, min(3600, (int) $response->json('parameters.retry_after', 60))));
        }

        return $response;
    }
}
