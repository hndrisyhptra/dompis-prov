<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MappingOwnergrup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class OwnerGroupAnalyticsService
{
    public static function filter(Builder $query, ?string $group): Builder
    {
        if (! $group) {
            return $query;
        }
        self::joinMapping($query, 'owner_group_filter')->select('work_orders.*');

        return $group === 'unmapped' ? $query->whereNull('owner_group_filter.kelompok_ownergrup')
            : $query->where('owner_group_filter.kelompok_ownergrup', $group);
    }

    public function summary(Builder $base): array
    {
        // Unique source owner_grup ensures one order contributes to exactly one column.
        $aggregates = self::joinMapping(clone $base, 'owner_mapping')->select(['work_orders.id_branches', 'owner_mapping.kelompok_ownergrup'])
            ->selectRaw('COUNT(*) as order_count')->groupBy('work_orders.id_branches', 'owner_mapping.kelompok_ownergrup')->get();
        $branches = Branch::withTrashed()->whereIn('id_branches', $aggregates->pluck('id_branches')->filter()->unique())->get()->keyBy('id_branches');
        $zero = array_fill_keys([...MappingOwnergrup::GROUPS, 'unmapped'], 0);
        $rows = [];
        $totals = $zero;
        foreach ($aggregates as $aggregate) {
            $key = $aggregate->id_branches ?? 'unassigned';
            $rows[$key] ??= ['branch_id' => $aggregate->id_branches, 'name' => $branches->get($aggregate->id_branches)?->name ?? 'Belum terpetakan', 'counts' => $zero, 'total' => 0];
            $group = $aggregate->kelompok_ownergrup ?? 'unmapped';
            $count = (int) $aggregate->order_count;
            $rows[$key]['counts'][$group] += $count;
            $rows[$key]['total'] += $count;
            $totals[$group] += $count;
        }
        uasort($rows, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return ['groups' => MappingOwnergrup::GROUPS, 'rows' => array_values($rows), 'totals' => $totals,
            'total' => array_sum($totals), 'has_mapping' => MappingOwnergrup::where('active', true)->whereIn('kelompok_ownergrup', MappingOwnergrup::GROUPS)->exists()];
    }

    private static function joinMapping(Builder $query, string $alias): Builder
    {
        return $query->leftJoin('mapping_ownergrup as '.$alias, function ($join) use ($alias) {
            self::matchOwner($join, $alias.'.owner_grup');
            $join->where($alias.'.active', true)->whereIn($alias.'.kelompok_ownergrup', MappingOwnergrup::GROUPS);
        });
    }

    private static function matchOwner(QueryBuilder $query, string $column): void
    {
        // Both list and pivot use this join; binary comparison preserves source-name distinctions.
        $query->whereRaw($query->getConnection()->getDriverName() === 'mysql'
            ? 'BINARY '.$column.' = BINARY work_orders.owner_grup'
            : $column.' = work_orders.owner_grup');
    }
}
