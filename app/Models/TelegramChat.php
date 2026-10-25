<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramChat extends Model
{
    protected $primaryKey = 'id_telegram_chats';

    protected $guarded = ['id_telegram_chats'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'id_branches');
    }
}
