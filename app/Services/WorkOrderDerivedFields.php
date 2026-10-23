<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class WorkOrderDerivedFields
{
    public static function status(?string $task, ?string $status): ?string
    {
        $parts = array_filter([trim($task ?? ''), trim($status ?? '')], fn ($value) => $value !== '');

        return $parts ? implode('-', $parts) : null;
    }

    public static function dates(?string $date): array
    {
        if (! $date) {
            return ['bulan' => null, 'tahun' => null, 'umur_hari' => null, 'kategori_umur' => null];
        }
        $start = CarbonImmutable::parse($date, 'Asia/Jakarta')->startOfDay();
        $age = (int) $start->diffInDays(CarbonImmutable::now('Asia/Jakarta')->startOfDay(), false);

        return ['bulan' => $start->format('m'), 'tahun' => $start->format('Y'), 'umur_hari' => $age,
            'kategori_umur' => $age > 28 ? '> 28 HARI' : ($age > 14 ? '14-28 HARI' : ($age > 7 ? '7-14 HARI' : '0-7 HARI'))];
    }
}
