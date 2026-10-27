<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OrderMonthAnalyticsService
{
    public function summary(Builder $base): array
    {
        $groups = [];
        $undated = 0;
        $metrics = ['total' => fn ($q) => $q, 'closed' => fn ($q) => $q->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CLOSE']),
            'ps' => fn ($q) => $q->psEligible(), 'outstanding' => fn ($q) => $q->outstanding()];
        $rows = app(OrderMetricAggregate::class)->query($base, $metrics)->selectRaw('SUBSTR(work_orders.tgl_order, 1, 7) AS order_month')->groupByRaw('SUBSTR(work_orders.tgl_order, 1, 7)')->toBase()->get();
        foreach ($rows as $row) {
            if ($row->order_month === null) {
                $undated += (int) $row->total;

                continue;
            }
            $month = $row->order_month;
            $groups[$month] = ['month' => $month, 'label' => CarbonImmutable::createFromFormat('!Y-m', $month)->locale('id')->translatedFormat('F Y')];
            foreach (array_keys($metrics) as $metric) {
                $groups[$month][$metric] = (int) $row->$metric;
            }
        }
        krsort($groups);

        return ['rows' => array_values($groups), 'undated' => $undated];
    }
}
