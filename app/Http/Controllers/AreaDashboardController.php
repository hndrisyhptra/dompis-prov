<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Models\Region;
use App\Models\WorkOrder;
use App\Services\AreaAnalyticsService;
use App\Services\BranchOrderScope;
use Illuminate\Support\Facades\Gate;

class AreaDashboardController extends Controller
{
    public function index(DashboardRequest $request, AreaAnalyticsService $analytics, BranchOrderScope $scope)
    {
        Gate::authorize('area-dashboard');
        $filters = $request->validated();

        return view('area.dashboard', $analytics->summary($request->user(), $filters) + [
            'filters' => $filters, 'regions' => Region::withTrashed()->orderBy('name')->get(), 'branches' => $scope->branches($request->user()),
            'services' => WorkOrder::visibleTo($request->user())->whereNotNull('layanan')->distinct()->orderBy('layanan')->pluck('layanan')->filter(),
            'types' => WorkOrder::visibleTo($request->user())->distinct()->orderBy('jenis_pekerjaan')->pluck('jenis_pekerjaan')->map(fn ($type) => strtoupper(trim($type ?? '', ' ')) ?: 'TANPA JENIS')->unique(),
        ]);
    }
}
