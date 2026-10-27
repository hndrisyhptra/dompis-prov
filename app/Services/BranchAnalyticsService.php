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
        foreach ((clone $base)->psEligible()->where('tgl_ps', '>=', $start->toDateString())->where('tgl_ps', '<', $today->addDay()->toDateString())
            ->selectRaw('DATE(work_orders.tgl_ps) AS ps_date, COUNT(*) AS total')->groupByRaw('DATE(work_orders.tgl_ps)')->toBase()->get() as $order) {
            $key = $order->ps_date;
            if (isset($series[$key])) {
                $series[$key]['count'] = (int) $order->total;
            }
        }
        $outstanding = (clone $base)->outstanding();
        $metrics = [
            'waiting_bima' => fn ($q) => $q->waitingBima(),
            'closed_by_branch' => fn ($q) => $q->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CLOSE']),
            'ps_total' => fn ($q) => $q->psEligible(), 'potential_ps' => fn ($q) => $q->potentialPs(), 'new_order' => fn ($q) => $q->newOrder(),
            'incoming' => fn ($q) => $q->where('tgl_order', '>=', $today->toDateString())->where('tgl_order', '<', $today->addDay()->toDateString()), 'complete' => fn ($q) => $q->psOnDate($today->toDateString()),
            'outstanding' => fn ($q) => $q->outstanding(), 'issues' => fn ($q) => $q->outstanding()->whereHas('issues', fn ($q) => $q->where('status', 'OPEN')),
            'unupdated' => fn ($q) => $q->outstanding()->whereNull('last_progress_at'), 'assigned_total' => fn ($q) => $q,
        ];
        $aggregate = app(OrderMetricAggregate::class)->query($base, $metrics)->selectRaw('MAX(work_orders.last_pulled_at) AS latest_pull')->toBase()->first();
        $counts = array_map(fn ($key) => (int) $aggregate->$key, array_keys($metrics));
        $counts = array_combine(array_keys($metrics), $counts);

        return $counts + [
            'mappingOrders' => app(MappingOrderAnalyticsService::class)->summary($base, false),
            'orderMonths' => app(OrderMonthAnalyticsService::class)->summary($base),
            'ownerGroups' => app(OwnerGroupAnalyticsService::class)->summary($base),
            'today' => $today,
            'series' => array_values($series),
            'period_complete' => array_sum(array_column($series, 'count')),
            'chart_max' => max(1, ...array_column($series, 'count')),
            'attention' => (clone $outstanding)->with('assignedBranch')->withCount(['issues as open_issues_count' => fn ($query) => $query->where('status', 'OPEN')])
                ->orderByDesc('open_issues_count')->orderByRaw('CASE WHEN last_progress_at IS NULL THEN 0 ELSE 1 END')->orderBy('tgl_order')->limit(6)->get(),
            'latest_pull' => $aggregate->latest_pull,
        ];
    }
}
