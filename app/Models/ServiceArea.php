<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceArea extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'id_service_areas';

    protected $guarded = ['id_service_areas'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'source_metadata' => 'array'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'id_branches');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'id_regions');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'id_service_areas');
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
