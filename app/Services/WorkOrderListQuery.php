<?php

namespace App\Services;

use App\Enums\ProgressCategory;
use App\Models\User;
use App\Support\OrderAging;
use App\Support\ProgressSummary;
use Carbon\CarbonImmutable;
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
        if (! empty($filters['ps_from']) && ! empty($filters['ps_to'])) {
            $end = CarbonImmutable::parse($filters['ps_to'], 'Asia/Jakarta')->min(CarbonImmutable::now('Asia/Jakarta')->startOfDay());
            $query->psEligible()->where('tgl_ps', '>=', $filters['ps_from'])->where('tgl_ps', '<', $end->addDay()->toDateString());
        }
        if (! empty($filters['aging_bucket']) && ! empty($filters['aging_on'])) {
            OrderAging::filter($query, $filters['aging_bucket'], $filters['aging_on']);
        }

        return MappingOrderAnalyticsService::applyDetailFilters($query, $filters);
    }
}
