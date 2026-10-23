<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OwnerGroupMappingReferenceService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class ApplyOwnerGroupMappingReference extends Command
{
    protected $signature = 'olo:apply-owner-group-mapping {--actor= : Username operator Area untuk audit} {--dry-run : Periksa rencana tanpa menyimpan}';

    protected $description = 'Apply the approved Owner Group reference without changing work order source or progress';

    public function handle(OwnerGroupMappingReferenceService $service): int
    {
        $actor = $this->option('actor') ? User::where('username', $this->option('actor'))->first() : null;
        if (! $actor || ! $actor->active || ! $actor->role->sync()) {
            $this->error('Isi --actor dengan username akun Area aktif yang memiliki izin sinkronisasi.');

            return self::FAILURE;
        }
        try {
            $reference = $service->reference();
            $stats = $this->option('dry-run') ? $service->preview($reference) : $service->apply($reference, $actor);
            $this->info($this->option('dry-run') ? 'Preview saja; belum disimpan.' : 'Mapping Owner Group diterapkan. Rekap otomatis mengikuti referensi aktif.');
            $this->table(['Hasil', 'Jumlah'], collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all());

            return self::SUCCESS;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
