<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_package';

    protected $guarded = ['id_package'];

    protected function casts(): array
    {
        return [];
    }
}
