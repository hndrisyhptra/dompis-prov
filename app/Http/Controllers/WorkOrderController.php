<?php

namespace App\Http\Controllers;

use App\Enums\ProgressCategory;
use App\Models\MappingOwnergrup;
use App\Models\Region;
use App\Models\WorkOrder;
use App\Services\BranchOrderScope;
use App\Services\WorkOrderQueueService;
use App\Support\ProgressSummary;
use App\Support\WorkOrderActions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function index(Request $request, BranchOrderScope $scope, WorkOrderQueueService $queues)
    {
        if (! $request->has('jenis_pekerjaan') && $request->has('job_type')) {
            $request->merge(['jenis_pekerjaan' => $request->input('job_type')]);
        }
        $filters = $request->validate(['q' => 'nullable|string|max:191', 'jenis_pekerjaan' => 'nullable|string|max:80', 'status' => 'nullable|string|max:80', 'branch' => 'nullable|integer',
            'region' => 'nullable|integer|exists:regions,id_regions', 'service' => 'nullable|string|max:255',
            'order_month' => 'nullable|date_format:Y-m|after_or_equal:2000-01|before_or_equal:2100-12',
            'owner_group' => ['nullable', Rule::in([...MappingOwnergrup::GROUPS, 'unmapped'])],
            'bucket' => ['nullable', Rule::in([...array_keys(ProgressSummary::GROUPS), 'other', 'cndc'])],
            'without_region' => 'nullable|boolean', 'unassigned' => 'nullable|boolean', 'modal' => 'nullable|boolean',
            'ps_date' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'queue' => ['nullable', Rule::in(['all', 'today', 'ps_today', 'complete', 'closed', 'outstanding', 'issues', 'unupdated', 'waiting_bima', 'missing_ps_date', 'future_ps_date', 'unmapped', 'potential_ps', 'new_order'])]]);
        $base = $scope->orders($request->user(), $filters);
        $query = (clone $base)->with(['assignedBranch', 'priorities' => fn ($q) => $q->visibleTo($request->user())])->withCount(['issues as open_issues_count' => fn ($query) => $query->where('status', 'OPEN')])
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->where('no_tq_sc_id', 'like', '%'.$value.'%')->orWhere('sid', 'like', '%'.$value.'%')->orWhere('nama_olo', 'like', '%'.$value.'%')))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where(ProgressCategory::tryFrom($value) ? 'progress_category' : 'operational_status', $value));
        $queue = $filters['queue'] ?? 'all';
        if (! empty($filters['bucket'])) {
            ProgressSummary::filter($query, $filters['bucket']);
        }
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        if (! empty($filters['ps_date'])) {
            $query->psOnDate($filters['ps_date']);
        }
        if ($request->boolean('modal')) {
            // Apply the same queue before pagination; never return an unscoped dashboard list.
            $queues->apply($query, $queue, $today);
            $orders = $query->latest('tgl_order')->latest('id_work_orders')->paginate(25)->withQueryString();

            return response()->view('dashboard.orders', ['orders' => $orders, 'listUrl' => route('work-orders.index', array_diff_key($filters, ['modal' => true]))])
                ->header('X-Dashboard-Fragment', 'orders')->header('Cache-Control', 'private, no-store');
        }
        $queueCounts = $queues->counts(clone $query, $today);
        $queues->apply($query, $queue, $today);

        return view('work-orders.index', ['orders' => $query->orderByRaw('CASE WHEN last_progress_at IS NULL THEN 0 ELSE 1 END')->latest('tgl_order')->latest('id_work_orders')->paginate(25)->withQueryString(),
            'queueCounts' => $queueCounts, 'contextRegion' => ! empty($filters['region']) ? Region::withTrashed()->find($filters['region'])?->name : null,
            'types' => WorkOrder::visibleTo($request->user())->select('jenis_pekerjaan')->distinct()->orderBy('jenis_pekerjaan')->pluck('jenis_pekerjaan')->map(fn ($type) => strtoupper(trim($type ?? '', ' ')) ?: 'TANPA JENIS')->unique(),
            'branches' => $scope->branches($request->user()), 'filters' => $filters,
            'total' => (clone $query)->count(), 'blocked' => (clone $base)->outstanding()->whereHas('issues', fn ($q) => $q->where('status', 'OPEN'))->count(),
            'complete' => (clone $base)->psEligible()->count()]);
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);
        $request->validate(['action' => ['nullable', Rule::in(['progress', 'issue', 'close'])]]);
        if ($request->filled('action')) {
            return redirect()->route('work-orders.action.edit', [$workOrder, $request->input('action')]);
        }

        return view('work-orders.show', ['order' => $workOrder->load(['assignedBranch', 'events.actor', 'issues.notes.actor', 'priorities' => fn ($q) => $q->visibleTo($request->user())]), 'taskLogs' => $workOrder->taskLogs()->with('actor')->paginate(20, ['*'], 'task_page')]);
    }

    public function editAction(Request $request, WorkOrder $workOrder, string $mode)
    {
        Gate::authorize('update', $workOrder);
        $definition = WorkOrderActions::definition($mode);
        abort_if($workOrder->terminal() && ! $request->user()->role->areaScope() && ! ($mode === 'close' && $workOrder->progress_category === 'CLOSE'), 403, 'Koreksi order yang sudah ditutup memerlukan Admin Area.');

        return view('work-orders.action', ['order' => $workOrder->load(['assignedBranch', 'issues.notes.actor']), 'mode' => $mode, 'definition' => $definition]);
    }
}
