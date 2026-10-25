<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BimaProgressMapping;
use Illuminate\Support\Facades\DB;

class BimaAutoProgressService
{
    public function __construct(private BimaAutoCloseService $autoClose) {}

    public function apply(WorkOrder $workOrder, User $actor, ?int $syncJobId = null): bool
    {
        abort_unless($actor->fresh()?->canSync(), 403);

        return DB::transaction(function () use ($workOrder, $actor, $syncJobId) {
            $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
            // The existing PS rule takes precedence and keeps its own history/audit contract.
            if ($this->autoClose->apply($order, $actor, $syncJobId)) {
                return true;
            }
            $category = BimaProgressMapping::category($order->status_order);
            if ($order->source_system !== 'BIMA' || ! $category || $order->terminal()
                || strtoupper(trim($order->progress_category ?? '')) === $category->value) {
                return false;
            }

            $before = $order->only(['progress_category', 'operational_status', 'revision', 'last_progress_at']);
            $openIssues = $order->issues()->where('status', 'OPEN')->count();
            // Do not run model source derivation, resolve issues, or rewrite completion/device data.
            DB::table('work_orders')->where('id_work_orders', $order->getKey())->update([
                'progress_category' => $category->value, 'operational_status' => $category->operationalStatus(),
                'revision' => $order->revision + 1, 'last_progress_at' => now(), 'updated_at' => now(),
            ]);
            $order->refresh();
            $order->events()->create([
                'id_users' => $actor->getKey(), 'old_status' => $before['operational_status'], 'new_status' => $order->operational_status,
                'old_progress_category' => $before['progress_category'], 'new_progress_category' => $category->value,
                'revision' => $order->revision,
                'notes' => 'Penyesuaian otomatis '.$category->value.' berdasarkan status BIMA: '.$order->status_order.'. Riwayat dan catatan Branch sebelumnya tetap tersimpan. Kendala aktif tidak diselesaikan otomatis.',
            ]);
            AuditLog::create([
                'id_users' => $actor->getKey(), 'action' => 'progress.auto_mapped_from_bima', 'entity_type' => 'work_order', 'id_entity' => $order->getKey(),
                'metadata' => ['origin' => 'inbound', 'id_sync_jobs' => $syncJobId, 'status_order' => $order->status_order,
                    'before' => $before, 'after' => $order->only(array_keys($before)), 'open_issues_preserved' => $openIssues],
            ]);

            return true;
        }, 3);
    }
}
