<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QeBoqItem extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_qe_boq_items';

    protected $guarded = ['id_qe_boq_items'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price' => 'decimal:2', 'total_price' => 'decimal:2'];
    }
}
