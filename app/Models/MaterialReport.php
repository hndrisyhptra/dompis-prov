<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\MaterialReportFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MaterialReport extends Model
{
    protected $primaryKey = 'id_material_reports';

    protected $guarded = ['id_material_reports'];

    protected function casts(): array
    {
        return array_fill_keys(array_keys(MaterialReportFields::QUANTITIES), 'decimal:3') + array_fill_keys(array_keys(MaterialReportFields::COORDINATES), 'decimal:8');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->active || $user->role === Role::Bot) {
            return $query->whereRaw('1=0');
        }

        return $user->role->areaScope() ? $query : $query->whereIn('id_branches', $user->branches()->where('active', true)->select('branches.id_branches'));
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'id_branches')->withTrashed();
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'id_work_orders');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'id_users');
    }

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'id_supersedes_material_reports');
    }
}
