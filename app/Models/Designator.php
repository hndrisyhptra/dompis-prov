<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designator extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_designator';

    protected $guarded = ['id_designator'];

    public function type()
    {
        return $this->belongsTo(DesignatorType::class, 'designator_type_id');
    }

    protected function casts(): array
    {
        return [];
    }
}
