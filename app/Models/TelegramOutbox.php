<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramOutbox extends Model
{
    protected $table = 'telegram_outbox';

    protected $primaryKey = 'id_telegram_outbox';

    protected $guarded = ['id_telegram_outbox'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
