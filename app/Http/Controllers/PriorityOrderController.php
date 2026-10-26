<?php

namespace App\Http\Controllers;

use App\Http\Requests\PriorityOrderListRequest;
use App\Models\WorkOrder;
use App\Services\BranchOrderScope;
use App\Services\PriorityOrderQuery;
use App\Services\PriorityOrderService;
use App\Services\WorkOrderQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PriorityOrderController extends Controller
{
    public function index(PriorityOrderListRequest $request, BranchOrderScope $scope, WorkOrderQueueService $queues, PriorityOrderQuery $priorities)
    {
        Gate::authorize('priority-orders');
        $actor = $request->user();
        $area = $actor->role->areaScope();
        $filters = $request->validated();
        $base = $priorities->base($actor, $filters);
        $today = CarbonImmutable::now('Asia/Jakarta');
        $summary = [];
        foreach (['all', 'outstanding', 'issues', 'waiting_bima'] as $state) {
            $summary[$state] = $queues->apply(clone $base, $state, $today)->count();
        }
        $query = $priorities->ordered($actor, $filters);

        return view('priorities.index', ['orders' => $query->paginate(25)->withQueryString(),
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
