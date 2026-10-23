<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingOwnergrup extends Model
{
    protected $table = 'mapping_ownergrup';

    protected $primaryKey = 'id_mapping_ownergrup';

    protected $guarded = ['id_mapping_ownergrup'];

    public const GROUPS = ['DISTRICT', 'PMD', 'ED REGIONAL', 'SDI REGIONAL', 'FF SO 3', 'FF SO 4', 'DCS'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
