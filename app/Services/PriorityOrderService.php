<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PriorityOrderService
{
    public function set(User $actor, WorkOrder $workOrder, bool $selected, ?string $token = null): void
    {
        DB::transaction(function () use ($actor, $workOrder, $selected, $token) {
            $actor = User::lockForUpdate()->findOrFail($actor->getKey());
            $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
            Gate::forUser($actor)->authorize('prioritize', $order);
            $branch = $actor->role->areaScope() ? null : $order->id_branches;
            $key = $branch ? 'branch:'.$branch : 'area';
            $priority = WorkOrderPriority::where('id_work_orders', $order->getKey())->where('scope_key', $key)->lockForUpdate()->first();
            if ($selected && $priority || ! $selected && ! $priority) {
                return;
            }
            if ($selected) {
                $priority = WorkOrderPriority::create(['id_work_orders' => $order->getKey(), 'scope_key' => $key, 'id_branches' => $branch,
                    'id_users' => $actor->getKey(), 'selection_token' => (string) Str::uuid()]);
            } else {
                abort_unless($token && hash_equals($priority->selection_token, $token), 409, 'Pilihan prioritas sudah berubah. Muat ulang sebelum melepas bookmark.');
            }
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => $selected ? 'priority.selected' : 'priority.removed',
                'entity_type' => 'work_order_priority', 'id_entity' => $priority->getKey(),
                'metadata' => ['id_work_orders' => $order->getKey(), 'scope_key' => $key, 'id_branches' => $branch, 'selected_by' => $priority->id_users]]);
            if (! $selected) {
                $priority->delete();
            }
        }, 3);
    }
}
