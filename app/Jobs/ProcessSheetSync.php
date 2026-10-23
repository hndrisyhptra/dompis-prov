<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Services\BimaSheetPullService;
use App\Services\Sheets\SheetException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessSheetSync implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(public int $syncJobId) {}

    public function backoff(): array
    {
        return [10, 30, 90, 180];
    }

    public function handle(BimaSheetPullService $pull): void
    {
        $job = SyncJob::findOrFail($this->syncJobId);
        if (! in_array($job->state, ['queued', 'running', 'retrying'], true)) {
            return;
        }
        if ($job->direction === 'push') {
            DB::transaction(function () use ($job) {
                $job = SyncJob::lockForUpdate()->findOrFail($job->getKey());
                if (! $job->busy()) {
                    return;
                }
                $job->update(['state' => 'failed', 'finished_at' => now(), 'error' => 'Publikasi MySQL ke Google Sheets sudah dinonaktifkan.']);
                AuditLog::create(['id_users' => $job->id_users, 'action' => 'monitoring.disabled', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(), 'metadata' => ['reason' => 'feature_removed']]);
            });

            return;
        }
        $lock = Cache::lock('sheets:'.$job->connection_hash, 240);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        $inbound = $job->direction === 'pull' ? Cache::lock('olo:work-order-inbound', 240) : null;
        if ($inbound && ! $inbound->get()) {
            $lock->release();
            $this->release(15);

            return;
        }
        try {
            $job->refresh();
            if (! in_array($job->state, ['queued', 'running', 'retrying'], true)) {
                return;
            }
            if (! $job->actor?->canSync()) {
                throw new \InvalidArgumentException('Hak sinkronisasi operator sudah dicabut.');
            }
            if ($job->direction === 'pull' && config('olo.inbound') !== 'sheets') {
                throw new \InvalidArgumentException('Mode penerimaan WO sudah beralih ke import Excel.');
            }
            if ($job->connection_hash !== BimaSheetPullService::connectionHash()) {
                throw new \InvalidArgumentException('Konfigurasi koneksi berubah. Buat job baru.');
            }
            $job->update(['state' => 'running', 'started_at' => $job->started_at ?? now(), 'attempts' => $job->attempts + 1, 'error' => null]);
            if ($job->stage === 'preview') {
                $pull->preview($job);
            } else {
                $pull->pull($job);
            }
        } catch (\InvalidArgumentException $exception) {
            $job->update(['state' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);
        } catch (Throwable $exception) {
            // Never persist provider payloads, credentials, customer data or raw SQL exceptions.
            $job->update(['state' => 'retrying', 'error' => $exception instanceof SheetException ? $exception->getMessage() : 'Sinkronisasi gagal. Periksa koneksi/worker dan coba lagi.', 'finished_at' => null]);
            throw new SheetException($job->error);
        } finally {
            $inbound?->release();
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        SyncJob::whereKey($this->syncJobId)->whereIn('state', ['queued', 'running', 'retrying'])->update(['state' => 'failed', 'error' => 'Batas retry tercapai atau worker berhenti. Periksa konfigurasi lalu jalankan ulang.', 'finished_at' => now()]);
    }
}
