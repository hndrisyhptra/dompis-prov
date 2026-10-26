<?php

namespace App\Services;

use App\Enums\ProgressCategory;
use App\Models\User;
use App\Support\ProgressSummary;
use Illuminate\Database\Eloquent\Builder;

class WorkOrderListQuery
{
    public function __construct(private BranchOrderScope $scope) {}

    public function query(User $user, array $filters): Builder
    {
        $query = $this->scope->orders($user, $filters)
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->where('no_tq_sc_id', 'like', '%'.$value.'%')->orWhere('sid', 'like', '%'.$value.'%')->orWhere('nama_olo', 'like', '%'.$value.'%')))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where(ProgressCategory::tryFrom($value) ? 'progress_category' : 'operational_status', $value));
        if (! empty($filters['bucket'])) {
            ProgressSummary::filter($query, $filters['bucket']);
        }
        if (! empty($filters['ps_date'])) {
            $query->psOnDate($filters['ps_date']);
        }

        return $query;
    }
}
