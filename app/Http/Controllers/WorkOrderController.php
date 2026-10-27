<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderListRequest;
use App\Models\Region;
use App\Models\WorkOrder;
use App\Services\Boq\BoqImportService;
use App\Services\BranchOrderScope;
use App\Services\WorkOrderListQuery;
use App\Services\WorkOrderQueueService;
use App\Support\ScopedOrderListPermissions;
use App\Support\WorkOrderActions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function index(WorkOrderListRequest $request, BranchOrderScope $scope, WorkOrderQueueService $queues, WorkOrderListQuery $list)
    {
        $filters = $request->validated();
        $base = $list->query($request->user(), $filters);
        $queue = $filters['queue'] ?? 'all';
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        if ($request->boolean('modal')) {
            // Apply the same queue before pagination; never return an unscoped dashboard list.
            // This read-only fragment does not render bookmarks, source JSON or issue counts.
            $query = (clone $base)->select(array_map(fn ($column) => 'work_orders.'.$column, [
                'id_work_orders', 'id_branches', 'no_tq_sc_id', 'sto', 'nama_olo', 'progress_category', 'operational_status',
                'status_order', 'task_bima', 'status_bima', 'tgl_order', 'tgl_ps', 'mapping_order',
            ]))->with('assignedBranch');
            $queues->apply($query, $queue, $today);
            $orders = $query->latest('tgl_order')->latest('id_work_orders')->paginate(25)->withQueryString();

            return response()->view('dashboard.orders', ['orders' => $orders, 'listUrl' => route('work-orders.index', array_diff_key($filters, ['modal' => true]))])
                ->header('X-Dashboard-Fragment', 'orders')->header('Cache-Control', 'private, no-store');
        }
        $query = (clone $base)->with(['assignedBranch', 'priorities' => fn ($q) => $q->visibleTo($request->user())])->withCount(['issues as open_issues_count' => fn ($query) => $query->where('status', 'OPEN')]);
        $queueCounts = $queues->counts($base, $today);
        $queues->apply($query, $queue, $today);
        $orders = $query->orderByRaw('CASE WHEN last_progress_at IS NULL THEN 0 ELSE 1 END')->latest('tgl_order')->latest('id_work_orders')->paginate(25)->withQueryString();

        return view('work-orders.index', ['orders' => $orders, 'scopedOrderPermissions' => ScopedOrderListPermissions::for($request->user()),
            'queueCounts' => $queueCounts, 'contextRegion' => ! empty($filters['region']) ? Region::withTrashed()->find($filters['region'])?->name : null,
            'types' => WorkOrder::visibleTo($request->user())->select('jenis_pekerjaan')->distinct()->orderBy('jenis_pekerjaan')->pluck('jenis_pekerjaan')->map(fn ($type) => strtoupper(trim($type ?? '', ' ')) ?: 'TANPA JENIS')->unique(),
            'branches' => $scope->branches($request->user()), 'filters' => $filters,
            'total' => $orders->total()]);
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);
        $request->validate(['action' => ['nullable', Rule::in(['progress', 'issue', 'close'])]]);
        if ($request->filled('action')) {
            return redirect()->route('work-orders.action.edit', [$workOrder, $request->input('action')]);
        }

        return view('work-orders.show', ['order' => $workOrder->load(['assignedBranch', 'events.actor', 'issues.notes.actor', 'priorities' => fn ($q) => $q->visibleTo($request->user())] + (BoqImportService::available() ? ['boq' => fn ($q) => $q] : [])), 'taskLogs' => $workOrder->taskLogs()->with('actor')->paginate(20, ['*'], 'task_page')]);
    }

    public function editAction(Request $request, WorkOrder $workOrder, string $mode)
    {
        Gate::authorize('update', $workOrder);
        $definition = WorkOrderActions::definition($mode);
        abort_if($workOrder->terminal() && ! $request->user()->role->areaScope() && ! ($mode === 'close' && $workOrder->progress_category === 'CLOSE'), 403, 'Koreksi order yang sudah ditutup memerlukan Admin Area.');

        return view('work-orders.action', ['order' => $workOrder->load(['assignedBranch', 'issues.notes.actor']), 'mode' => $mode, 'definition' => $definition]);
    }
}
