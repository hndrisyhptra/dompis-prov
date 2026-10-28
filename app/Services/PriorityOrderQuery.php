<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class PriorityOrderQuery
{
    public function __construct(private BranchOrderScope $scope, private WorkOrderQueueService $queues) {}

    public function base(User $actor, array $filters): Builder
    {
        $query = $this->scope->orders($actor, ['jenis_pekerjaan' => 'all'] + $filters)->notPsEligible()->whereHas('priorities', $this->selections($actor, $filters))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('no_tq_sc_id', 'like', '%'.$term.'%')->orWhere('sid', 'like', '%'.$term.'%')->orWhere('nama_olo', 'like', '%'.$term.'%')));
        foreach (['bima_status' => 'status_bima', 'bima_task' => 'task_bima'] as $filter => $column) {
            if ($filters[$filter.'_missing'] ?? false) {
                $query->where(fn ($q) => $q->whereNull($column)->orWhereRaw('LENGTH(work_orders.'.$column.') = 0'));
            } elseif (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->whereRaw($query->getConnection()->getDriverName() === 'mysql' ? 'BINARY work_orders.'.$column.' = BINARY ?' : 'work_orders.'.$column.' = ?', [$filters[$filter]]);
            }
        }

        return $query;
    }

    public function filtered(User $actor, array $filters): Builder
    {
        return $this->queues->apply($this->base($actor, $filters), $filters['state'] ?? 'all', CarbonImmutable::now('Asia/Jakarta'));
    }

    public function ordered(User $actor, array $filters): Builder
    {
        return $this->orderedFrom($this->filtered($actor, $filters), $actor, $filters);
    }

    /** Order an already authorized/filtered query without rebuilding its Branch scope. */
    public function orderedFrom(Builder $filtered, User $actor, array $filters): Builder
    {
        $query = (clone $filtered)
            ->with(['assignedBranch', 'priorities' => fn ($q) => $q->visibleTo($actor)->with('creator:id_users,name')])
            ->withCount(['issues as open_issues_count' => fn ($q) => $q->where('status', 'OPEN')])->withMax(['priorities as priority_selected_at' => $this->selections($actor, $filters)], 'created_at');
        match ($filters['sort'] ?? 'selected') {
            'age' => $query->orderByRaw('CASE WHEN tgl_order IS NULL THEN 1 ELSE 0 END')->orderBy('tgl_order'),
            'updated' => $query->orderByDesc('last_progress_at'),
            default => $query->orderByDesc('priority_selected_at'),
        };

        return $query->orderByDesc('id_work_orders');
    }

    private function selections(User $actor, array $filters): Closure
    {
        return fn ($q) => $q->visibleTo($actor)->when(($filters['source'] ?? 'all') === 'area', fn ($q) => $q->where('scope_key', 'area'))
            ->when(($filters['source'] ?? 'all') === 'branch', fn ($q) => $q->whereNotNull('id_branches'));
    }
}
