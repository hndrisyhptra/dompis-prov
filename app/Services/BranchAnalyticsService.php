<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

class BranchAnalyticsService
{
    public function __construct(private BranchOrderScope $scope) {}

    public function summary(User $user, array $filters): array
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $days = (int) ($filters['days'] ?? 14);
        $start = $today->subDays($days - 1);
        $base = $this->scope->orders($user, $filters);
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->addDays($i);
            $series[$date->format('Y-m-d')] = ['date' => $date->format('Y-m-d'), 'label' => $date->format('d M'), 'count' => 0];
        }
        // PS is confirmed by both statuses; calendar date comes from BIMA TGL PS.
        foreach ((clone $base)->psEligible()->whereDate('bima_ps_date', '>=', $start->toDateString())->whereDate('bima_ps_date', '<=', $today->toDateString())->select(['id_work_orders', 'bima_ps_date'])->cursor() as $order) {
            $key = $order->bima_ps_date->toDateString();
            if (isset($series[$key])) {
                $series[$key]['count']++;
            }
        }
        $outstanding = (clone $base)->outstanding();

        return [
            'today' => $today,
            'incoming' => (clone $base)->whereDate('order_date', $today->format('Y-m-d'))->count(),
            'complete' => (clone $base)->psOnDate($today->toDateString())->count(),
            'outstanding' => (clone $outstanding)->count(),
            'issues' => (clone $outstanding)->whereHas('issues', fn ($query) => $query->where('status', 'OPEN'))->count(),
            'unupdated' => (clone $outstanding)->whereNull('last_progress_at')->count(),
            'series' => array_values($series),
            'period_complete' => array_sum(array_column($series, 'count')),
            'chart_max' => max(1, ...array_column($series, 'count')),
            'attention' => (clone $outstanding)->with('branch')->withCount(['issues as open_issues_count' => fn ($query) => $query->where('status', 'OPEN')])
                ->orderByDesc('open_issues_count')->orderByRaw('CASE WHEN last_progress_at IS NULL THEN 0 ELSE 1 END')->orderBy('order_date')->limit(6)->get(),
            'latest_pull' => (clone $base)->max('last_pulled_at'),
            'assigned_total' => (clone $base)->count(),
        ];
    }
}
