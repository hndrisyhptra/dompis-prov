<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BimaAutoCloseService;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileBimaClose extends Command
{
    protected $signature = 'olo:reconcile-bima-close {--actor= : Username operator Area untuk audit} {--dry-run : Periksa tanpa menyimpan}';

    protected $description = 'Reconcile historical BIMA PS statuses to CLOSE progress with an immutable history';

    public function handle(BimaAutoCloseService $service): int
    {
        $actor = User::where('username', $this->option('actor'))->first();
        if (! $this->option('actor') || ! $actor?->canSync()) {
            $this->error('Isi --actor dengan username operator Area aktif yang memiliki izin sinkronisasi.');

            return self::FAILURE;
        }
        try {
            $result = $service->reconcile($actor, (bool) $this->option('dry-run'));
            $this->info($this->option('dry-run') ? 'Preview saja; belum disimpan.' : 'Penyesuaian CLOSE selesai. Tanggal historis dan kendala tetap dipertahankan.');
            $this->table(['Hasil', 'Jumlah'], collect($result['stats'])->map(fn ($value, $key) => [$key, $value])->values()->all());
            $this->table(['Bulan order', 'Status BIMA sesuai PS', 'Sudah CLOSE', 'Perlu CLOSE', 'Diperbarui'], collect($result['months'])->map(fn ($row, $month) => [$month, ...array_values($row)])->values()->all());

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
