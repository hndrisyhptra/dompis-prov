<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OrderMonthAnalyticsService
{
    public function summary(Builder $base): array
    {
        $groups = [];
        $queries = ['total' => clone $base,
            'closed' => (clone $base)->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CLOSE']),
            'ps' => (clone $base)->psEligible(), 'outstanding' => (clone $base)->outstanding()];
        foreach ($queries as $metric => $query) {
            foreach ($query->whereNotNull('tgl_order')->selectRaw('SUBSTR(work_orders.tgl_order, 1, 7) AS order_month, COUNT(*) AS total')->groupByRaw('SUBSTR(work_orders.tgl_order, 1, 7)')->get() as $row) {
                $month = $row->order_month;
                $groups[$month] ??= ['month' => $month, 'label' => CarbonImmutable::createFromFormat('!Y-m', $month)->locale('id')->translatedFormat('F Y'),
                    'total' => 0, 'closed' => 0, 'ps' => 0, 'outstanding' => 0];
                $groups[$month][$metric] = (int) $row->total;
            }
        }
        krsort($groups);

        return ['rows' => array_values($groups), 'undated' => (clone $base)->whereNull('tgl_order')->count()];
    }
}
