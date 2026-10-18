<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WoStatusEvent extends Model
{
    protected $primaryKey = 'id_wo_status_events';

    public $timestamps = false;

    protected $guarded = ['id_wo_status_events'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'id_users');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
