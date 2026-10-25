<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    protected $primaryKey = 'id_telegram_updates';

    protected $guarded = ['id_telegram_updates'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'processed_at' => 'datetime', 'next_attempt_at' => 'datetime'];
    }
}
