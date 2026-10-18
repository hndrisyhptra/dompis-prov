<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    protected $primaryKey = 'id_branches';

    use SoftDeletes;

    protected $guarded = ['id_branches'];

    protected $fillable = ['code', 'name', 'regional', 'region_id', 'active'];

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function matchesRegion(Region $region): bool
    {
        return $this->region_id !== null ? (int) $this->region_id === $region->getKey() : $this->regional === $region->name;
    }

    public function serviceAreas()
    {
        return $this->hasMany(ServiceArea::class, 'id_branches');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
