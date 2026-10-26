<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Models\WorkOrder;
use App\Services\BranchAnalyticsService;
use App\Services\BranchOrderScope;

class BranchDashboardController extends Controller
{
    public function index(DashboardRequest $request, BranchAnalyticsService $analytics, BranchOrderScope $scope)
    {
        $filters = $request->validated();

        return view('branch.dashboard', $analytics->summary($request->user(), $filters) + [
            'branches' => $scope->branches($request->user()), 'filters' => $filters,
            'types' => WorkOrder::visibleTo($request->user())->select('jenis_pekerjaan')->distinct()->orderBy('jenis_pekerjaan')->pluck('jenis_pekerjaan')->filter(),
        ]);
    }
}
