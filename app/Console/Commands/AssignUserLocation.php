<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignUserLocation extends Command
{
    protected $signature = 'olo:user-location {identity : Username atau email} {--region= : Kode region} {--sto= : Kode STO} {--clear : Hapus lokasi utama tanpa mengubah hak cabang}';

    protected $description = 'Tetapkan region/STO utama user; tidak memberikan hak akses branch baru';

    public function handle(): int
    {
        $identity = $this->argument('identity');
        $user = User::where(filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username', $identity)->first();
        if (! $user || (! $this->option('region') && ! $this->option('sto') && ! $this->option('clear')) || ($this->option('clear') && ($this->option('region') || $this->option('sto')))) {
            $this->error('User tidak ditemukan atau opsi lokasi tidak valid. Gunakan --region, --sto, atau --clear.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($user) {
            $user = User::lockForUpdate()->findOrFail($user->getKey());
            $region = $this->option('region') ? Region::where('code', mb_strtoupper(trim($this->option('region'))))->where('is_active', true)->lockForUpdate()->first() : null;
            $sto = $this->option('sto') ? ServiceArea::with(['branch', 'region'])->where('workzone', mb_strtoupper(trim($this->option('sto'))))->where('is_active', true)->lockForUpdate()->first() : null;
            if (($this->option('region') && ! $region) || ($this->option('sto') && (! $sto || ! $sto->branch?->active || ! $sto->region?->is_active || ! $sto->branch->matchesRegion($sto->region))) || ($region && $sto && $region->getKey() !== $sto->id_regions)) {
                $this->error('Region/STO tidak aktif, belum dipetakan ke branch, atau region tidak sesuai STO.');

                return self::FAILURE;
            }
            $region ??= $sto?->region;
            if (! $user->role->areaScope() && (($sto && ! $user->branches()->where('branches.id_branches', $sto->id_branches)->where('active', true)->exists()) || ($region && ! $sto && ! $user->branches()->where('active', true)->get()->contains(fn ($branch) => $branch->matchesRegion($region))))) {
                $this->error('Lokasi berada di luar hak cabang user. Tetapkan hak cabang melalui proses admin terlebih dahulu.');

                return self::FAILURE;
            }
            $before = $user->only(['id_regions', 'id_service_areas']);
            $user->forceFill(['id_regions' => $region?->getKey(), 'id_service_areas' => $sto?->getKey(), 'management_revision' => $user->management_revision + 1])->save();
            AuditLog::create(['action' => 'user.location_assigned_cli', 'entity_type' => 'user', 'id_entity' => $user->getKey(), 'metadata' => ['before' => $before, 'after' => $user->only(['id_regions', 'id_service_areas'])]]);
            $this->info('Lokasi utama user disimpan. Hak akses cabang tetap mengikuti user_branches.');

            return self::SUCCESS;
        });
    }
}
