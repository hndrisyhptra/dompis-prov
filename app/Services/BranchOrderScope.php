<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BranchOrderScope
{
    public function branches(User $user): Collection
    {
        return $user->role->areaScope() ? Branch::withTrashed()->orderBy('name')->get()
            : $user->branches()->where('active', true)->orderBy('name')->get();
    }

    public function orders(User $user, array $filters): Builder
    {
        $query = WorkOrder::visibleTo($user);
        if (! empty($filters['branch'])) {
            abort_unless($this->branches($user)->contains('id_branches', (int) $filters['branch']), 403);
            $query->where('id_branches', $filters['branch']);
        }
        if (! empty($filters['region'])) {
            $query->whereIn('id_branches', Branch::withTrashed()->where('region_id', $filters['region'])->select('id_branches'));
        }
        if (! empty($filters['without_region'])) {
            $query->where(fn ($q) => $q->whereNull('id_branches')->orWhereIn('id_branches', Branch::withTrashed()->whereNull('region_id')->select('id_branches')));
        }
        if (! empty($filters['unassigned'])) {
            $query->whereNull('id_branches');
        }
        if (! empty($filters['service'])) {
            $query->where('layanan', $filters['service']);
        }
        if (! empty($filters['order_month'])) {
            $month = CarbonImmutable::createFromFormat('!Y-m', $filters['order_month']);
            $query->where('tgl_order', '>=', $month->toDateString())->where('tgl_order', '<', $month->addMonth()->toDateString());
        }
        $type = $filters['jenis_pekerjaan'] ?? 'NEW INSTALL';
        if ($type === 'TANPA JENIS') {
            $query->where(fn ($q) => $q->whereNull('jenis_pekerjaan')->orWhereRaw('TRIM(jenis_pekerjaan) = ?', [''])->orWhereRaw('UPPER(TRIM(jenis_pekerjaan)) = ?', ['TANPA JENIS']));
        } elseif ($type !== 'all') {
            $query->whereRaw('UPPER(TRIM(jenis_pekerjaan)) = ?', [strtoupper(trim($type, ' '))]);
        }

        return OwnerGroupAnalyticsService::filter($query, $filters['owner_group'] ?? null);
    }
}
