<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkOrderPriority extends Model
{
    protected $primaryKey = 'id_work_order_priorities';

    protected $guarded = ['id_work_order_priorities'];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'id_work_orders');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'id_users');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->active || ! $user->role->priorityOrders()) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('workOrder', fn ($q) => $q->visibleTo($user));
        if ($user->role->areaScope()) {
            return $query->where('scope_key', 'area')->whereNull('id_branches');
        }

        return $query->where(fn ($q) => $q->where(fn ($q) => $q->where('scope_key', 'area')->whereNull('id_branches'))
            ->orWhere(fn ($q) => $q->whereNotNull('id_branches')->whereHas('workOrder', fn ($q) => $q->whereColumn('work_orders.id_branches', 'work_order_priorities.id_branches'))));
    }
}
