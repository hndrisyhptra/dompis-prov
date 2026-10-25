<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\BranchOrderScope;
use App\Services\PriorityOrderService;
use App\Services\WorkOrderQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PriorityOrderController extends Controller
{
    public function index(Request $request, BranchOrderScope $scope, WorkOrderQueueService $queues)
    {
        Gate::authorize('priority-orders');
        $actor = $request->user();
        $area = $actor->role->areaScope();
        $filters = $request->validate(['q' => 'nullable|string|max:191', 'branch' => 'nullable|integer',
            'source' => ['nullable', Rule::in($area ? ['area'] : ['all', 'area', 'branch'])],
            'state' => ['nullable', Rule::in(['all', 'outstanding', 'issues', 'waiting_bima', 'closed'])],
            'sort' => ['nullable', Rule::in(['selected', 'age', 'updated'])]]);
        $visible = fn ($q) => $q->visibleTo($actor)->when(($filters['source'] ?? 'all') === 'area', fn ($q) => $q->where('scope_key', 'area'))
            ->when(($filters['source'] ?? 'all') === 'branch', fn ($q) => $q->whereNotNull('id_branches'));
        $base = $scope->orders($actor, ['jenis_pekerjaan' => 'all'] + $filters)->whereHas('priorities', $visible)
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('no_tq_sc_id', 'like', '%'.$term.'%')->orWhere('sid', 'like', '%'.$term.'%')->orWhere('nama_olo', 'like', '%'.$term.'%')));
        $today = CarbonImmutable::now('Asia/Jakarta');
        $summary = [];
        foreach (['all', 'outstanding', 'issues', 'waiting_bima'] as $state) {
            $summary[$state] = $queues->apply(clone $base, $state, $today)->count();
        }
        $query = $queues->apply(clone $base, $filters['state'] ?? 'all', $today)->with(['assignedBranch', 'priorities' => fn ($q) => $q->visibleTo($actor)->with('creator:id_users,name')])
            ->withCount(['issues as open_issues_count' => fn ($q) => $q->where('status', 'OPEN')])->withMax(['priorities as priority_selected_at' => $visible], 'created_at');
        match ($filters['sort'] ?? 'selected') {
            'age' => $query->orderByRaw('CASE WHEN tgl_order IS NULL THEN 1 ELSE 0 END')->orderBy('tgl_order'),
            'updated' => $query->orderByDesc('last_progress_at'),
            default => $query->orderByDesc('priority_selected_at'),
        };

        return view('priorities.index', ['orders' => $query->orderByDesc('id_work_orders')->paginate(25)->withQueryString(),
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
