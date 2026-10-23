<?php

namespace App\Support;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

class ProgressSummary
{
    public const GROUPS = [
        'survey' => ['SURVEY'], 'bima' => ['OGP BIMA', 'RESUBMIT AO'],
        'cndc' => ['CNDC'], 'kendala' => ['KENDALA', 'HOLD', 'BUTUH QE', 'PERIZINAN'], 'jt' => ['JT'],
        'cancel' => ['CANCEL'], 'close' => ['CLOSE'],
    ];

    public const LABELS = ['survey' => 'Survey', 'bima' => 'Proses BIMA', 'cndc' => 'CNDC',
        'kendala' => 'Kendala', 'jt' => 'Proses JT', 'other' => 'Lainnya',
        'cancel' => 'Cancel', 'close' => 'Close', 'ps_today' => 'PS hari ini', 'total' => 'Total order'];

    public const EXPRESSION = "COALESCE(NULLIF(UPPER(TRIM(progress_category)), ''), CASE operational_status WHEN 'COMPLETED' THEN 'CLOSE' WHEN 'CANCELLED' THEN 'CANCEL' WHEN 'BLOCKED' THEN 'KENDALA' ELSE operational_status END)";

    public static function bucket(WorkOrder $order): string
    {
        $category = strtoupper(trim($order->progress_category ?? '', ' ')) ?: match ($order->operational_status) {
            'COMPLETED' => 'CLOSE', 'CANCELLED' => 'CANCEL', 'BLOCKED' => 'KENDALA', default => $order->operational_status,
        };
        foreach (self::GROUPS as $key => $values) {
            if (in_array($category, $values, true)) {
                return $key;
            }
        }

        return 'other';
    }

    public static function filter(Builder $query, string $bucket): Builder
    {
        if ($bucket === 'other') {
            $values = array_merge(...array_values(self::GROUPS));

            return $query->whereRaw(self::EXPRESSION.' NOT IN ('.implode(',', array_fill(0, count($values), '?')).')', $values);
        }
        $values = self::GROUPS[$bucket];

        return $query->whereRaw(self::EXPRESSION.' IN ('.implode(',', array_fill(0, count($values), '?')).')', $values);
    }
}
