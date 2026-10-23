<?php

namespace App\Services;

use App\Models\SyncJob;
use App\Models\WorkOrder;

class WorkOrderSourceWriter
{
    // Caller holds the work order/preview row locks in one transaction.
    public function write(SyncJob $job, ?WorkOrder $existing, array $data, ?int $branch, string $tab): WorkOrder
    {
        $order = $existing ?? new WorkOrder;
        $attributes = $order->getAttributes();
        $source = $order->source_bima_fields ?? [];
        $before = ['old_task' => $order->task_bima, 'old_status_bima' => $order->status_bima, 'old_status_order' => $order->status_order];
        foreach ($order->device_overrides ?? [] as $field) {
            if (array_key_exists($field, WorkOrderDeviceService::FIELDS)) {
                unset($data[$field]);
            }
        }
        $order->fill($data + ['id_branches' => $branch, 'source_tab' => $tab, 'last_pulled_at' => now()])->save();
        $changes = [];
        if ($existing) {
            foreach (ExcelWorkOrderMapper::FIELDS as $field => $label) {
                if (! array_key_exists($field, $data) && ! in_array($field, ['status_order', 'mapping_order'], true)) {
                    continue;
                }
                if (in_array($field, ['bulan', 'tahun', 'umur_hari', 'kategori_umur'], true)) {
                    continue;
                }
                $old = $attributes[$field] ?? null;
                $new = $order->getRawOriginal($field);
                if (in_array($field, ['tgl_order', 'tgl_ps'], true)) {
                    $old = $old === null ? null : substr($old, 0, 10);
                    $new = $new === null ? null : substr($new, 0, 10);
                }
                if ($old !== $new) {
                    $changes[$field] = ['label' => $label, 'before' => $old, 'after' => $new];
                }
            }
            foreach (array_unique(array_merge(array_keys($source), array_keys($order->source_bima_fields ?? []))) as $header) {
                if (str_starts_with($header, '_')) {
                    continue;
                }
                $old = $source[$header] ?? null;
                $new = ($order->source_bima_fields ?? [])[$header] ?? null;
                $label = $header === 'STATUS' ? 'STATUS BIMA' : $header;
                if ($old !== $new && ! in_array($label, array_column($changes, 'label'), true)) {
                    $changes['source:'.$header] = ['label' => $header.' (sumber BIMA)', 'before' => $old, 'after' => $new];
                }
            }
        }
        if ($existing && $changes) {
            $order->taskLogs()->create($before + ['new_task' => $order->task_bima, 'new_status_bima' => $order->status_bima,
                'new_status_order' => $order->status_order, 'field_changes' => $changes, 'id_sync_jobs' => $job->getKey(), 'id_users' => $job->id_users, 'changed_at' => now()]);
        }

        app(BimaAutoCloseService::class)->apply($order, $job->actor, $job->getKey());

        return $order->refresh();
    }
}
