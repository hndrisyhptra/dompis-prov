<?php

namespace Tests\Fakes;

use App\Contracts\TelegramGateway as Contract;
use App\Services\Telegram\TelegramException;

class TelegramGateway implements Contract
{
    public array $messages = [];

    public bool $fail = false;

    public function sendMessage(string $chatId, string $text): string
    {
        if ($this->fail) {
            throw new TelegramException;
        }
        $this->messages[] = compact('chatId', 'text');

        return (string) count($this->messages);
    }
}
