<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BranchOrderScope
{
    public function branches(User $user): Collection
    {
        return $user->role->areaScope() ? Branch::withTrashed()->orderBy('name')->get()
            : $user->branches()->where('active', true)->orderBy('name')->get();
    }

    public function orders(User $user, array $filters): Builder
    {
        $query = WorkOrder::visibleTo($user);
        if (! empty($filters['branch'])) {
            abort_unless($this->branches($user)->contains('id_branches', (int) $filters['branch']), 403);
            $query->where('id_branches', $filters['branch']);
        }
        if (! empty($filters['region'])) {
            $query->whereIn('id_branches', Branch::withTrashed()->where('region_id', $filters['region'])->select('id_branches'));
        }
        if (! empty($filters['service'])) {
            $query->where('service_name', $filters['service']);
        }
        $type = $filters['job_type'] ?? 'NEW INSTALL';
        if ($type === 'TANPA JENIS') {
            $query->where(fn ($q) => $q->whereNull('job_type')->orWhereRaw('TRIM(job_type) = ?', [''])->orWhereRaw('UPPER(TRIM(job_type)) = ?', ['TANPA JENIS']));
        } elseif ($type !== 'all') {
            $query->whereRaw('UPPER(TRIM(job_type)) = ?', [strtoupper(trim($type, ' '))]);
        }

        return $query;
    }
}
