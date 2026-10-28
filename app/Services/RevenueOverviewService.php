<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

class RevenueOverviewService
{
    public const STAGES = ['ps' => 'PS', 'ongoing' => 'Belum PS', 'cancel' => 'Cancel'];

    public function orders(User $user, array $filters): Builder
    {
        $query = app(BranchOrderScope::class)->orders($user, $filters + ['jenis_pekerjaan' => 'all']);
        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('no_tq_sc_id', 'like', $term)->orWhere('sid', 'like', $term));
        }
        if (! empty($filters['package_id'])) {
            $query->whereHas('boq', fn ($q) => $q->where('package_id', $filters['package_id']));
        }
        $state = $filters['state'] ?? 'all';
        if ($state === 'without_boq') {
            $query->whereDoesntHave('boq');
        } elseif ($state !== 'all') {
            $query->whereHas('boq');
            $ps = (clone $query)->psEligible()->select('work_orders.id_work_orders');
            if ($state === 'ps') {
                $query->whereIn('work_orders.id_work_orders', $ps);
            } else {
                $query->whereNotIn('work_orders.id_work_orders', $ps);
                if ($state === 'cancel') {
                    $query->where(fn ($q) => $q->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CANCEL'])->orWhere('operational_status', 'CANCELLED'));
                } else {
                    $query->whereRaw("UPPER(TRIM(COALESCE(progress_category, ''))) <> ?", ['CANCEL'])->where('operational_status', '<>', 'CANCELLED');
                }
            }
        }

        return $query;
    }

    public static function stage(WorkOrder $order): string
    {
        return $order->isPsEligible() ? 'ps' : ((strtoupper(trim($order->progress_category ?? '')) === 'CANCEL' || $order->operational_status === 'CANCELLED') ? 'cancel' : 'ongoing');
    }

    public function summary(Builder $query): array
    {
        $summary = $this->emptyGroup();
        $branches = [];
        $months = [];
        $latest = null;
        (clone $query)->select(['id_work_orders', 'id_branches', 'tgl_order', 'progress_category', 'operational_status', 'status_order', 'status_bima', 'task_bima'])
            ->with(['boq' => fn ($q) => $q->select(['id_boq_jt', 'id_work_orders', 'material_total', 'labor_total', 'grand_total', 'updated_at'])])
            ->reorder()->chunkById(500, function ($orders) use (&$summary, &$branches, &$months, &$latest) {
                foreach ($orders as $order) {
                    $branch = $order->id_branches ?? 'unassigned';
                    $branches[$branch] ??= $this->emptyGroup();
                    $month = $order->tgl_order?->format('Y-m') ?? 'undated';
                    $months[$month] ??= $this->emptyGroup();
                    $this->addOrder($summary, $order);
                    $this->addOrder($branches[$branch], $order);
                    $this->addOrder($months[$month], $order);
                    if ($order->boq && (! $latest || $order->boq->updated_at->greaterThan($latest))) {
                        $latest = $order->boq->updated_at;
                    }
                }
            }, 'work_orders.id_work_orders', 'id_work_orders');
        ksort($months);
        uasort($branches, fn ($a, $b) => BigDecimal::of($b['grand_total'])->compareTo($a['grand_total']));
        $maximum = '0.00';
        foreach ($months as $row) {
            if (BigDecimal::of($row['grand_total'])->isGreaterThan($maximum)) {
                $maximum = $row['grand_total'];
            }
        }
        foreach ($months as &$row) {
            $row['bar'] = BigDecimal::of($maximum)->isZero() ? '0' : (string) BigDecimal::of($row['grand_total'])->multipliedBy(100)->dividedBy($maximum, 1, RoundingMode::HalfUp);
        }
        unset($row);
        $summary['coverage'] = $summary['order_count'] ? (string) BigDecimal::of($summary['boq_count'])->multipliedBy(100)->dividedBy($summary['order_count'], 1, RoundingMode::HalfUp) : '0.0';

        return ['summary' => $summary, 'branchSummary' => $branches, 'monthSummary' => $months, 'latestBoqAt' => $latest];
    }

    private function emptyGroup(): array
    {
        return ['order_count' => 0, 'boq_count' => 0, 'material_total' => '0.00', 'labor_total' => '0.00', 'grand_total' => '0.00',
            'ps' => '0.00', 'ongoing' => '0.00', 'cancel' => '0.00', 'ps_count' => 0, 'ongoing_count' => 0, 'cancel_count' => 0];
    }

    private function addOrder(array &$group, WorkOrder $order): void
    {
        $group['order_count']++;
        if (! $order->boq) {
            return;
        }
        $group['boq_count']++;
        foreach (['material_total', 'labor_total', 'grand_total'] as $key) {
            $group[$key] = (string) BigDecimal::of($group[$key])->plus($order->boq->$key);
        }
        $stage = self::stage($order);
        $group[$stage] = (string) BigDecimal::of($group[$stage])->plus($order->boq->grand_total);
        $group[$stage.'_count']++;
    }
}
