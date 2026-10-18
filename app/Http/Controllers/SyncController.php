<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSheetSync;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Services\BimaSheetPullService;
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

        return view('sync.index', ['jobs' => SyncJob::with('actor')->latest()->paginate(15),
            'spreadsheetUrl' => $spreadsheetUrl, 'credentialsReadable' => $credentialsReadable,
            'lastRead' => SyncJob::where('direction', 'pull')->where('connection_hash', BimaSheetPullService::connectionHash())
                ->whereIn('state', ['preview_ready', 'success', 'partial_success'])->whereNotNull('finished_at')->latest('finished_at')->first(),
            'lastPull' => SyncJob::where('direction', 'pull')->where('stage', 'commit')->whereIn('state', ['success', 'partial_success'])->latest('finished_at')->first(),
            'lastPush' => SyncJob::where('direction', 'push')->where('state', 'success')->latest('finished_at')->first()]);
    }

    public function preview(Request $request)
    {
        Gate::authorize('sync');
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

    public function push(Request $request)
    {
        Gate::authorize('sync');
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'confirmed' => 'accepted']);
        $job = DB::transaction(function () use ($request, $data) {
            $job = SyncJob::firstOrCreate(['idempotency_key' => $data['idempotency_key']], [
                'id_users' => $request->user()->getKey(), 'direction' => 'push', 'stage' => 'commit', 'connection_hash' => BimaSheetPullService::connectionHash(),
            ]);
            abort_unless($job->id_users === $request->user()->getKey() && $job->direction === 'push', 403);
            if ($job->wasRecentlyCreated) {
                AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'monitoring.confirmed', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey()]);
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

        return view('sync.show', ['job' => $syncJob->load('actor'), 'rows' => $syncJob->rows()->orderByRaw("CASE WHEN action IN ('rejected', 'conflict') THEN 0 ELSE 1 END")->orderBy('row_index')->paginate(30)]);
    }

    public function commit(Request $request, SyncJob $syncJob)
    {
        Gate::authorize('sync');
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($syncJob, $request) {
            $job = SyncJob::lockForUpdate()->findOrFail($syncJob->getKey());
            abort_unless($job->id_users === $request->user()->getKey(), 403);
            if ($job->stage === 'commit') {
                return; // Double click / request retry must not dispatch twice.
            }
            abort_unless($job->state === 'preview_ready' && $job->expires_at?->isFuture() && $job->connection_hash === BimaSheetPullService::connectionHash(), 409, 'Preview kedaluwarsa atau tidak siap. Buat preview baru.');
            abort_unless((int) ($job->stats['read'] ?? 0) > 0, 409, 'BIMA_RAW belum berisi order. Tempel data lalu buat preview baru.');
            $job->update(['stage' => 'commit', 'state' => 'queued', 'finished_at' => null]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'bima.confirmed', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey()]);
            ProcessSheetSync::dispatch($job->getKey())->afterCommit();
        });

        return redirect()->route('sync.show', $syncJob);
    }

    public function retry(Request $request, SyncJob $syncJob)
    {
        Gate::authorize('sync');
        DB::transaction(function () use ($request, $syncJob) {
            $job = SyncJob::lockForUpdate()->findOrFail($syncJob->getKey());
            abort_unless($job->id_users === $request->user()->getKey(), 403);
            abort_unless($job->state === 'failed' && $job->connection_hash === BimaSheetPullService::connectionHash(), 409);
            abort_if($job->direction === 'pull' && $job->stage === 'commit' && ! $job->snapshot, 409);
            $job->update(['state' => 'queued', 'error' => null, 'finished_at' => null]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'sync.retry', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey()]);
            ProcessSheetSync::dispatch($job->getKey())->afterCommit();
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
