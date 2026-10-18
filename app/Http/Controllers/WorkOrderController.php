<?php

namespace App\Http\Controllers;

use App\Enums\ProgressCategory;
use App\Models\Region;
use App\Models\WorkOrder;
use App\Services\BranchOrderScope;
use App\Support\ProgressSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function index(Request $request, BranchOrderScope $scope)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:191', 'job_type' => 'nullable|string|max:80', 'status' => 'nullable|string|max:80', 'branch' => 'nullable|integer',
            'region' => 'nullable|integer|exists:regions,id_regions', 'service' => 'nullable|string|max:255',
            'bucket' => ['nullable', Rule::in([...array_keys(ProgressSummary::GROUPS), 'other'])],
            'queue' => ['nullable', Rule::in(['all', 'today', 'ps_today', 'complete', 'outstanding', 'issues', 'unupdated', 'waiting_bima', 'missing_ps_date', 'future_ps_date', 'unmapped'])]]);
        $base = $scope->orders($request->user(), $filters);
        $query = (clone $base)->with('branch')->withCount(['issues as open_issues_count' => fn ($query) => $query->where('status', 'OPEN')])
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->where('external_order_id', 'like', '%'.$value.'%')->orWhere('source_sid', 'like', '%'.$value.'%')->orWhere('customer_name', 'like', '%'.$value.'%')))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where(ProgressCategory::tryFrom($value) ? 'progress_category' : 'operational_status', $value));
        $queue = $filters['queue'] ?? 'all';
        if (! empty($filters['bucket'])) {
            ProgressSummary::filter($query, $filters['bucket']);
        }
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        if ($queue === 'today') {
            $query->whereDate('order_date', $today->format('Y-m-d'));
        } elseif ($queue === 'ps_today') {
            $query->psOnDate($today->toDateString());
        } elseif ($queue === 'complete') {
            $query->psEligible();
        } elseif ($queue === 'waiting_bima') {
            $query->whereRaw('UPPER(TRIM(progress_category)) = ?', ['CLOSE'])->where(fn ($q) => $q->whereNull('bima_status')->orWhereRaw('UPPER(TRIM(bima_status)) NOT IN (?, ?)', ['CLOSE', 'COMPLETE']));
        } elseif ($queue === 'missing_ps_date') {
            $query->psEligible()->whereNull('bima_ps_date');
        } elseif ($queue === 'future_ps_date') {
            $query->psEligible()->whereDate('bima_ps_date', '>', $today->toDateString());
        } elseif ($queue === 'unmapped') {
            $query->whereNull('id_branches');
        } elseif (in_array($queue, ['outstanding', 'issues', 'unupdated'], true)) {
            $query->outstanding();
            if ($queue === 'issues') {
                $query->whereHas('issues', fn ($q) => $q->where('status', 'OPEN'));
            } elseif ($queue === 'unupdated') {
                $query->whereNull('last_progress_at');
            }
        }

        return view('work-orders.index', ['orders' => $query->orderByRaw('CASE WHEN last_progress_at IS NULL THEN 0 ELSE 1 END')->latest('order_date')->latest('id_work_orders')->paginate(25)->withQueryString(),
            'contextRegion' => ! empty($filters['region']) ? Region::withTrashed()->find($filters['region'])?->name : null,
            'types' => WorkOrder::visibleTo($request->user())->select('job_type')->distinct()->orderBy('job_type')->pluck('job_type')->map(fn ($type) => strtoupper(trim($type ?? '', ' ')) ?: 'TANPA JENIS')->unique(),
            'branches' => $scope->branches($request->user()), 'filters' => $filters,
            'total' => (clone $query)->count(), 'blocked' => (clone $base)->outstanding()->whereHas('issues', fn ($q) => $q->where('status', 'OPEN'))->count(),
            'complete' => (clone $base)->psEligible()->count()]);
    }

    public function show(WorkOrder $workOrder)
    {
        Gate::authorize('view', $workOrder);

        return view('work-orders.show', ['order' => $workOrder->load(['branch', 'events.actor', 'issues.notes.actor'])]);
    }
}
