<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingOrder extends Model
{
    protected $table = 'mapping_order';

    protected $primaryKey = 'id_mapping_order';

    protected $guarded = ['id_mapping_order'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
