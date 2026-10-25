<?php

return [
    'enabled' => (bool) env('TELEGRAM_MATERIAL_ENABLED', false),
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
];
