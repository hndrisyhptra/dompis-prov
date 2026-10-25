<?php

namespace App\Services\Boq;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

class BoqBreakdown
{
    public static function bucket(string $type): string
    {
        return match (strtoupper(trim($type))) {
            'MATERIAL' => 'material',
            'JASA', 'INSTALASI', 'PENGUKURAN', 'DISMANTLE' => 'labor',
            default => throw new InvalidArgumentException('Tipe designator belum memiliki kelompok nilai material/jasa: '.$type),
        };
    }

    public static function totals(iterable $items): array
    {
        $totals = ['material_total' => BigDecimal::of('0.00'), 'labor_total' => BigDecimal::of('0.00')];
        $volumes = [];
        foreach ($items as $item) {
            $bucket = self::bucket($item['type']);
            // Query builder rows may be floats on SQLite. Cast explicitly before Brick's int|string API.
            $totals[$bucket.'_total'] = $totals[$bucket.'_total']->plus((string) $item['total_price']);
            $unit = mb_strtolower(trim($item['unit']));
            $volumes[$unit] ??= ['unit' => $unit, 'material' => BigDecimal::of('0.000'), 'labor' => BigDecimal::of('0.000')];
            $volumes[$unit][$bucket] = $volumes[$unit][$bucket]->plus((string) $item['qty']);
        }
        ksort($volumes);

        return ['material_total' => (string) $totals['material_total'], 'labor_total' => (string) $totals['labor_total'],
            'grand_total' => (string) $totals['material_total']->plus($totals['labor_total']),
            'volumes' => array_map(fn ($row) => ['unit' => $row['unit'], 'material' => (string) $row['material'], 'labor' => (string) $row['labor']], array_values($volumes))];
    }
}
