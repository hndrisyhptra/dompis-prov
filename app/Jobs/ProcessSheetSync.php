<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Services\BimaSheetPullService;
use App\Services\MonitoringSheetPushService;
use App\Services\Sheets\SheetException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
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
        $lock = Cache::lock('sheets:'.$job->connection_hash, 240);
        if (! $lock->get()) {
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
            if ($job->connection_hash !== BimaSheetPullService::connectionHash()) {
                throw new \InvalidArgumentException('Konfigurasi koneksi berubah. Buat job baru.');
            }
            $job->update(['state' => 'running', 'started_at' => $job->started_at ?? now(), 'attempts' => $job->attempts + 1, 'error' => null]);
            if ($job->direction === 'push') {
                app(MonitoringSheetPushService::class)->push($job);
            } elseif ($job->stage === 'preview') {
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
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        SyncJob::whereKey($this->syncJobId)->whereIn('state', ['queued', 'running', 'retrying'])->update(['state' => 'failed', 'error' => 'Batas retry tercapai atau worker berhenti. Periksa konfigurasi lalu jalankan ulang.', 'finished_at' => now()]);
    }
}
