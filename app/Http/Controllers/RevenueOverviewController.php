<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Region;
use App\Services\Boq\BoqImportService;
use App\Services\BranchOrderScope;
use App\Services\RevenueOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RevenueOverviewController extends Controller
{
    public function index(Request $request, RevenueOverviewService $service, BranchOrderScope $scope)
    {
        Gate::authorize('revenue-overview');
        abort_unless(BoqImportService::available(), 503, 'Revenue Overview belum tersedia. Migrasi perhitungan BOQ perlu diselesaikan.');
        $filters = $request->validate(['region' => 'nullable|integer', 'branch' => 'nullable|integer',
            'order_month' => 'nullable|date_format:Y-m|after_or_equal:2000-01|before_or_equal:2100-12',
            'package_id' => 'nullable|integer|exists:packages,id_package', 'state' => ['nullable', Rule::in(['all', 'ps', 'ongoing', 'cancel', 'without_boq'])],
            'q' => 'nullable|string|max:100']);
        $branches = $scope->branches($request->user())->load('region');
        $regions = Region::withTrashed()->when(! $request->user()->role->areaScope(), fn ($q) => $q->whereIn('id_regions', $branches->pluck('region_id')->filter()))->orderBy('name')->get();
        if (! empty($filters['region'])) {
            abort_unless($regions->contains('id_regions', (int) $filters['region']), 403);
        }
        $query = $service->orders($request->user(), $filters);

        return view('revenue.index', $service->summary($query) + ['filters' => $filters, 'branches' => $branches, 'regions' => $regions,
            'packages' => Package::withTrashed()->orderBy('name')->get(),
            'orders' => $query->with(['boq.package', 'assignedBranch'])->withExists('boq')->orderByDesc('boq_exists')->orderByDesc('id_work_orders')->paginate(20)->withQueryString()]);
    }
}
