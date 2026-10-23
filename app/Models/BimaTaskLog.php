<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BimaTaskLog extends Model
{
    protected $primaryKey = 'id_bima_task_logs';

    public $timestamps = false;

    protected $guarded = ['id_bima_task_logs'];

    protected $hidden = ['field_changes'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime', 'field_changes' => 'encrypted:array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'id_users');
    }
}
