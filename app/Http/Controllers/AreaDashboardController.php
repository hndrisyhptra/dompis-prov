<?php

namespace App\Http\Controllers;

use App\Models\Region;
use App\Models\WorkOrder;
use App\Services\AreaAnalyticsService;
use App\Services\BranchOrderScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AreaDashboardController extends Controller
{
    public function index(Request $request, AreaAnalyticsService $analytics, BranchOrderScope $scope)
    {
        Gate::authorize('area-dashboard');
        $filters = $request->validate(['region' => 'nullable|integer|exists:regions,id_regions', 'branch' => 'nullable|integer',
            'service' => 'nullable|string|max:255', 'job_type' => 'nullable|string|max:80', 'month' => 'nullable|date_format:Y-m|after_or_equal:2000-01|before_or_equal:2100-12']);

        return view('area.dashboard', $analytics->summary($request->user(), $filters) + [
            'filters' => $filters, 'regions' => Region::withTrashed()->orderBy('name')->get(), 'branches' => $scope->branches($request->user()),
            'services' => WorkOrder::visibleTo($request->user())->whereNotNull('service_name')->distinct()->orderBy('service_name')->pluck('service_name')->filter(),
            'types' => WorkOrder::visibleTo($request->user())->distinct()->orderBy('job_type')->pluck('job_type')->map(fn ($type) => strtoupper(trim($type ?? '', ' ')) ?: 'TANPA JENIS')->unique(),
        ]);
    }
}
