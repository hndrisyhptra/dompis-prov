<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QeBoqHistory extends Model
{
    protected $primaryKey = 'id_qe_boq_histories';

    protected $guarded = ['id_qe_boq_histories'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'id_users');
    }

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array'];
    }
}
