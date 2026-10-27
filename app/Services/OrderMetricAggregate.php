<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class OrderMetricAggregate
{
    /** Compile existing query scopes into conditional aggregates, keeping business rules in one place. */
    public function query(Builder $base, array $metrics): Builder
    {
        $rows = (clone $base)->reorder()->setEagerLoads([])->select(['work_orders.id_work_orders', 'work_orders.id_branches', 'work_orders.jenis_pekerjaan', 'work_orders.tgl_order', 'work_orders.last_pulled_at']);
        $query = $base->getModel()->newQueryWithoutScopes()->select([]);
        foreach ($metrics as $name => $apply) {
            $predicate = $base->getModel()->newQuery();
            $apply($predicate);
            $where = $predicate->toBase();
            $condition = preg_replace('/^where /i', '', $where->getGrammar()->compileWheres($where));
            $alias = $where->getGrammar()->wrap($name);
            if ($condition === '') {
                $query->selectRaw('COUNT(*) AS '.$alias);
            } else {
                $flag = $where->getGrammar()->wrap('_olo_metric_'.$name);
                // Evaluate correlated issue predicates per row before GROUP BY (MariaDB ONLY_FULL_GROUP_BY).
                $rows->selectRaw('CASE WHEN '.$condition.' THEN 1 ELSE 0 END AS '.$flag, $where->getRawBindings()['where'] ?? []);
                $query->selectRaw('COALESCE(SUM(work_orders.'.$flag.'), 0) AS '.$alias);
            }
        }

        return $query->fromSub($rows->toBase(), 'work_orders');
    }

    public function counts(Builder $base, array $metrics): array
    {
        $result = $this->query($base, $metrics)->toBase()->first();

        return array_map('intval', (array) $result);
    }
}
