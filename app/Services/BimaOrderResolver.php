<?php

namespace App\Services;

use App\Models\BranchMapping;
use App\Models\ServiceArea;
use App\Models\WorkOrder;

class BimaOrderResolver
{
    public function resolve(array $data): ?int
    {
        $mapped = BranchMapping::where('enabled', true)->where('bima_branch', mb_strtoupper(trim(($data['branch'] ?? ''))))
            ->whereIn('sto_code', ['', mb_strtoupper(trim(($data['sto'] ?? '')))])->whereHas('branch', fn ($q) => $q->where('active', true))
            ->orderByRaw("CASE WHEN sto_code = '' THEN 1 ELSE 0 END")->value('id_branches');
        $sto = ServiceArea::withTrashed()->with(['branch', 'region'])->where('workzone', mb_strtoupper(trim(($data['sto'] ?? ''))))->first();
        if (! $sto) {
            return $mapped;
        }
        if ($sto->trashed() || ! $sto->is_active || ($sto->id_regions && ! $sto->region?->is_active)) {
            return null;
        }
        if (! $sto->id_branches) {
            return $mapped;
        }
        if (! $sto->branch?->active || ($sto->branch->region_id !== null && (int) $sto->branch->region_id !== $sto->id_regions)) {
            return null;
        }

        // Competing explicit source mapping and STO ownership require Area review.
        return $mapped && $mapped !== $sto->id_branches ? null : $sto->id_branches;
    }

    public function classify(array $data, ?WorkOrder $existing, ?int $branch): array
    {
        if (! $branch) {
            return ['conflict', 'Mapping branch F/STO belum tersedia, tidak aktif, atau bertentangan dengan master STO.'];
        }
        if ($existing && ($existing->id_branches !== $branch || ($existing->branch && array_key_exists('branch', $data) && $existing->branch !== $data['branch']))) {
            return ['conflict', 'Branch sumber/mapping berubah. Perlu review Area; tidak dipindahkan otomatis.'];
        }

        return [! $existing ? 'new' : ($existing->source_snapshot_hash === $data['source_snapshot_hash'] ? 'unchanged' : 'changed'), null];
    }
}
