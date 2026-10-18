<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ServiceArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MapStoBranch extends Command
{
    protected $signature = 'olo:sto-map {workzone : Kode STO} {branch : Kode branch aplikasi}';

    protected $description = 'Petakan satu STO ke branch aktif dengan audit; WO lama tetap perlu review konflik';

    public function handle(): int
    {
        return DB::transaction(function () {
            $sto = ServiceArea::with('region')->where('workzone', mb_strtoupper(trim($this->argument('workzone'))))->where('is_active', true)->lockForUpdate()->first();
            $branch = Branch::where('code', mb_strtoupper(trim($this->argument('branch'))))->where('active', true)->lockForUpdate()->first();
            if (! $sto || ! $branch || ! $sto->region?->is_active || ! $branch->matchesRegion($sto->region)) {
                $this->error('STO/branch tidak aktif atau region branch tidak sesuai master region STO.');

                return self::FAILURE;
            }
            // A remap must not invalidate existing user assignments silently.
            if ($sto->id_branches !== $branch->getKey() && $sto->users()->exists()) {
                $this->error('STO sudah dipakai user. Review lokasi user sebelum memindahkan branch.');

                return self::FAILURE;
            }
            $before = $sto->id_branches;
            $sto->update(['id_branches' => $branch->getKey()]);
            AuditLog::create(['action' => 'sto.branch_mapped_cli', 'entity_type' => 'service_area', 'id_entity' => $sto->getKey(), 'metadata' => ['before_id_branches' => $before, 'id_branches' => $branch->getKey()]]);
            $this->info('Mapping STO disimpan. Preview baru diperlukan; branch WO lama tidak diubah otomatis.');

            return self::SUCCESS;
        });
    }
}
