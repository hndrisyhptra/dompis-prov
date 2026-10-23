<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OrderMappingReferenceService;
use Illuminate\Console\Command;

class ApplyOrderMappingReference extends Command
{
    protected $signature = 'olo:apply-order-mapping {--actor= : Username administrator untuk audit}';

    protected $description = 'Apply the approved BIMA mapping reference and refresh existing order classifications';

    public function handle(OrderMappingReferenceService $service): int
    {
        $actor = $this->option('actor') ? User::where('username', $this->option('actor'))->first() : null;
        if ($this->option('actor') && (! $actor || ! $actor->active || ! $actor->role->sync())) {
            $this->error('Actor harus berupa akun aktif yang memiliki izin sinkronisasi Area.');

            return self::FAILURE;
        }
        $stats = $service->apply($service->reference(), $actor);
        $this->table(['Hasil', 'Jumlah'], collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
