<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PriorityOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReleasePsPriorities extends Command
{
    protected $signature = 'olo:release-ps-priorities {--actor= : Username operator Area untuk audit} {--dry-run : Periksa tanpa menyimpan}';

    protected $description = 'Lepas bookmark Area dan Branch untuk order yang sudah memenuhi kriteria PS';

    public function handle(PriorityOrderService $service): int
    {
        $actor = $this->option('actor') ? User::where('username', $this->option('actor'))->first() : null;
        if (! $actor?->canSync()) {
            $this->error('Isi --actor dengan username operator Area aktif yang memiliki izin sinkronisasi.');

            return self::FAILURE;
        }
        $lock = Cache::lock('olo:work-order-inbound', 300);
        if (! $lock->get()) {
            $this->error('Import sedang berjalan. Ulangi setelah import selesai.');

            return self::FAILURE;
        }
        try {
            $orders = 0;
            $bookmarks = 0;
            $dryRun = (bool) $this->option('dry-run');
            WorkOrder::psEligible()->whereHas('priorities')->withCount('priorities')->chunkById(200, function ($batch) use ($actor, $service, $dryRun, &$orders, &$bookmarks) {
                foreach ($batch as $order) {
                    $released = $dryRun ? $order->priorities_count : $service->releaseForPs($order, $actor, 'reconcile');
                    $orders += $released > 0 ? 1 : 0;
                    $bookmarks += $released;
                }
            }, 'id_work_orders');
            $this->info(($dryRun ? 'Preview' : 'Selesai').': '.$orders.' order PS, '.$bookmarks.' bookmark '.($dryRun ? 'akan dilepas.' : 'dilepas.'));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
