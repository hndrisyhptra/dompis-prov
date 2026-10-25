<?php

namespace App\Contracts;

interface TelegramGateway
{
    public function sendMessage(string $chatId, string $text): string;
}
