<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExcelWorkOrderImportService
{
    public const CONNECTION_HASH = 'olo-work-order-xlsx-v5-date-modified-ps';

    public static function connectionHash(): string
    {
        return hash('sha256', self::CONNECTION_HASH);
    }

    public function __construct(private ExcelWorkOrderMapper $mapper, private BimaOrderResolver $resolver, private BimaPsDateResolver $psDates) {}

    private function data(array $import, array $raw): array
    {
        return $this->mapper->map($raw, $import['mapping'], $import['date1904'], $import['headers']);
    }

    public function preview(SyncJob $job): void
    {
        $this->psDates->withRuleCache(fn () => $this->resolver->withRoutingCache(fn () => $this->previewRows($job)));
    }

    private function previewRows(SyncJob $job): void
    {
        $import = $job->snapshot['import'];
        $counts = [];
        foreach ($job->rows()->select(['id_sync_job_rows', 'payload'])->orderBy('row_index')->lazy(100) as $row) {
            $key = $row->payload['raw'][(int) $import['mapping']['no_tq_sc_id'] - 1]['value'] ?? '';
            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $stats = ['read' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'rejected' => 0, 'conflict' => 0];
        foreach ($job->rows()->select('id_sync_job_rows')->orderBy('row_index')->cursor() as $row) {
            DB::transaction(function () use ($job, $row, $counts, $import, &$stats) {
                $row = $job->rows()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();
                $raw = $row->payload['raw'];
                $stats['read']++;
                $data = null;
                $external = null;
                $error = null;
                $fields = [];
                try {
                    $data = $this->data($import, $raw);
                    $external = $data['no_tq_sc_id'];
                    if (($counts[$external] ?? 0) > 1) {
                        throw new InvalidArgumentException('NO TQ/SC/ID duplikat dalam file. Seluruh kemunculan ID ditolak.');
                    }
                    $existing = WorkOrder::where('source_system', 'BIMA')->where('no_tq_sc_id', $external)->first();
                    $psDate = $this->psDates->prepare($data, $existing, $raw, $import);
                    $data = $psDate['data'];
                    $routing = $data + ['branch' => $existing?->branch ?? '', 'sto' => $existing?->sto ?? ''];
                    $branch = $this->resolver->resolve($routing);
                    $previousSource = $existing?->source_bima_fields ?? [];
                    $data['source_bima_fields'] = array_replace($previousSource, $data['source_bima_fields']);
                    ksort($data['source_bima_fields']);
                    ksort($data);
                    $hashData = $data;
                    if (BimaPsDateResolver::usesDateModified($import) && ! array_key_exists('tgl_ps', $hashData) && $existing?->tgl_ps && array_key_exists('TGL PS', $previousSource)) {
                        // Preserve the canonical source date in the hash without writing a stale date back.
                        $hashData['tgl_ps'] = $existing->tgl_ps->toDateString();
                        ksort($hashData);
                    }
                    $data['source_snapshot_hash'] = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    [$action,$error] = $this->resolver->classify($data, $existing, $branch);
                    $fields = array_keys(array_filter($data, fn ($value, $key) => ! $existing || $existing->getAttribute($key) != $value, ARRAY_FILTER_USE_BOTH));
                    $prepared = ['data' => $data, 'expected_hash' => $existing?->source_snapshot_hash, 'id_branches' => $branch, 'expected_id_branches' => $existing?->id_branches,
                        'ps_date_rule' => $psDate['fingerprint']];
                } catch (InvalidArgumentException $exception) {
                    $action = 'rejected';
                    $error = $exception->getMessage();
                }
                $stats[$action]++;
                $row->update(['external_key' => $external, 'action' => $action, 'error' => $error, 'field_changes' => $fields,
                    'payload' => ['raw' => $raw, 'prepared' => in_array($action, ['new', 'changed', 'unchanged'], true) ? $prepared : null]]);
            }, 3);
        }
        $job->update(['state' => 'preview_ready', 'stats' => $stats, 'expires_at' => now()->addMinutes(30), 'finished_at' => now(), 'error' => null]);
        AuditLog::create(['id_users' => $job->id_users, 'action' => 'excel.preview', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => $stats]);
    }

    public function commit(SyncJob $job): void
    {
        $this->psDates->withRuleCache(fn () => $this->resolver->withRoutingCache(fn () => $this->commitRows($job)));
    }

    private function commitRows(SyncJob $job): void
    {
        $import = $job->snapshot['import'];
        foreach ($job->rows()->select('id_sync_job_rows')->orderBy('row_index')->cursor() as $row) {
            DB::transaction(function () use ($job, $row, $import) {
                $log = $job->rows()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();
                $payload = $log->payload;
                if ($log->applied_at || ! $payload) {
                    return;
                }
                $prepared = $payload['prepared'] ?? null;
                if (! $prepared) {
                    $log->update(['applied_at' => now(), 'payload' => null]);

                    return;
                }
                $data = $prepared['data'];
                $existing = WorkOrder::where('source_system', 'BIMA')->where('no_tq_sc_id', $data['no_tq_sc_id'])->lockForUpdate()->first();
                $branch = $this->resolver->resolve($data + ['branch' => $existing?->branch ?? '', 'sto' => $existing?->sto ?? '']);
                [$action,$error] = $this->resolver->classify($data, $existing, $branch);
                if ($branch !== $prepared['id_branches'] || ($existing?->source_snapshot_hash !== $prepared['expected_hash'] && $existing?->source_snapshot_hash !== $data['source_snapshot_hash'])
                    || (array_key_exists('expected_id_branches', $prepared) && $existing?->id_branches !== $prepared['expected_id_branches'] && $existing?->id_branches !== $branch)
                    || ($prepared['ps_date_rule'] ?? null) !== $this->psDates->fingerprint($data, $existing, $import)) {
                    $action = 'conflict';
                    $error = 'Data sumber atau mapping berubah sesudah preview. Upload dan preview ulang.';
                }
                if (in_array($action, ['new', 'changed'], true)) {
                    // Mapper allowlist excludes all operational/progress/assignment attributes.
                    $wo = app(WorkOrderSourceWriter::class)->write($job, $existing, $data, $branch, 'XLSX:'.mb_substr($import['sheet'], 0, 200));
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
