<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PsEligibility
{
    public const STATUSES = [
        'Approval E2E Testing Bitstream NNI-COMPLETE',
        'Approval E2E Testing Bitstream Transport-COMPLETE',
        'Approval E2E Testing Manual-COMPLETE',
        'Approval E2E Testing VPN-COMPLETE',
        'Approval E2E Testing-COMPLETE',
        'Approval End to End Test-COMPLETE',
        'Approval Project Management-COMPLETE',
        '-COMPWORK',
        'Modify Access VPN-COMPLETE',
        'Suspend METRO Manual-COMPLETE',
        'Testing and Approval METRO Manual-COMPLETE',
        'Testing and Approval-COMPLETE',
        'Testing dan Approval WDM-COMPLETE',
        'Testing dan Approval-COMPLETE',
    ];

    public static function normalizedStatuses(): array
    {
        return array_map('strtoupper', self::STATUSES);
    }

    public static function closedOrders(Builder $query, bool $eligible): Builder
    {
        $binary = $query->getConnection()->getDriverName() === 'mysql' ? 'BINARY ' : '';
        $query->whereRaw($binary.'UPPER(TRIM(work_orders.progress_category)) = ?', ['CLOSE']);

        return self::sourceOrders($query, $eligible);
    }

    public static function sourceOrders(Builder $query, bool $eligible = true): Builder
    {
        $binary = $query->getConnection()->getDriverName() === 'mysql' ? 'BINARY ' : '';
        $key = $binary."UPPER(TRIM(COALESCE(work_orders.status_order, '')))";
        $raw = $binary."UPPER(TRIM(COALESCE(work_orders.status_bima, '')))";
        // Legacy empty-task COMPWORK has no leading '-'. Read it canonically without rewriting the source.
        $expression = "CASE WHEN {$key} = 'COMPWORK' AND LENGTH(TRIM(COALESCE(work_orders.task_bima, ''))) = 0 AND {$raw} = 'COMPWORK' THEN '-COMPWORK' ELSE {$key} END";
        $method = $eligible ? 'whereIn' : 'whereNotIn';

        return $query->$method(DB::raw($binary.'('.$expression.')'), self::normalizedStatuses());
    }

    public static function matches(?string $progress, ?string $statusOrder, ?string $task, ?string $statusBima): bool
    {
        return strtoupper(trim($progress ?? '', ' ')) === 'CLOSE' && self::sourceMatches($statusOrder, $task, $statusBima);
    }

    public static function sourceMatches(?string $statusOrder, ?string $task, ?string $statusBima): bool
    {
        $key = strtoupper(trim($statusOrder ?? '', ' '));
        if ($key === 'COMPWORK' && trim($task ?? '', ' ') === '' && strtoupper(trim($statusBima ?? '', ' ')) === 'COMPWORK') {
            $key = '-COMPWORK';
        }

        return in_array($key, self::normalizedStatuses(), true);
    }
}
