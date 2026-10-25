<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramIdentity extends Model
{
    protected $primaryKey = 'id_telegram_identities';

    protected $guarded = ['id_telegram_identities'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_users');
    }
}
