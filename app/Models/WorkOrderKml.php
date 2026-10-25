<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderKml extends Model
{
    protected $table = 'work_order_kmls';

    protected $primaryKey = 'id_work_order_kmls';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['geojson' => 'array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'id_users');
    }
}
