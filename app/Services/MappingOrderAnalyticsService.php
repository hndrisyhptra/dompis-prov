<?php

namespace App\Services;

use App\Models\Branch;
use App\Support\OrderAging;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MappingOrderAnalyticsService
{
    public const CATEGORIES = ['PS', 'CANCLWORK', 'OPEN', 'STUCK'];

    public static function applyDetailFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['mapping_order'])) {
            $filters['mapping_order'] === 'open_stuck'
                ? $query->whereRaw(self::categoryExpression($query).' IN (?, ?)', ['OPEN', 'STUCK'])
                : $query->whereRaw(self::categoryExpression($query).' = ?', [$filters['mapping_order']]);
        }
        if (! empty($filters['sto_missing'])) {
            $query->whereRaw(self::stoExpression($query).' = ?', ['']);
        } elseif (isset($filters['sto']) && $filters['sto'] !== '') {
            $query->whereRaw(self::stoExpression($query).' = ?', [$filters['sto']]);
        }

        return $query;
    }

    public function summary(Builder $base, bool $area, string $jobType = 'all', ?Collection $branches = null): array
    {
        $category = self::categoryExpression($base);
        // Classify within the scoped row query, then group plain derived columns.
        // MariaDB ONLY_FULL_GROUP_BY rejects some nested NULLIF/TRIM expressions otherwise.
        $rows = (clone $base)->reorder()->setEagerLoads([])->select('work_orders.id_branches')
            ->selectRaw($category.' AS mapping_category');
        if (! $area) {
            $sto = self::stoExpression($base);
            $rows->selectRaw($sto.' AS sto_key');
        } else {
            $rows->selectRaw("COALESCE(NULLIF(UPPER(TRIM(work_orders.jenis_pekerjaan)), ''), 'TANPA JENIS') AS job_type");
            $agingDate = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
            [$agingExpression, $agingBindings] = OrderAging::expression($agingDate);
            $rows->selectRaw($agingExpression.' AS aging_bucket', $agingBindings);
        }
        $query = $base->getConnection()->query()->fromSub($rows->toBase(), 'mapping_rows')
            ->select('id_branches', 'mapping_category')->selectRaw('COUNT(*) AS order_count')->groupBy('id_branches', 'mapping_category');
        if (! $area) {
            $query->addSelect('sto_key')->groupBy('sto_key');
        } else {
            $query->addSelect('job_type', 'aging_bucket')->groupBy('job_type', 'aging_bucket');
        }
        $aggregates = $query->get();
        $branches ??= Branch::withTrashed()->with(['region' => fn ($q) => $q->withTrashed()])
            ->whereIn('id_branches', $aggregates->pluck('id_branches')->filter()->unique())->get()->keyBy('id_branches');
        $zero = array_fill_keys([...self::CATEGORIES, 'unmapped'], 0);
        $rows = [];
        $totals = $zero;
        $transactionColumns = $jobType === 'all' ? ['NEW INSTALL', 'MODIFY', 'DISCONNECT'] : [strtoupper(trim($jobType, ' '))];
        $transactions = [];
        foreach (['OPEN', 'STUCK'] as $category) {
            $transactions[$category] = ['filters' => ['mapping_order' => $category], 'counts' => [], 'total' => 0, 'regions' => []];
        }
        $transactionTotals = [];
        $agingZero = array_fill_keys(array_keys(OrderAging::LABELS), 0);
        $aging = ['counts' => $agingZero, 'total' => 0, 'regions' => []];
        foreach ($aggregates as $aggregate) {
            $branch = $branches->get($aggregate->id_branches);
            $id = $area ? $branch?->region_id : $aggregate->id_branches;
            $key = $id ?? 'unassigned';
            $name = $area ? ($branch?->region?->name ?? 'Tanpa Region') : ($branch?->name ?? 'Belum ditugaskan');
            $selection = $area ? ['region' => $id, 'without_region' => $id === null ? 1 : null]
                : ['branch' => $id, 'unassigned' => $id === null ? 1 : null];
            $rows[$key] ??= ['name' => $name, 'filters' => $selection, 'counts' => $zero, 'total' => 0, 'children' => []];
            $group = $aggregate->mapping_category;
            $count = (int) $aggregate->order_count;
            $rows[$key]['counts'][$group] += $count;
            $rows[$key]['total'] += $count;
            $totals[$group] += $count;
            if ($area && $group === 'OPEN') {
                $bucket = $aggregate->aging_bucket;
                $aging['counts'][$bucket] += $count;
                $aging['total'] += $count;
                $aging['regions'][$key] ??= ['name' => $name, 'filters' => $selection, 'counts' => $agingZero, 'total' => 0, 'branches' => []];
                $aging['regions'][$key]['counts'][$bucket] += $count;
                $aging['regions'][$key]['total'] += $count;
                $branchKey = $aggregate->id_branches ?? 'unassigned';
                $aging['regions'][$key]['branches'][$branchKey] ??= ['name' => $branch?->name ?? 'Belum ditugaskan',
                    'filters' => ['branch' => $aggregate->id_branches, 'unassigned' => $aggregate->id_branches === null ? 1 : null], 'counts' => $agingZero, 'total' => 0];
                $aging['regions'][$key]['branches'][$branchKey]['counts'][$bucket] += $count;
                $aging['regions'][$key]['branches'][$branchKey]['total'] += $count;
            }
            if ($area && isset($transactions[$group])) {
                $type = $aggregate->job_type;
                if (! in_array($type, $transactionColumns, true)) {
                    $transactionColumns[] = $type;
                }
                $transactionTotals[$type] = ($transactionTotals[$type] ?? 0) + $count;
                $section = &$transactions[$group];
                $section['counts'][$type] = ($section['counts'][$type] ?? 0) + $count;
                $section['total'] += $count;
                $section['regions'][$key] ??= ['name' => $name, 'filters' => $selection, 'counts' => [], 'total' => 0, 'branches' => []];
                $region = &$section['regions'][$key];
                $region['counts'][$type] = ($region['counts'][$type] ?? 0) + $count;
                $region['total'] += $count;
                $branchKey = $aggregate->id_branches ?? 'unassigned';
                $region['branches'][$branchKey] ??= ['name' => $branch?->name ?? 'Belum ditugaskan',
                    'filters' => ['branch' => $aggregate->id_branches, 'unassigned' => $aggregate->id_branches === null ? 1 : null], 'counts' => [], 'total' => 0];
                $region['branches'][$branchKey]['counts'][$type] = ($region['branches'][$branchKey]['counts'][$type] ?? 0) + $count;
                $region['branches'][$branchKey]['total'] += $count;
                unset($section, $region);
            }
            if (! $area) {
                $stoKey = 'sto:'.$aggregate->sto_key;
                $rows[$key]['children'][$stoKey] ??= ['name' => $aggregate->sto_key === '' ? 'STO belum terisi' : $aggregate->sto_key,
                    'filters' => $selection + ['sto' => $aggregate->sto_key === '' ? null : $aggregate->sto_key, 'sto_missing' => $aggregate->sto_key === '' ? 1 : null],
                    'counts' => $zero, 'total' => 0];
                $rows[$key]['children'][$stoKey]['counts'][$group] += $count;
                $rows[$key]['children'][$stoKey]['total'] += $count;
            }
        }
        uasort($rows, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        foreach ($rows as &$row) {
            uasort($row['children'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['name'], $b['name']));
        }
        unset($row);
        if ($jobType === 'all') {
            $extraTypes = array_diff($transactionColumns, ['NEW INSTALL', 'MODIFY', 'DISCONNECT']);
            natcasesort($extraTypes);
            $transactionColumns = ['NEW INSTALL', 'MODIFY', 'DISCONNECT', ...$extraTypes];
        }
        $typeZero = array_fill_keys($transactionColumns, 0);
        foreach ($transactions as &$section) {
            $section['counts'] = array_replace($typeZero, $section['counts']);
            uasort($section['regions'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            foreach ($section['regions'] as &$region) {
                $region['counts'] = array_replace($typeZero, $region['counts']);
                uasort($region['branches'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
                foreach ($region['branches'] as &$branchRow) {
                    $branchRow['counts'] = array_replace($typeZero, $branchRow['counts']);
                }
                unset($branchRow);
            }
            unset($region);
        }
        unset($section);
        uasort($aging['regions'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        foreach ($aging['regions'] as &$region) {
            uasort($region['branches'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        }
        unset($region);
        $aging['columns'] = array_diff_key(OrderAging::LABELS, $aging['counts']['unknown'] ? [] : ['unknown' => true]);
        $aging['date'] = $area ? $agingDate->toDateString() : null;

        return ['area' => $area, 'rows' => $rows, 'totals' => $totals, 'total' => array_sum($totals),
            'aging' => $area ? $aging : null,
            'transactions' => $area ? ['sections' => $transactions, 'columns' => $transactionColumns, 'totals' => array_replace($typeZero, $transactionTotals), 'total' => array_sum($transactionTotals)] : null,
            'columns' => [...self::CATEGORIES, ...($totals['unmapped'] ? ['unmapped'] : [])]];
    }

    private static function categoryExpression(Builder $query): string
    {
        $binary = $query->getConnection()->getDriverName() === 'mysql' ? 'BINARY ' : '';

        return 'CASE WHEN '.$binary."work_orders.mapping_order IN ('PS', 'CANCLWORK', 'OPEN', 'STUCK') THEN ".$binary."work_orders.mapping_order ELSE 'unmapped' END";
    }

    private static function stoExpression(Builder $query): string
    {
        $binary = $query->getConnection()->getDriverName() === 'mysql' ? 'BINARY ' : '';

        return $binary."COALESCE(NULLIF(TRIM(work_orders.sto), ''), '')";
    }
}
