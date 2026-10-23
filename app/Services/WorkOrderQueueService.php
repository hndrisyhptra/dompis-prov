<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class WorkOrderQueueService
{
    public function apply(Builder $query, string $queue, CarbonImmutable $today): Builder
    {
        if ($queue === 'today') {
            return $query->whereDate('tgl_order', $today->toDateString());
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
            return $query->psEligible()->whereDate('tgl_ps', '>', $today->toDateString());
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
        $counts = [];
        foreach (['all', 'unupdated', 'outstanding', 'issues', 'closed', 'waiting_bima', 'complete', 'potential_ps', 'new_order'] as $queue) {
            $counts[$queue] = $this->apply(clone $base, $queue, $today)->count();
        }

        return $counts;
    }
}
