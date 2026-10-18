<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Region extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_regions';

    protected $guarded = ['id_regions'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'source_metadata' => 'array'];
    }

    public function branches()
    {
        return $this->hasMany(Branch::class, 'region_id');
    }

    public function serviceAreas()
    {
        return $this->hasMany(ServiceArea::class, 'id_regions');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'id_regions');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'id_users_created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'id_users_updated_by');
    }
}
