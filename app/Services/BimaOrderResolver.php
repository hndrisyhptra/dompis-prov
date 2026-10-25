<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\ServiceArea;
use App\Models\WorkOrder;

class BimaOrderResolver
{
    public function resolve(array $data): ?int
    {
        $sourceBranch = mb_strtoupper(trim($data['branch'] ?? ''));
        $mapping = BranchMapping::where('enabled', true)->where('bima_branch', $sourceBranch)
            ->whereIn('sto_code', ['', mb_strtoupper(trim(($data['sto'] ?? '')))])->whereHas('branch', fn ($q) => $q->where('active', true))
            ->orderByRaw("CASE WHEN sto_code = '' THEN 1 ELSE 0 END")->first();
        $mapped = $mapping?->id_branches;
        if (! $mapped && $sourceBranch !== '') {
            // Master Data can add Branches without requiring a separate legacy alias.
            $matches = Branch::where(fn ($q) => $q->whereRaw('UPPER(TRIM(code)) = ?', [$sourceBranch])->orWhereRaw('UPPER(TRIM(name)) = ?', [$sourceBranch]))
                ->pluck('id_branches');
            if ($matches->count() > 1) {
                return null;
            }
            $mapped = $matches->first();
        }
        if ($mapped && ! Branch::whereKey($mapped)->where('active', true)->exists()) {
            return null;
        }
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

        // The latest recognized BIMA Branch owns this WO; do not rewrite the shared STO master.
        return $mapped ?? $sto->id_branches;
    }

    public function classify(array $data, ?WorkOrder $existing, ?int $branch): array
    {
        if (! $branch) {
            return ['conflict', 'Mapping branch F/STO belum tersedia, tidak aktif, atau bertentangan dengan master STO.'];
        }
        $unchanged = $existing && $existing->source_snapshot_hash === $data['source_snapshot_hash'] && $existing->id_branches === $branch;

        return [! $existing ? 'new' : ($unchanged ? 'unchanged' : 'changed'), null];
    }
}
