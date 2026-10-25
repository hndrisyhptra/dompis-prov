<?php

namespace App\Services\Telegram;

use RuntimeException;

class TelegramException extends RuntimeException
{
    public function __construct(string $message = 'Pengiriman Telegram belum berhasil.', public int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
