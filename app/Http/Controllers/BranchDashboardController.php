<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\BranchAnalyticsService;
use App\Services\BranchOrderScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchDashboardController extends Controller
{
    public function index(Request $request, BranchAnalyticsService $analytics, BranchOrderScope $scope)
    {
        if (! $request->has('jenis_pekerjaan') && $request->has('job_type')) {
            $request->merge(['jenis_pekerjaan' => $request->input('job_type')]);
        }
        $filters = $request->validate(['branch' => 'nullable|integer', 'jenis_pekerjaan' => 'nullable|string|max:80', 'days' => ['nullable', Rule::in([7, 14, 30])]]);

        return view('branch.dashboard', $analytics->summary($request->user(), $filters) + [
            'branches' => $scope->branches($request->user()), 'filters' => $filters,
            'types' => WorkOrder::visibleTo($request->user())->select('jenis_pekerjaan')->distinct()->orderBy('jenis_pekerjaan')->pluck('jenis_pekerjaan')->filter(),
        ]);
    }

    public function material(Request $request, BranchOrderScope $scope)
    {
        return view('branch.material', ['branches' => $scope->branches($request->user())]);
    }
}
