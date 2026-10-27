<?php

namespace App\Http\Controllers;

use App\Http\Requests\PriorityOrderListRequest;
use App\Models\WorkOrder;
use App\Services\BranchOrderScope;
use App\Services\OwnerGroupAnalyticsService;
use App\Services\PriorityMonitoringService;
use App\Services\PriorityOrderQuery;
use App\Services\PriorityOrderService;
use App\Services\WorkOrderQueueService;
use App\Support\ScopedOrderListPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PriorityOrderController extends Controller
{
    public function monitoring(PriorityOrderListRequest $request, BranchOrderScope $scope, PriorityOrderQuery $priorities, PriorityMonitoringService $monitoring, OwnerGroupAnalyticsService $ownerGroups)
    {
        $filters = $request->validated();
        $base = $priorities->filtered($request->user(), $filters);
        $report = $monitoring->summary($base);
        $activeTab = $filters['tab'] ?? 'branch';
        $branchOwners = $activeTab === 'owner-group' ? $ownerGroups->summary($base) : null;
        $query = (clone $base)->with('assignedBranch');
        if ($activeTab === 'issues') {
            $query = $monitoring->updatedOrders($query);
        }
        $orders = $query->orderBy('id_branches')->orderBy('task_bima')->orderBy('no_tq_sc_id')->orderBy('id_work_orders')->paginate(25)->withQueryString();
        $issueUpdates = $activeTab === 'issues' ? $monitoring->operationalUpdates($orders->getCollection()) : collect();

        return view('priorities.monitoring', ['report' => $report, 'branchOwners' => $branchOwners, 'orders' => $orders, 'filters' => $filters, 'activeTab' => $activeTab, 'issueUpdates' => $issueUpdates,
            'branches' => $scope->branches($request->user()), 'area' => $request->user()->role->areaScope()]);
    }

    public function index(PriorityOrderListRequest $request, BranchOrderScope $scope, WorkOrderQueueService $queues, PriorityOrderQuery $priorities)
    {
        Gate::authorize('priority-orders');
        $actor = $request->user();
        $area = $actor->role->areaScope();
        $filters = $request->validated();
        $base = $priorities->base($actor, $filters);
        $today = CarbonImmutable::now('Asia/Jakarta');
        $summary = $queues->selectedCounts($base, $today, ['all', 'outstanding', 'issues', 'waiting_bima']);
        $query = $priorities->ordered($actor, $filters);

        return view('priorities.index', ['orders' => $query->paginate(25)->withQueryString(),
            'scopedOrderPermissions' => ScopedOrderListPermissions::for($actor),
            'summary' => $summary, 'branches' => $scope->branches($actor), 'filters' => $filters, 'area' => $area]);
    }

    public function store(Request $request, WorkOrder $workOrder, PriorityOrderService $service)
    {
        Gate::authorize('prioritize', $workOrder);
        $this->validateScope($request);
        $service->set($request->user(), $workOrder, true);

        return back()->with('status', 'Order ditambahkan ke Prioritas '.($request->user()->role->areaScope() ? 'Area.' : 'Branch.'));
    }

    public function destroy(Request $request, WorkOrder $workOrder, PriorityOrderService $service)
    {
        Gate::authorize('prioritize', $workOrder);
        $this->validateScope($request);
        $data = $request->validate(['selection_token' => 'required|uuid']);
        $service->set($request->user(), $workOrder, false, $data['selection_token']);

        return back()->with('status', 'Bookmark prioritas dilepas. Work Order dan riwayatnya tetap tersimpan.');
    }

    private function validateScope(Request $request): void
    {
        $request->validate(['scope_key' => 'prohibited', 'id_branches' => 'prohibited', 'id_users' => 'prohibited', 'id_work_orders' => 'prohibited']);
    }
}
