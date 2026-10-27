<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Support\ProgressSummary;
use Carbon\CarbonImmutable;

class AreaAnalyticsService
{
    public function __construct(private BranchOrderScope $scope) {}

    public function summary(User $user, array $filters): array
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'] ?? $today->format('Y-m'), 'Asia/Jakarta');
        $tab = $filters['tab'] ?? 'progress';
        $progress = $tab === 'progress';
        $achPs = $tab === 'ach-ps';
        $base = $this->scope->orders($user, $filters + ['jenis_pekerjaan' => 'all']);
        $zero = array_fill_keys(array_keys(ProgressSummary::LABELS), 0);
        $summary = array_fill_keys(['total', 'ps_total', 'ps_today', 'incoming_today', 'outstanding', 'open_issues', 'waiting_bima', 'missing_ps_date', 'future_ps_date', 'unmapped', 'unupdated', 'closed_by_branch'], 0);
        $rows = [];
        $summary['potential_ps'] = 0;
        $summary['new_order'] = 0;
        // Share this request's master lookup with Mapping Order; no cross-user cache.
        $branches = $progress || $achPs ? Branch::withTrashed()->with(['region' => fn ($q) => $q->withTrashed()])->orderBy('name')->get()->keyBy('id_branches') : collect();
        foreach ($progress ? $branches : [] as $branch) {
            if (($filters['region'] ?? null) && (int) $branch->region_id !== (int) $filters['region']) {
                continue;
            }
            if (($filters['branch'] ?? null) && $branch->getKey() !== (int) $filters['branch']) {
                continue;
            }
            if ($branch->active && ! $branch->trashed()) {
                $rows[$branch->getKey()] = $this->branchRow($branch, $zero);
            }
        }
        $series = [];
        for ($day = 1; $achPs && $day <= $month->daysInMonth; $day++) {
            $date = $month->day($day);
            $series[$date->toDateString()] = ['date' => $date->toDateString(), 'label' => $date->format('d M'), 'day' => $day, 'count' => 0, 'future' => $date->isAfter($today)];
        }
        $metrics = [
            'total' => fn ($q) => $q,
            'ps_total' => fn ($q) => $q->psEligible(),
            'ps_today' => fn ($q) => $q->psOnDate($today->toDateString()),
            'incoming_today' => fn ($q) => $q->where('tgl_order', '>=', $today->toDateString())->where('tgl_order', '<', $today->addDay()->toDateString()),
            'outstanding' => fn ($q) => $q->outstanding(),
            'open_issues' => fn ($q) => $q->outstanding()->whereHas('issues', fn ($q) => $q->where('status', 'OPEN')),
            'waiting_bima' => fn ($q) => $q->waitingBima(),
            'missing_ps_date' => fn ($q) => $q->psEligible()->whereNull('tgl_ps'),
            'future_ps_date' => fn ($q) => $q->psEligible()->where('tgl_ps', '>=', $today->addDay()->toDateString()),
            'unmapped' => fn ($q) => $q->whereNull('id_branches'),
            'unupdated' => fn ($q) => $q->outstanding()->whereNull('last_progress_at'),
            'closed_by_branch' => fn ($q) => $q->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CLOSE']),
            'potential_ps' => fn ($q) => $q->potentialPs(), 'new_order' => fn ($q) => $q->newOrder(),
        ];
        foreach ($progress ? [...array_keys(ProgressSummary::GROUPS), 'other'] : [] as $bucket) {
            $metrics['bucket_'.$bucket] = fn ($q) => ProgressSummary::filter($q, $bucket);
        }
        $aggregateQuery = app(OrderMetricAggregate::class)->query($base, $metrics)
            ->selectRaw('MAX(work_orders.last_pulled_at) AS latest_pull');
        if ($progress) {
            $aggregateQuery->addSelect('work_orders.id_branches')->groupBy('work_orders.id_branches');
        }
        $aggregates = $aggregateQuery->toBase()->get();
        $lastPull = null;
        foreach ($aggregates as $aggregate) {
            if ($progress) {
                $branchKey = $aggregate->id_branches ?? 'unmapped';
                $rows[$branchKey] ??= $this->branchRow($branches->get($aggregate->id_branches), $zero);
                foreach (array_keys($zero) as $key) {
                    $metric = in_array($key, ['incoming_today', 'ps_today', 'total'], true) ? $key : 'bucket_'.$key;
                    $rows[$branchKey]['counts'][$key] += (int) $aggregate->$metric;
                }
            }
            foreach (array_keys($summary) as $key) {
                $summary[$key] += (int) $aggregate->$key;
            }
            if ($aggregate->latest_pull && (! $lastPull || $aggregate->latest_pull > $lastPull)) {
                $lastPull = $aggregate->latest_pull;
            }
        }
        $lastPull = $lastPull ? CarbonImmutable::parse($lastPull, 'UTC') : null;
        $dailyAggregates = $achPs ? (clone $base)->psEligible()->where('tgl_ps', '>=', $month->toDateString())->where('tgl_ps', '<', $month->addMonth()->toDateString())
            ->where('tgl_ps', '<', $today->addDay()->toDateString())->selectRaw('work_orders.id_branches, DATE(work_orders.tgl_ps) AS ps_date, COUNT(*) AS total')
            ->groupBy('work_orders.id_branches')->groupByRaw('DATE(work_orders.tgl_ps)')->toBase()->get() : collect();
        foreach ($dailyAggregates as $daily) {
            if (isset($series[$daily->ps_date])) {
                $series[$daily->ps_date]['count'] += (int) $daily->total;
            }
        }
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['region_id'] ?? 'unmapped';
            $groups[$key] ??= ['name' => $row['region_name'], 'code' => $row['region_code'], 'region_id' => $row['region_id'], 'rows' => [], 'totals' => $zero];
            $groups[$key]['rows'][] = $row;
            foreach ($row['counts'] as $column => $count) {
                $groups[$key]['totals'][$column] += $count;
            }
        }
        uasort($groups, fn ($a, $b) => (['JATENGDIY' => 0, 'JATIM' => 1, 'BALNUS' => 2][$a['code']] ?? 3) <=> (['JATENGDIY' => 0, 'JATIM' => 1, 'BALNUS' => 2][$b['code']] ?? 3));
        $grand = $zero;
        foreach ($groups as $group) {
            foreach ($group['totals'] as $key => $value) {
                $grand[$key] += $value;
            }
        }

        return ['mappingOrders' => $progress ? app(MappingOrderAnalyticsService::class)->summary($base, true, $filters['jenis_pekerjaan'] ?? 'all', $branches) : null,
            'psDailyTable' => $achPs ? app(DailyPsTableService::class)->build($dailyAggregates, $branches, $month, $today, ($filters['ps_view'] ?? 'recent') === 'month') : null,
            'orderMonths' => $progress ? app(OrderMonthAnalyticsService::class)->summary($base) : null,
            'ownerGroups' => $tab === 'owner-group' ? app(OwnerGroupAnalyticsService::class)->summary($base) : null,
            'today' => $today, 'month' => $month, 'summary' => $summary, 'groups' => $groups, 'grand' => $grand,
            'branch_count' => count(array_filter($rows, fn ($row) => $row['branch_id'] !== null)),
            'series' => array_values($series), 'chart_max' => max([1, ...array_column($series, 'count')]), 'month_ps' => $achPs ? array_sum(array_column($series, 'count')) : null,
            'completion_rate' => $summary['total'] ? round($summary['ps_total'] / $summary['total'] * 100, 1) : 0, 'latest_pull' => $lastPull];
    }

    private function branchRow(?Branch $branch, array $zero): array
    {
        return ['branch_id' => $branch?->getKey(), 'name' => $branch?->name ?? 'Belum terpetakan',
            'inactive' => $branch && (! $branch->active || $branch->trashed()), 'region_id' => $branch?->region_id,
            'region_name' => $branch?->region?->name ?? 'Tanpa regional', 'region_code' => $branch?->region?->code,
            'counts' => $zero];
    }
}
