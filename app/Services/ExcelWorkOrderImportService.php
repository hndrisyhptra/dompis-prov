<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExcelWorkOrderImportService
{
    public const CONNECTION_HASH = 'olo-work-order-xlsx-v4-task-status';

    public static function connectionHash(): string
    {
        return hash('sha256', self::CONNECTION_HASH);
    }

    public function __construct(private ExcelWorkOrderMapper $mapper, private BimaOrderResolver $resolver) {}

    private function data(SyncJob $job, array $raw): array
    {
        return $this->mapper->map($raw, $job->snapshot['import']['mapping'], $job->snapshot['import']['date1904']);
    }

    public function preview(SyncJob $job): void
    {
        $counts = [];
        foreach ($job->rows()->orderBy('row_index')->cursor() as $row) {
            $key = $row->payload['raw'][(int) $job->snapshot['import']['mapping']['no_tq_sc_id'] - 1]['value'] ?? '';
            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $stats = ['read' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'rejected' => 0, 'conflict' => 0];
        foreach ($job->rows()->orderBy('row_index')->cursor() as $row) {
            DB::transaction(function () use ($job, $row, $counts, &$stats) {
                $row = $job->rows()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();
                $stats['read']++;
                $data = null;
                $external = null;
                $error = null;
                $fields = [];
                try {
                    $data = $this->data($job, $row->payload['raw']);
                    $external = $data['no_tq_sc_id'];
                    if (($counts[$external] ?? 0) > 1) {
                        throw new InvalidArgumentException('NO TQ/SC/ID duplikat dalam file. Seluruh kemunculan ID ditolak.');
                    }
                    $existing = WorkOrder::where('source_system', 'BIMA')->where('no_tq_sc_id', $external)->first();
                    $routing = $data + ['branch' => $existing?->branch ?? '', 'sto' => $existing?->sto ?? ''];
                    $branch = $this->resolver->resolve($routing);
                    $data['source_bima_fields'] = array_replace($existing?->source_bima_fields ?? [], $data['source_bima_fields']);
                    ksort($data['source_bima_fields']);
                    ksort($data);
                    $data['source_snapshot_hash'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    [$action,$error] = $this->resolver->classify($data, $existing, $branch);
                    $fields = array_keys(array_filter($data, fn ($value, $key) => ! $existing || $existing->getAttribute($key) != $value, ARRAY_FILTER_USE_BOTH));
                    $prepared = ['data' => $data, 'expected_hash' => $existing?->source_snapshot_hash, 'id_branches' => $branch, 'expected_id_branches' => $existing?->id_branches];
                } catch (InvalidArgumentException $exception) {
                    $action = 'rejected';
                    $error = $exception->getMessage();
                }
                $stats[$action]++;
                $row->update(['external_key' => $external, 'action' => $action, 'error' => $error, 'field_changes' => $fields,
                    'payload' => ['raw' => $row->payload['raw'], 'prepared' => in_array($action, ['new', 'changed', 'unchanged'], true) ? $prepared : null]]);
            }, 3);
        }
        $job->update(['state' => 'preview_ready', 'stats' => $stats, 'expires_at' => now()->addMinutes(30), 'finished_at' => now(), 'error' => null]);
        AuditLog::create(['id_users' => $job->id_users, 'action' => 'excel.preview', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => $stats]);
    }

    public function commit(SyncJob $job): void
    {
        foreach ($job->rows()->orderBy('row_index')->cursor() as $row) {
            DB::transaction(function () use ($job, $row) {
                $log = $job->rows()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();
                if ($log->applied_at || ! $log->payload) {
                    return;
                }
                $prepared = $log->payload['prepared'] ?? null;
                if (! $prepared) {
                    $log->update(['applied_at' => now(), 'payload' => null]);

                    return;
                }
                $data = $prepared['data'];
                $existing = WorkOrder::where('source_system', 'BIMA')->where('no_tq_sc_id', $data['no_tq_sc_id'])->lockForUpdate()->first();
                $branch = $this->resolver->resolve($data + ['branch' => $existing?->branch ?? '', 'sto' => $existing?->sto ?? '']);
                [$action,$error] = $this->resolver->classify($data, $existing, $branch);
                if ($branch !== $prepared['id_branches'] || ($existing?->source_snapshot_hash !== $prepared['expected_hash'] && $existing?->source_snapshot_hash !== $data['source_snapshot_hash'])
                    || (array_key_exists('expected_id_branches', $prepared) && $existing?->id_branches !== $prepared['expected_id_branches'] && $existing?->id_branches !== $branch)) {
                    $action = 'conflict';
                    $error = 'Data sumber atau mapping berubah sesudah preview. Upload dan preview ulang.';
                }
                if (in_array($action, ['new', 'changed'], true)) {
                    // Mapper allowlist excludes all operational/progress/assignment attributes.
                    $wo = app(WorkOrderSourceWriter::class)->write($job, $existing, $data, $branch, 'XLSX:'.mb_substr($job->snapshot['import']['sheet'], 0, 200));
                    $action = $existing ? 'updated' : 'inserted';
                    AuditLog::create(['id_users' => $job->id_users, 'action' => 'excel.'.$action, 'entity_type' => 'work_order', 'id_entity' => $wo->getKey(),
                        'metadata' => ['id_sync_jobs' => $job->getKey(), 'fields' => $log->field_changes]]);
                }
                if ($action === 'unchanged' && $existing) {
                    app(BimaAutoProgressService::class)->apply($existing, $job->actor, $job->getKey());
                }
                $log->update(['action' => $action, 'error' => $error, 'applied_at' => now(), 'payload' => null]);
            }, 3);
        }
        $stats = ['read' => $job->rows()->count(), 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0, 'conflict' => 0];
        foreach ($job->rows()->selectRaw('action, COUNT(*) AS total')->groupBy('action')->get() as $row) {
            $stats[$row->action] = (int) $row->total;
        }
        $job->update(['state' => ($stats['rejected'] + $stats['conflict']) ? 'partial_success' : 'success', 'stats' => $stats, 'finished_at' => now(), 'error' => null]);
        AuditLog::create(['id_users' => $job->id_users, 'action' => 'excel.imported', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => $stats]);
    }
}
