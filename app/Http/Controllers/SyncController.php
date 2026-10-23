<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSheetSync;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Services\BimaSheetPullService;
use App\Services\ExcelWorkOrderImportService;
use App\Support\WorkOrderUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SyncController extends Controller
{
    public function index()
    {
        Gate::authorize('sync');

        $spreadsheetId = config('olo.sheets.spreadsheet_id');
        $credentialsPath = config('olo.sheets.credentials_path');
        $spreadsheetUrl = is_string($spreadsheetId) && preg_match('/^[a-zA-Z0-9_-]{10,200}$/', $spreadsheetId)
            ? 'https://docs.google.com/spreadsheets/d/'.$spreadsheetId.'/edit' : null;
        $credentialsReadable = $credentialsPath && is_file($credentialsPath) && is_readable($credentialsPath);

        return view('sync.index', ['uploadLimitBytes' => WorkOrderUpload::limitBytes(), 'uploadLimitMb' => WorkOrderUpload::limitBytes() / 1024 / 1024, 'jobs' => SyncJob::with('actor')->latest()->paginate(15),
            'lastImport' => SyncJob::where('direction', 'import')->where('stage', 'commit')->whereIn('state', ['success', 'partial_success'])->latest('finished_at')->first(),
            'spreadsheetUrl' => $spreadsheetUrl, 'credentialsReadable' => $credentialsReadable,
            'lastRead' => SyncJob::where('direction', 'pull')->where('connection_hash', BimaSheetPullService::connectionHash())
                ->whereIn('state', ['preview_ready', 'success', 'partial_success'])->whereNotNull('finished_at')->latest('finished_at')->first(),
            'lastPull' => SyncJob::where('direction', 'pull')->where('stage', 'commit')->whereIn('state', ['success', 'partial_success'])->latest('finished_at')->first()]);
    }

    public function preview(Request $request)
    {
        Gate::authorize('sync');
        abort_unless(config('olo.inbound') === 'sheets', 409, 'Penerimaan WO sekarang melalui upload Excel di Sync Center.');
        $data = $request->validate(['idempotency_key' => 'required|uuid']);
        $job = DB::transaction(function () use ($request, $data) {
            $job = SyncJob::firstOrCreate(['idempotency_key' => $data['idempotency_key']], [
                'id_users' => $request->user()->getKey(), 'direction' => 'pull', 'connection_hash' => BimaSheetPullService::connectionHash(),
            ]);
            abort_unless($job->id_users === $request->user()->getKey() && $job->direction === 'pull', 403);
            if ($job->wasRecentlyCreated) {
                ProcessSheetSync::dispatch($job->getKey())->afterCommit();
            }

            return $job;
        });

        return redirect()->route('sync.show', $job);
    }

    public function show(Request $request, SyncJob $syncJob)
    {
        Gate::authorize('sync');
        if ($request->expectsJson()) {
            return response()->json($syncJob->only(['id_sync_jobs', 'state', 'stage', 'stats', 'error', 'attempts', 'finished_at']));
        }
        if ($syncJob->direction === 'import' && $syncJob->stage === 'mapping') {
            return redirect()->route('imports.mapping', $syncJob);
        }

        return view('sync.show', ['job' => $syncJob->load('actor'), 'rows' => $syncJob->rows()->orderByRaw("CASE WHEN action IN ('rejected', 'conflict') THEN 0 ELSE 1 END")->orderBy('row_index')->paginate(30)]);
    }

    public function commit(Request $request, SyncJob $syncJob)
    {
        Gate::authorize('sync');
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($syncJob, $request) {
            $job = SyncJob::lockForUpdate()->findOrFail($syncJob->getKey());
            abort_unless($job->id_users === $request->user()->getKey(), 403);
            abort_unless(in_array($job->direction, ['import', 'pull'], true), 410, 'Publikasi MySQL ke Google Sheets sudah dinonaktifkan.');
            if ($job->stage === 'commit') {
                return; // Double click / request retry must not dispatch twice.
            }
            $expectedHash = $job->direction === 'import' ? ExcelWorkOrderImportService::connectionHash() : BimaSheetPullService::connectionHash();
            abort_unless($job->direction === 'import' ? config('olo.inbound') === 'excel' : config('olo.inbound') === 'sheets', 409, 'Mode penerimaan WO sudah berubah. Buat preview baru.');
            abort_unless($job->state === 'preview_ready' && $job->expires_at?->isFuture() && $job->connection_hash === $expectedHash, 409, 'Preview kedaluwarsa atau tidak siap. Buat preview baru.');
            abort_unless((int) ($job->stats['read'] ?? 0) > 0, 409, 'Sumber belum berisi order. Buat preview baru dengan data order.');
            $job->update(['stage' => 'commit', 'state' => 'queued', 'finished_at' => null]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => $job->direction === 'import' ? 'excel.confirmed' : 'bima.confirmed', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey()]);
            if ($job->direction === 'import') {
                ProcessWorkOrderImport::dispatch($job->getKey())->afterCommit();
            } else {
                ProcessSheetSync::dispatch($job->getKey())->afterCommit();
            }
        });

        return redirect()->route('sync.show', $syncJob);
    }

    public function retry(Request $request, SyncJob $syncJob)
    {
        Gate::authorize('sync');
        DB::transaction(function () use ($request, $syncJob) {
            $job = SyncJob::lockForUpdate()->findOrFail($syncJob->getKey());
            abort_unless($job->id_users === $request->user()->getKey(), 403);
            abort_unless(in_array($job->direction, ['import', 'pull'], true), 410, 'Publikasi MySQL ke Google Sheets sudah dinonaktifkan.');
            $expectedHash = $job->direction === 'import' ? ExcelWorkOrderImportService::connectionHash() : BimaSheetPullService::connectionHash();
            abort_unless($job->state === 'failed' && $job->connection_hash === $expectedHash, 409);
            abort_if(($job->direction === 'import' && config('olo.inbound') !== 'excel') || ($job->direction === 'pull' && config('olo.inbound') !== 'sheets'), 409, 'Mode penerimaan WO sudah berubah. Buat preview baru di Sync Center.');
            abort_if($job->direction === 'pull' && $job->stage === 'commit' && ! $job->snapshot, 409);
            $job->update(['state' => 'queued', 'error' => null, 'finished_at' => null]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'sync.retry', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey()]);
            if ($job->direction === 'import') {
                ProcessWorkOrderImport::dispatch($job->getKey())->afterCommit();
            } else {
                ProcessSheetSync::dispatch($job->getKey())->afterCommit();
            }
        });

        return redirect()->route('sync.show', $syncJob);
    }

    public function errors(SyncJob $syncJob)
    {
        Gate::authorize('sync');

        return response()->streamDownload(function () use ($syncJob) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Baris', 'No WO / TQ-SC-ID', 'Status', 'Pesan'], escape: '');
            foreach ($syncJob->rows()->whereIn('action', ['rejected', 'conflict'])->orderBy('row_index')->cursor() as $row) {
                $safe = fn ($value) => preg_match('/^[\s]*[=+@-]/', (string) $value) ? "'".$value : $value;
                fputcsv($stream, array_map($safe, [$row->row_index, $row->external_key, $row->action, $row->error]), escape: '');
            }
            fclose($stream);
        }, 'sync-'.$syncJob->getKey().'-exceptions.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
