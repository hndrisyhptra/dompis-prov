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
        $base = $this->scope->orders($user, $filters + ['job_type' => 'all']);
        $zero = array_fill_keys(array_keys(ProgressSummary::LABELS), 0);
        $summary = array_fill_keys(['total', 'ps_total', 'ps_today', 'incoming_today', 'outstanding', 'open_issues', 'waiting_bima', 'missing_ps_date', 'future_ps_date', 'unmapped'], 0);
        $rows = [];
        $branches = Branch::withTrashed()->with(['region' => fn ($q) => $q->withTrashed()])->orderBy('name')->get()->keyBy('id_branches');
        foreach ($branches as $branch) {
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
        $types = array_fill_keys(['NEW INSTALL', 'MODIFY', 'DISCONNECT', 'SUSPEND', 'RESUME'], array_fill_keys(['total', 'incoming_today', 'ps_total', 'ps_today', 'outstanding'], 0));
        $series = [];
        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            $date = $month->day($day);
            $series[$date->toDateString()] = ['date' => $date->toDateString(), 'label' => $date->format('d M'), 'day' => $day, 'count' => 0, 'future' => $date->isAfter($today)];
        }
        $lastPull = null;
        foreach ((clone $base)->select(['id_work_orders', 'id_branches', 'job_type', 'order_date', 'progress_category', 'operational_status', 'bima_status', 'bima_ps_date', 'branch_completed_at', 'last_pulled_at'])
            ->withCount(['issues as open_issues_count' => fn ($query) => $query->where('status', 'OPEN')])->cursor() as $order) {
            $branchKey = $order->id_branches ?? 'unmapped';
            $rows[$branchKey] ??= $this->branchRow($branches->get($order->id_branches), $zero);
            $bucket = ProgressSummary::bucket($order);
            $rows[$branchKey]['counts'][$bucket]++;
            $rows[$branchKey]['counts']['total']++;
            $type = strtoupper(trim($order->job_type ?? '', ' ')) ?: 'TANPA JENIS';
            $types[$type] ??= array_fill_keys(array_keys($types['NEW INSTALL']), 0);
            $summary['total']++;
            $types[$type]['total']++;
            if (! $order->id_branches) {
                $summary['unmapped']++;
            }
            if ($order->order_date?->toDateString() === $today->toDateString()) {
                $summary['incoming_today']++;
                $types[$type]['incoming_today']++;
            }
            if (! $order->terminal() && ! $order->branch_completed_at) {
                $summary['outstanding']++;
                $types[$type]['outstanding']++;
                if ($order->open_issues_count > 0) {
                    $summary['open_issues']++;
                }
            }
            if (strtoupper(trim($order->progress_category ?? '', ' ')) === 'CLOSE' && ! $order->isPsEligible()) {
                $summary['waiting_bima']++;
            }
            if ($order->isPsEligible()) {
                $summary['ps_total']++;
                $types[$type]['ps_total']++;
                $date = $order->bima_ps_date?->toDateString();
                if (! $date) {
                    $summary['missing_ps_date']++;
                } elseif ($date > $today->toDateString()) {
                    $summary['future_ps_date']++;
                }
                if ($date === $today->toDateString()) {
                    $summary['ps_today']++;
                    $types[$type]['ps_today']++;
                    $rows[$branchKey]['counts']['ps_today']++;
                }
                if ($date && isset($series[$date]) && ! $series[$date]['future']) {
                    $series[$date]['count']++;
                }
            }
            if ($order->last_pulled_at && (! $lastPull || $order->last_pulled_at->gt($lastPull))) {
                $lastPull = $order->last_pulled_at;
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
        if (($filters['job_type'] ?? 'all') !== 'all') {
            $types = array_intersect_key($types, [strtoupper(trim($filters['job_type'], ' ')) => true]);
        }

        return ['today' => $today, 'month' => $month, 'summary' => $summary, 'groups' => $groups, 'grand' => $grand, 'recap' => $types,
            'branch_count' => count(array_filter($rows, fn ($row) => $row['branch_id'] !== null)),
            'series' => array_values($series), 'chart_max' => max(1, ...array_column($series, 'count')), 'month_ps' => array_sum(array_column($series, 'count')),
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
