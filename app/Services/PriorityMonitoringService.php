<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MappingOwnergrup;
use App\Models\WorkOrder;
use App\Models\WoStatusEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PriorityMonitoringService
{
    public function updatedOrders(Builder $base): Builder
    {
        // The uncorrelated ID subquery can use action and order/revision indexes;
        // the outer builder retains all priority, account and filter constraints.
        $manualOrders = AuditLog::query()->select('audit_logs.id_entity')
            ->where('audit_logs.action', 'progress.changed')->where('audit_logs.entity_type', 'work_order')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('wo_status_events')
                ->whereColumn('wo_status_events.id_work_orders', 'audit_logs.id_entity')
                ->whereColumn('wo_status_events.revision', 'audit_logs.metadata->revision')
                ->whereColumn('wo_status_events.id_users', 'audit_logs.id_users')
                ->whereRaw('LENGTH(TRIM(wo_status_events.notes)) > 0'));

        return $base->where(fn ($q) => $q->whereHas('issues', fn ($q) => $q->where('status', 'OPEN'))
            ->orWhereIn('work_orders.id_work_orders', $manualOrders))
            ->with(['issues' => fn ($q) => $q->where('status', 'OPEN')->with('latestNote')]);
    }

    /** Issues and their latest notes are eager loaded for the current page only. */
    public function latestIssueUpdate(WorkOrder $order, array $issueAuditIds = []): ?array
    {
        $latest = null;
        foreach ($order->issues as $issue) {
            $update = ['text' => $issue->description, 'at' => $issue->created_at, 'issue_id' => $issue->getKey(), 'note_id' => 0];
            $note = $issue->latestNote;
            if ($note && (! $update['at'] || $note->created_at?->gte($update['at']))) {
                $update = ['text' => $note->note, 'at' => $note->created_at, 'issue_id' => $issue->getKey(), 'note_id' => $note->getKey()];
            }
            $auditKey = $update['note_id'] ? 'wo_issue_note:'.$update['note_id'] : 'wo_issue:'.$update['issue_id'];
            $update += ['audit_id' => $issueAuditIds[$auditKey] ?? 0, 'label' => 'Kendala'];
            $rank = fn ($value) => [$value['at']?->getTimestamp() ?? 0, $value['audit_id'], $value['issue_id'], $value['note_id']];
            if ($latest === null || $rank($update) > $rank($latest)) {
                $latest = $update;
            }
        }

        return $latest;
    }

    public function operationalUpdates(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }
        $orderIds = $orders->pluck('id_work_orders')->all();
        $issues = $orders->flatMap(fn ($order) => $order->issues);
        $issueIds = $issues->pluck('id_wo_issues')->all();
        $noteIds = $issues->map(fn ($issue) => $issue->latestNote?->getKey())->filter()->all();
        // Read immutable audit provenance in one batch; automatic BIMA/routing events are excluded.
        $audits = AuditLog::whereIn('action', ['progress.changed', 'issue.opened', 'issue.note_added'])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('entity_type', 'work_order')->whereIn('id_entity', $orderIds))
                ->orWhere(fn ($q) => $q->where('entity_type', 'wo_issue')->whereIn('id_entity', $issueIds))
                ->orWhere(fn ($q) => $q->where('entity_type', 'wo_issue_note')->whereIn('id_entity', $noteIds)))
            ->select(['id_audit_logs', 'id_entity', 'id_users', 'entity_type', 'action', 'metadata'])->get();
        $manual = [];
        $issueAuditIds = [];
        foreach ($audits as $audit) {
            if ($audit->action === 'progress.changed' && $audit->entity_type === 'work_order' && isset($audit->metadata['revision'])) {
                $key = $audit->id_entity.':'.$audit->metadata['revision'].':'.$audit->id_users;
                $manual[$key] = max($manual[$key] ?? 0, $audit->getKey());
            } elseif (in_array($audit->entity_type, ['wo_issue', 'wo_issue_note'], true)) {
                $key = $audit->entity_type.':'.$audit->id_entity;
                $issueAuditIds[$key] = max($issueAuditIds[$key] ?? 0, $audit->getKey());
            }
        }
        $updates = $orders->mapWithKeys(fn ($order) => [$order->getKey() => $this->latestIssueUpdate($order, $issueAuditIds)]);
        if (! $manual) {
            return $updates;
        }
        foreach (WoStatusEvent::whereIn('id_work_orders', $orderIds)->orderByDesc('created_at')->orderByDesc('id_wo_status_events')->cursor() as $event) {
            $key = $event->id_work_orders.':'.$event->revision.':'.$event->id_users;
            if (! isset($manual[$key]) || trim($event->notes ?? '') === '') {
                continue;
            }
            $candidate = ['text' => $event->notes, 'at' => $event->created_at, 'audit_id' => $manual[$key],
                'label' => $event->new_progress_category ?? $event->new_status];
            $current = $updates->get($event->id_work_orders);
            $rank = fn ($value) => [$value['at']?->getTimestamp() ?? 0, $value['audit_id'] ?? 0];
            if ($current === null || $rank($candidate) > $rank($current)) {
                $updates->put($event->id_work_orders, $candidate);
            }
        }

        return $updates;
    }

    public function summary(Builder $base): array
    {
        $zero = array_fill_keys([...MappingOwnergrup::GROUPS, 'unmapped'], 0);
        $totals = $zero;
        $statuses = [];
        $owners = [];
        // Aggregate before hydration; binary grouping preserves distinct source task/status names on MySQL.
        $binary = $base->getConnection()->getDriverName() === 'mysql' ? 'BINARY ' : '';
        $statusExpression = $binary."COALESCE(work_orders.status_bima, '')";
        $taskExpression = $binary."COALESCE(work_orders.task_bima, '')";
        $query = OwnerGroupAnalyticsService::joinMapping(clone $base, 'priority_owner')
            ->select('priority_owner.kelompok_ownergrup')->selectRaw($statusExpression.' AS status_bima, '.$taskExpression.' AS task_bima, COUNT(*) AS order_count')
            ->groupByRaw('priority_owner.kelompok_ownergrup, '.$statusExpression.', '.$taskExpression);
        foreach ($query->toBase()->get() as $order) {
            $count = (int) $order->order_count;
            $status = self::dimension($order->status_bima, 'bima_status');
            $task = self::dimension($order->task_bima, 'bima_task');
            $statusKey = json_encode($order->status_bima ?? '');
            $taskKey = json_encode($order->task_bima ?? '');
            $group = $order->kelompok_ownergrup ?? 'unmapped';
            $statuses[$statusKey] ??= $status + ['counts' => $zero, 'total' => 0, 'tasks' => []];
            $statuses[$statusKey]['tasks'][$taskKey] ??= $task + ['counts' => $zero, 'total' => 0];
            $statuses[$statusKey]['counts'][$group] += $count;
            $statuses[$statusKey]['total'] += $count;
            $statuses[$statusKey]['tasks'][$taskKey]['counts'][$group] += $count;
            $statuses[$statusKey]['tasks'][$taskKey]['total'] += $count;
            $owners[$group] ??= ['label' => self::ownerLabel($group), 'key' => $group, 'tasks' => [], 'total' => 0];
            $owners[$group]['tasks'][$taskKey] ??= $task + ['total' => 0];
            $owners[$group]['tasks'][$taskKey]['total'] += $count;
            $owners[$group]['total'] += $count;
            $totals[$group] += $count;
        }
        uasort($statuses, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
        foreach ($statuses as &$status) {
            uasort($status['tasks'], fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
        }
        unset($status);
        foreach ($owners as &$owner) {
            uasort($owner['tasks'], fn ($a, $b) => $b['total'] <=> $a['total'] ?: strnatcasecmp($a['label'], $b['label']));
        }
        unset($owner);
        $groups = array_keys(array_filter($totals));
        $owners = array_replace(array_fill_keys($groups, null), $owners);

        return ['statuses' => $statuses, 'owners' => $owners, 'totals' => $totals, 'groups' => $groups, 'total' => array_sum($totals)];
    }

    public static function ownerLabel(string $group): string
    {
        return $group === 'unmapped' ? 'Belum dipetakan' : $group;
    }

    public static function dimension(?string $value, string $field): array
    {
        $missing = $value === null || $value === '';

        return ['label' => $missing ? ($field === 'bima_status' ? 'Status BIMA belum tersedia' : 'Task BIMA belum tersedia') : $value,
            'filters' => [$field => $missing ? null : $value, $field.'_missing' => $missing ? 1 : null]];
    }
}
