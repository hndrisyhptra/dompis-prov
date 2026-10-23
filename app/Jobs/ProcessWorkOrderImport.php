<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Services\ExcelWorkOrderImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProcessWorkOrderImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(public int $syncJobId) {}

    public function backoff(): array
    {
        return [10, 30, 90, 180];
    }

    public function handle(ExcelWorkOrderImportService $service): void
    {
        $job = SyncJob::findOrFail($this->syncJobId);
        if ($job->direction !== 'import' || ! $job->busy()) {
            return;
        }
        $lock = Cache::lock('olo:work-order-inbound', 240);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $job->refresh();
            if (! $job->busy()) {
                return;
            }
            if (! $job->actor?->canSync() || config('olo.inbound') !== 'excel') {
                throw new InvalidArgumentException('Hak import operator atau mode import sudah berubah.');
            }
            if ($job->connection_hash !== ExcelWorkOrderImportService::connectionHash()) {
                throw new InvalidArgumentException('Versi import berubah. Upload ulang file.');
            }
            $job->update(['state' => 'running', 'started_at' => $job->started_at ?? now(), 'attempts' => $job->attempts + 1, 'error' => null]);
            if ($job->stage === 'preview') {
                $service->preview($job);
            } elseif ($job->stage === 'commit') {
                $service->commit($job);
            } else {
                throw new InvalidArgumentException('Tahap import belum siap diproses.');
            }
        } catch (InvalidArgumentException $exception) {
            $job->update(['state' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);
        } catch (Throwable) {
            $job->update(['state' => 'retrying', 'error' => 'Import belum selesai. Periksa database dan worker, lalu coba lagi.', 'finished_at' => null]);
            throw new RuntimeException('Import Excel belum selesai.');
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        SyncJob::whereKey($this->syncJobId)->whereIn('state', ['queued', 'running', 'retrying'])->update(['state' => 'failed', 'error' => 'Batas retry import tercapai. Periksa worker dan coba ulang.', 'finished_at' => now()]);
    }
}
