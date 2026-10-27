<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OrderAging
{
    public const LABELS = ['0_7' => '0-7 HARI', '7_14' => '7-14 HARI', '14_28' => '14-28 HARI', 'over_28' => '> 28 HARI', 'unknown' => 'Tanggal belum terisi'];

    /** Match the owner's >28 / >14 / >7 formula against calendar dates, not stored age. */
    public static function expression(CarbonImmutable $date): array
    {
        return ["CASE WHEN work_orders.tgl_order IS NULL THEN 'unknown' WHEN work_orders.tgl_order < ? THEN 'over_28' WHEN work_orders.tgl_order < ? THEN '14_28' WHEN work_orders.tgl_order < ? THEN '7_14' ELSE '0_7' END",
            [$date->subDays(28)->toDateString(), $date->subDays(14)->toDateString(), $date->subDays(7)->toDateString()]];
    }

    public static function filter(Builder $query, string $bucket, string $date): Builder
    {
        [$expression, $bindings] = self::expression(CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Jakarta'));

        return $query->whereRaw($expression.' = ?', [...$bindings, $bucket]);
    }
}
