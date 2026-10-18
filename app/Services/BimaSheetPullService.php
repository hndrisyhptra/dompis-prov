<?php

namespace App\Services;

use App\Contracts\GoogleSheetGateway;
use App\Models\AuditLog;
use App\Models\BranchMapping;
use App\Models\ServiceArea;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BimaSheetPullService
{
    public function __construct(private GoogleSheetGateway $gateway, private BimaRowMapper $mapper) {}

    public static function connectionHash(): string
    {
        return hash('sha256', json_encode([config('olo.sheets.driver'), config('olo.sheets.spreadsheet_id'), config('olo.sheets.credentials_path'), config('olo.sheets.fake_path')]));
    }

    private function mappedBranch(array $data): ?int
    {
        $mapped = BranchMapping::where('enabled', true)->where('bima_branch', mb_strtoupper(trim($data['source_branch_name'])))
            ->whereIn('sto_code', ['', mb_strtoupper(trim($data['sto_code']))])->whereHas('branch', fn ($q) => $q->where('active', true))
            ->orderByRaw("CASE WHEN sto_code = '' THEN 1 ELSE 0 END")->value('id_branches');
        $sto = ServiceArea::withTrashed()->with(['branch', 'region'])->where('workzone', mb_strtoupper(trim($data['sto_code'])))->first();
        if (! $sto) {
            return $mapped;
        }
        if ($sto->trashed() || ! $sto->is_active || ($sto->id_regions && ! $sto->region?->is_active)) {
            return null;
        }
        if (! $sto->id_branches) {
            return $mapped;
        }
        if (! $sto->branch?->active || ($sto->branch->region_id !== null && (int) $sto->branch->region_id !== $sto->id_regions)) {
            return null;
        }

        // Competing explicit source mapping and STO ownership require Area review.
        return $mapped && $mapped !== $sto->id_branches ? null : $sto->id_branches;
    }

    private function classify(array $data, ?WorkOrder $existing, ?int $branch): array
    {
        if (! $branch) {
            return ['conflict', 'Mapping branch F/STO belum tersedia, tidak aktif, atau bertentangan dengan master STO.'];
        }
        if ($existing && ($existing->id_branches !== $branch || $existing->source_branch_name !== $data['source_branch_name'])) {
            return ['conflict', 'Branch sumber/mapping berubah. Perlu review Area; tidak dipindahkan otomatis.'];
        }

        return [! $existing ? 'new' : ($existing->source_snapshot_hash === $data['source_snapshot_hash'] ? 'unchanged' : 'changed'), null];
    }

    public function preview(SyncJob $job): void
    {
        $values = $this->gateway->readBima();
        if (count($values) > config('olo.sheets.max_rows') + 1) {
            throw new InvalidArgumentException('BIMA_RAW melebihi batas 10.000 baris. Pecah snapshot melalui prosedur Area.');
        }
        $this->mapper->validateHeader(array_shift($values) ?? []);
        $counts = [];
        foreach ($values as $row) {
            $key = $row[7] ?? null;
            if (is_string($key) && trim($key) !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $snapshot = [];
        $logs = [];
        $stats = ['read' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'rejected' => 0, 'conflict' => 0];
        foreach ($values as $offset => $row) {
            if (collect(array_slice($row, 0, 28))->every(fn ($value) => $value === null || $value === '')) {
                continue;
            }
            $index = $offset + 2;
            $stats['read']++;
            $data = null;
            $existing = null;
            $branch = null;
            try {
                $data = $this->mapper->map($row);
                if (($counts[$data['external_order_id']] ?? 0) > 1) {
                    throw new InvalidArgumentException('ID H duplikat dalam snapshot. Semua kemunculan ID ditolak.');
                }
                $existing = WorkOrder::where('source_system', 'BIMA')->where('external_order_id', $data['external_order_id'])->first();
                $branch = $this->mappedBranch($data);
                [$action, $error] = $this->classify($data, $existing, $branch);
            } catch (InvalidArgumentException $exception) {
                $action = 'rejected';
                $error = $exception->getMessage();
            }
            $stats[$action]++;
            $changes = $data ? array_keys(array_filter($data, fn ($value, $field) => ! $existing || $existing->getAttribute($field) != $value, ARRAY_FILTER_USE_BOTH)) : [];
            $logs[] = ['row_index' => $index, 'external_key' => isset($row[7]) && is_scalar($row[7]) ? mb_substr((string) $row[7], 0, 191) : null,
                'action' => $action, 'error' => $error, 'field_changes' => $changes];
            if (in_array($action, ['new', 'changed', 'unchanged'], true)) {
                $snapshot[(string) $index] = ['data' => $data, 'expected_hash' => $existing?->source_snapshot_hash, 'id_branches' => $branch];
            }
        }
        DB::transaction(function () use ($job, $snapshot, $logs, $stats) {
            $job->rows()->delete(); // Only retryable preview details; no work order has been modified.
            foreach (array_chunk($logs, 200) as $chunk) {
                foreach ($chunk as $row) {
                    $job->rows()->create($row);
                }
            }
            $job->update(['snapshot' => $snapshot, 'state' => 'preview_ready', 'stats' => $stats,
                'expires_at' => now()->addMinutes(30), 'finished_at' => now(), 'error' => null]);
            AuditLog::create(['id_users' => $job->id_users, 'action' => 'bima.preview', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => $stats]);
        });
    }

    public function pull(SyncJob $job): void
    {
        if (in_array($job->state, ['success', 'partial_success'], true)) {
            return;
        }
        foreach ($job->snapshot ?? [] as $index => $entry) {
            DB::transaction(function () use ($job, $index, $entry) {
                $log = $job->rows()->where('row_index', $index)->lockForUpdate()->firstOrFail();
                if ($log->applied_at) {
                    return;
                }
                $data = $entry['data'];
                $existing = WorkOrder::where('source_system', 'BIMA')->where('external_order_id', $data['external_order_id'])->lockForUpdate()->first();
                $branch = $this->mappedBranch($data);
                [$action, $error] = $this->classify($data, $existing, $branch);
                if ($branch !== ($entry['id_branches'] ?? $entry['branch_id'] ?? null) || ($existing?->source_snapshot_hash !== $entry['expected_hash'] && $existing?->source_snapshot_hash !== $data['source_snapshot_hash'])) {
                    $action = 'conflict';
                    $error = 'Data sumber/mapping berubah sesudah preview. Buat preview baru.';
                }
                if (in_array($action, ['new', 'changed'], true)) {
                    // Explicit inbound allowlist from mapper. No operational field can enter this update.
                    $attributes = $data + ['id_branches' => $branch, 'source_tab' => 'BIMA_RAW', 'last_pulled_at' => now()];
                    $wo = $existing ?? new WorkOrder;
                    $wo->fill($attributes)->save();
                    $action = $existing ? 'updated' : 'inserted';
                    AuditLog::create(['id_users' => $job->id_users, 'action' => 'bima.'.$action, 'entity_type' => 'work_order', 'id_entity' => $wo->getKey(),
                        'metadata' => ['id_sync_jobs' => $job->getKey(), 'fields' => $log->field_changes]]);
                }
                $log->update(['action' => $action, 'error' => $error, 'applied_at' => now()]);
            }, 3);
        }
        $stats = ['read' => $job->rows()->count(), 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0, 'conflict' => 0];
        foreach ($job->rows()->selectRaw('action, COUNT(*) AS total')->groupBy('action')->get() as $row) {
            $stats[$row->action] = (int) $row->total;
        }
        DB::transaction(function () use ($job, $stats) {
            $job->update(['stats' => $stats, 'state' => ($stats['rejected'] + $stats['conflict']) > 0 ? 'partial_success' : 'success', 'finished_at' => now(), 'error' => null, 'snapshot' => null]);
            AuditLog::create(['id_users' => $job->id_users, 'action' => 'bima.pull', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => $stats]);
        });
    }
}
