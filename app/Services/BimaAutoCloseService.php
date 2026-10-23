<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\PsEligibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BimaAutoCloseService
{
    public function apply(WorkOrder $workOrder, User $actor, ?int $syncJobId = null): bool
    {
        abort_unless($actor->fresh()?->canSync(), 403);

        return DB::transaction(function () use ($workOrder, $actor, $syncJobId) {
            $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
            if ($order->source_system !== 'BIMA' || ! PsEligibility::sourceMatches($order->status_order, $order->task_bima, $order->status_bima)
                || strtoupper(trim($order->progress_category ?? '', ' ')) === 'CLOSE') {
                return false;
            }
            $before = $order->only(['progress_category', 'operational_status', 'revision', 'verification_status', 'verified_at', 'last_progress_at']);
            $openIssues = $order->issues()->where('status', 'OPEN')->count();
            $changes = ['progress_category' => 'CLOSE', 'operational_status' => $order->operational_status === 'COMPLETED' ? 'COMPLETED' : 'FIELD_DONE',
                'revision' => $order->revision + 1, 'last_progress_at' => now(), 'updated_at' => now()];
            if ($order->operational_status !== 'COMPLETED') {
                $changes += ['verification_status' => 'UNVERIFIED', 'verified_at' => null];
            }
            // Only the authorized progress correction: do not recalculate or rewrite any source attribute/date.
            DB::table('work_orders')->where('id_work_orders', $order->getKey())->update($changes);
            $order->refresh();
            $order->events()->create(['id_users' => $actor->getKey(), 'old_status' => $before['operational_status'], 'new_status' => $order->operational_status,
                'old_progress_category' => $before['progress_category'], 'new_progress_category' => 'CLOSE', 'revision' => $order->revision,
                'notes' => 'Penyesuaian otomatis CLOSE berdasarkan status BIMA: '.$order->status_order.'. Waktu riwayat adalah waktu penyesuaian, bukan tanggal selesai historis. Kendala aktif tetap tersimpan.']);
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'progress.auto_closed_from_bima', 'entity_type' => 'work_order', 'id_entity' => $order->getKey(),
                'metadata' => ['origin' => $syncJobId ? 'inbound' : 'backfill', 'id_sync_jobs' => $syncJobId, 'status_order' => $order->status_order,
                    'before' => $before, 'after' => $order->only(array_keys($before)), 'open_issues_preserved' => $openIssues,
                    'completion_date_inferred' => false]]);

            return true;
        }, 3);
    }

    public function reconcile(User $actor, bool $dryRun = false): array
    {
        abort_unless($actor->fresh()?->canSync(), 403);
        $lock = Cache::lock('olo:work-order-inbound', 300);
        if (! $lock->get()) {
            throw new RuntimeException('Import work order sedang berjalan. Ulangi setelah import selesai.');
        }
        try {
            return DB::transaction(function () use ($actor, $dryRun) {
                $stats = ['matched' => 0, 'already_close' => 0, 'to_close' => 0, 'updated' => 0, 'with_open_issues' => 0];
                $months = [];
                PsEligibility::sourceOrders(WorkOrder::where('source_system', 'BIMA'))->orderBy('id_work_orders')->lockForUpdate()->chunkById(200, function ($orders) use ($actor, $dryRun, &$stats, &$months) {
                    foreach ($orders as $order) {
                        $month = $order->tgl_order?->format('Y-m') ?? 'Tanpa tanggal';
                        $months[$month] ??= ['matched' => 0, 'already_close' => 0, 'to_close' => 0, 'updated' => 0];
                        $stats['matched']++;
                        $months[$month]['matched']++;
                        $key = strtoupper(trim($order->progress_category ?? '', ' ')) === 'CLOSE' ? 'already_close' : 'to_close';
                        $stats[$key]++;
                        $months[$month][$key]++;
                        if ($key === 'to_close') {
                            $stats['with_open_issues'] += $order->issues()->where('status', 'OPEN')->exists() ? 1 : 0;
                            if (! $dryRun && $this->apply($order, $actor)) {
                                $stats['updated']++;
                                $months[$month]['updated']++;
                            }
                        }
                    }
                }, 'id_work_orders');
                ksort($months);
                if (! $dryRun) {
                    AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'progress.bima_close_reconciled', 'entity_type' => 'work_order_batch', 'id_entity' => 0,
                        'metadata' => ['stats' => $stats, 'months' => $months]]);
                }

                return ['stats' => $stats, 'months' => $months];
            }, 3);
        } finally {
            $lock->release();
        }
    }
}
