<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkOrderDeviceService
{
    public const FIELDS = ['odp' => 'ODP', 'sn' => 'SN', 'olt' => 'OLT', 'port_olt' => 'PORT OLT', 'metro' => 'METRO', 'port_metro' => 'PORT METRO'];

    public function update(User $actor, WorkOrder $workOrder, array $data): void
    {
        DB::transaction(function () use ($actor, $workOrder, $data) {
            $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('editDevices', $order);
            abort_unless($order->revision === (int) $data['revision'], 409, 'Data order sudah berubah. Muat ulang sebelum menyimpan perangkat.');
            $changes = [];
            foreach (self::FIELDS as $field => $label) {
                if (array_key_exists($field, $data) && $data[$field] !== $order->$field) {
                    $changes[$field] = $data[$field];
                }
            }
            if (! $changes) {
                return;
            }
            $before = array_intersect_key($order->getAttributes(), $changes);
            $order->fill($changes)->forceFill(['device_overrides' => array_values(array_unique(array_merge($order->device_overrides ?? [], array_keys($changes)))),
                'revision' => $order->revision + 1])->save();
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'devices.changed', 'entity_type' => 'work_order', 'id_entity' => $order->getKey(),
                'metadata' => ['before' => $before, 'after' => $changes, 'revision' => $order->revision]]);
        }, 3);
    }
}
