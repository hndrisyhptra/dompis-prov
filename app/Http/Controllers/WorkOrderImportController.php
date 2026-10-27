<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWorkOrderImport;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Models\SyncJobRow;
use App\Services\BimaWorkOrdersTemplate;
use App\Services\ExcelWorkOrderImportService;
use App\Services\ExcelWorkOrderMapper;
use App\Services\WorkOrderXlsxReader;
use App\Support\WorkOrderUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WorkOrderImportController extends Controller
{
    private function authorizeImport(): void
    {
        Gate::authorize('sync');
        abort_unless(config('olo.inbound') === 'excel', 409, 'Mode import Excel belum aktif.');
    }

    private function currentVersion(SyncJob $job): void
    {
        abort_unless($job->connection_hash === ExcelWorkOrderImportService::connectionHash(), 409, 'Struktur kolom import sudah berubah. Upload ulang file dari Sync Center.');
    }

    public function upload(Request $request, WorkOrderXlsxReader $reader)
    {
        $this->authorizeImport();
        $file = $request->file('file');
        if ($file instanceof UploadedFile && ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => WorkOrderUpload::errorMessage($file->getError())]);
        }
        $data = $request->validate(['file' => 'required|file|extensions:xlsx|max:10240', 'idempotency_key' => 'required|uuid', 'sheet' => 'nullable|string|max:100', 'header_row' => 'required|integer|min:1|max:50'], [
            'file.required' => 'Pilih file XLSX hasil export BIMA terlebih dahulu.',
            'file.file' => 'File upload tidak dapat dibaca. Pilih ulang file XLSX.',
            'file.uploaded' => WorkOrderUpload::errorMessage(UPLOAD_ERR_EXTENSION),
            'file.extensions' => 'Gunakan file berformat XLSX. Simpan file XLS sebagai XLSX terlebih dahulu.',
            'file.max' => 'Ukuran file melebihi batas aplikasi 10 MB.',
        ]);
        try {
            $workbook = $reader->read($file->getRealPath(), $data['sheet'] ?? null, (int) $data['header_row']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $job = DB::transaction(function () use ($request, $data, $workbook, $hash, $file) {
            $metadata = ['headers' => $workbook['headers'], 'sheet' => $workbook['sheet'], 'sheets' => $workbook['sheets'], 'date1904' => $workbook['date1904'],
                'template' => BimaWorkOrdersTemplate::mapping($workbook['headers']) !== null ? BimaWorkOrdersTemplate::NAME : null,
                'file_hash' => $hash, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 240), 'header_row' => (int) $data['header_row']];
            $job = SyncJob::firstOrCreate(['idempotency_key' => $data['idempotency_key']], ['id_users' => $request->user()->getKey(), 'direction' => 'import',
                'stage' => 'mapping', 'state' => 'awaiting_mapping', 'connection_hash' => ExcelWorkOrderImportService::connectionHash(), 'snapshot' => ['import' => $metadata], 'expires_at' => now()->addMinutes(30)]);
            abort_unless($job->id_users === $request->user()->getKey() && $job->direction === 'import', 403);
            abort_unless(($job->snapshot['import']['file_hash'] ?? null) === $hash && ($job->snapshot['import']['sheet'] ?? null) === $workbook['sheet'] && ($job->snapshot['import']['header_row'] ?? null) === (int) $data['header_row'], 409, 'Form upload sudah digunakan untuk file lain. Muat ulang Sync Center.');
            if ($job->wasRecentlyCreated) {
                $batch = [];
                $bytes = 0;
                foreach ($workbook['rows'] as $index => $cells) {
                    // Preserve encrypted casts while batching below a conservative MySQL packet budget.
                    $attributes = (new SyncJobRow(['id_sync_jobs' => $job->getKey(), 'row_index' => $index, 'action' => 'pending', 'payload' => ['raw' => $cells]]))->getAttributes();
                    $size = strlen($attributes['payload']) + 256;
                    if ($batch && (count($batch) >= 100 || $bytes + $size > 262144)) {
                        SyncJobRow::insert($batch);
                        $batch = [];
                        $bytes = 0;
                    }
                    $batch[] = $attributes;
                    $bytes += $size;
                }
                if ($batch) {
                    SyncJobRow::insert($batch);
                }
                AuditLog::create(['id_users' => $job->id_users, 'action' => 'excel.uploaded', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => ['rows' => count($workbook['rows']), 'file_hash' => $hash]]);
            }

            return $job;
        });

        return redirect()->route('imports.mapping', $job);
    }

    public function mapping(Request $request, SyncJob $syncJob, ExcelWorkOrderMapper $mapper)
    {
        $this->authorizeImport();
        abort_unless($syncJob->direction === 'import' && $syncJob->id_users === auth()->id(), 403);
        if ($syncJob->stage === 'commit') {
            return redirect()->route('sync.show', $syncJob);
        }

        $this->currentVersion($syncJob);
        $meta = $syncJob->snapshot['import'];
        $preset = BimaWorkOrdersTemplate::mapping($meta['headers']);
        $mapping = $meta['mapping'] ?? $mapper->suggest($meta['headers']);
        $useBimaDefaults = $preset !== null && $request->boolean('use_bima_mapping');
        if ($preset !== null) {
            // Re-detect existing pending uploads too; this GET does not rewrite their stored snapshots.
            $meta['template'] = BimaWorkOrdersTemplate::NAME;
            if ($useBimaDefaults) {
                $mapping = $preset;
            }
        }
        $fields = ExcelWorkOrderMapper::inputFields();
        $primaryFields = $preset === null ? $fields : collect($preset)->map(fn ($column, $field) => $fields[$field])->all();

        return view('imports.mapping', ['job' => $syncJob, 'meta' => $meta, 'fields' => $fields,
            'primaryFields' => $primaryFields, 'additionalFields' => array_diff_key($fields, $primaryFields), 'presetMapping' => $preset, 'useBimaDefaults' => $useBimaDefaults, 'mapping' => $mapping,
            'rowCount' => $syncJob->rows()->count(),
            'sample' => $syncJob->rows()->orderBy('row_index')->limit(3)->get()->map(fn ($row) => ['row_index' => $row->row_index, 'raw' => $row->payload['raw'] ?? []])]);
    }

    public function preview(Request $request, SyncJob $syncJob)
    {
        $this->authorizeImport();
        abort_unless($syncJob->direction === 'import' && $syncJob->id_users === $request->user()->getKey(), 403);
        $this->currentVersion($syncJob);
        $data = $request->validate(['confirmed_mapping' => 'accepted', 'mapping' => 'required|array:'.implode(',', array_keys(ExcelWorkOrderMapper::inputFields())),
            'mapping.no_tq_sc_id' => 'required|integer|min:1|max:'.count($syncJob->snapshot['import']['headers']), 'mapping.*' => 'nullable|integer|min:1|max:'.count($syncJob->snapshot['import']['headers'])]);
        $mapping = array_map('intval', array_filter($data['mapping'], fn ($value) => $value !== null && $value !== ''));
        if (! isset($mapping['sto']) && ! isset($mapping['branch'])) {
            throw ValidationException::withMessages(['mapping' => 'Pilih kolom STO atau BRANCH sumber untuk routing Branch.']);
        }
        if (count(array_unique($mapping)) !== count($mapping)) {
            throw ValidationException::withMessages(['mapping' => 'Satu kolom file hanya boleh dipetakan ke satu tujuan.']);
        }
        foreach ($mapping as $column) {
            if (trim($syncJob->snapshot['import']['headers'][$column - 1]) === '') {
                throw ValidationException::withMessages(['mapping' => 'Kolom tanpa header tidak dapat dipilih.']);
            }
        }
        $preset = BimaWorkOrdersTemplate::mapping($syncJob->snapshot['import']['headers']);
        if ($preset !== null && isset($mapping['tgl_ps']) && $mapping['tgl_ps'] !== (int) $preset['tgl_ps']) {
            throw ValidationException::withMessages(['mapping.tgl_ps' => 'Untuk export BIMA Work Orders, pilih Date Modified sebagai Tanggal PS atau Tidak diimpor. Tanggal hanya diterapkan pada status PS dengan Mapping Order PS aktif.']);
        }
        DB::transaction(function () use ($request, $syncJob, $mapping) {
            $job = SyncJob::whereKey($syncJob->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(in_array($job->state, ['awaiting_mapping', 'preview_ready'], true) && $job->stage !== 'commit' && $job->expires_at?->isFuture(), 409, 'Mapping sudah diproses atau kedaluwarsa. Upload ulang file.');
            $snapshot = $job->snapshot;
            $snapshot['import']['mapping'] = $mapping;
            $job->update(['snapshot' => $snapshot, 'stage' => 'preview', 'state' => 'queued', 'stats' => null, 'error' => null, 'finished_at' => null]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'excel.mapping_confirmed', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => ['fields' => array_keys($mapping)]]);
            ProcessWorkOrderImport::dispatch($job->getKey())->afterCommit();
        });

        return redirect()->route('sync.show', $syncJob);
    }
}
