<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DailyPsTableService
{
    /** Fold the already scoped PS aggregates; no per-Branch query or WO hydration. */
    public function build(Collection $aggregates, Collection $branches, CarbonImmutable $month, CarbonImmutable $today, bool $fullMonth): array
    {
        $calendar = [];
        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            $date = $month->day($day);
            $calendar[$date->toDateString()] = ['date' => $date->toDateString(), 'label' => $date->format('d M'), 'future' => $date->isAfter($today)];
        }
        $elapsed = array_filter($calendar, fn ($date) => ! $date['future']);
        $dates = $fullMonth ? $calendar : ($elapsed ? array_slice($elapsed, -15, null, true) : array_slice($calendar, 0, 15, true));
        $zero = array_fill_keys(array_keys($dates), 0);
        $rows = [];
        $totals = $zero;
        $monthTotal = 0;
        foreach ($aggregates as $aggregate) {
            if (! isset($calendar[$aggregate->ps_date]) || $calendar[$aggregate->ps_date]['future']) {
                continue;
            }
            $key = $aggregate->id_branches ?? 'unassigned';
            $rows[$key] ??= ['name' => $branches->get($aggregate->id_branches)?->name ?? 'Belum ditugaskan',
                'filters' => ['branch' => $aggregate->id_branches, 'unassigned' => $aggregate->id_branches === null ? 1 : null],
                'counts' => $zero, 'total' => 0, 'month_total' => 0];
            $count = (int) $aggregate->total;
            $rows[$key]['month_total'] += $count;
            $monthTotal += $count;
            if (array_key_exists($aggregate->ps_date, $dates)) {
                $rows[$key]['counts'][$aggregate->ps_date] += $count;
                $rows[$key]['total'] += $count;
                $totals[$aggregate->ps_date] += $count;
            }
        }
        uasort($rows, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $start = array_key_first($dates);
        $end = array_key_last($dates);

        return ['rows' => $rows, 'dates' => array_values($dates), 'totals' => $totals, 'total' => array_sum($totals), 'month_total' => $monthTotal,
            'full_month' => $fullMonth, 'start' => $start, 'end' => $end, 'period_filters' => ['ps_from' => $start, 'ps_to' => $end]];
    }
}
