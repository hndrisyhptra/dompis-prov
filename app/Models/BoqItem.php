<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoqItem extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_boq_items';

    protected $guarded = ['id_boq_items'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price' => 'decimal:2', 'total_price' => 'decimal:2'];
    }
}
