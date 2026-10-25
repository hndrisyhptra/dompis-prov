<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DesignatorPackagePrice extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_designator_package_price';

    protected $guarded = ['id_designator_package_price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
