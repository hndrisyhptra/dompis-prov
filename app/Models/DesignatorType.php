<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DesignatorType extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_designator_type';

    protected $guarded = ['id_designator_type'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
