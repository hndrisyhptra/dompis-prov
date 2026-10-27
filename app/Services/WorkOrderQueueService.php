<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class WorkOrderQueueService
{
    public function apply(Builder $query, string $queue, CarbonImmutable $today): Builder
    {
        if ($queue === 'today') {
            return $query->where('tgl_order', '>=', $today->toDateString())->where('tgl_order', '<', $today->addDay()->toDateString());
        }
        if ($queue === 'ps_today') {
            return $query->psOnDate($today->toDateString());
        }
        if ($queue === 'complete') {
            return $query->psEligible();
        }
        if ($queue === 'potential_ps') {
            return $query->potentialPs();
        }
        if ($queue === 'new_order') {
            return $query->newOrder();
        }
        if ($queue === 'closed') {
            return $query->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CLOSE']);
        }
        if ($queue === 'waiting_bima') {
            return $query->waitingBima();
        }
        if ($queue === 'missing_ps_date') {
            return $query->psEligible()->whereNull('tgl_ps');
        }
        if ($queue === 'future_ps_date') {
            return $query->psEligible()->where('tgl_ps', '>=', $today->addDay()->toDateString());
        }
        if ($queue === 'unmapped') {
            return $query->whereNull('id_branches');
        }
        if (in_array($queue, ['outstanding', 'issues', 'unupdated'], true)) {
            $query->outstanding();
            if ($queue === 'issues') {
                $query->whereHas('issues', fn ($q) => $q->where('status', 'OPEN'));
            }
            if ($queue === 'unupdated') {
                $query->whereNull('last_progress_at');
            }
        }

        return $query;
    }

    public function counts(Builder $base, CarbonImmutable $today): array
    {
        return $this->selectedCounts($base, $today, ['all', 'unupdated', 'outstanding', 'issues', 'closed', 'waiting_bima', 'complete', 'potential_ps', 'new_order']);
    }

    public function selectedCounts(Builder $base, CarbonImmutable $today, array $queues): array
    {
        $metrics = [];
        foreach ($queues as $queue) {
            $metrics[$queue] = fn ($query) => $this->apply($query, $queue, $today);
        }

        return app(OrderMetricAggregate::class)->counts($base, $metrics);
    }
}
