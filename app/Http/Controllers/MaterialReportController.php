<?php

namespace App\Http\Controllers;

use App\Models\MaterialReport;
use App\Services\BranchOrderScope;
use App\Support\MaterialReportFields;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MaterialReportController extends Controller
{
    public function index(Request $request, BranchOrderScope $scope)
    {
        Gate::authorize('material-reports');
        abort_unless(Schema::hasTable('material_reports'), 503, 'Laporan material belum tersedia. Jalankan migrasi material.');
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'branch' => 'nullable|integer', 'state' => ['nullable', Rule::in(['submitted', 'superseded', 'all'])],
            'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        $branches = $scope->branches($request->user());
        if (! empty($filters['branch'])) {
            abort_unless($branches->contains('id_branches', (int) $filters['branch']), 403);
        }
        $base = MaterialReport::visibleTo($request->user())->when($filters['branch'] ?? null, fn ($q, $branch) => $q->where('id_branches', $branch))
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('no_tq_sc_id', 'like', '%'.$search.'%')->orWhere('report_number', 'like', '%'.$search.'%')))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from, 'Asia/Jakarta')->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', CarbonImmutable::parse($to, 'Asia/Jakarta')->addDay()->utc()));
        $summary = ['submitted' => (clone $base)->where('state', 'submitted')->count(), 'superseded' => (clone $base)->where('state', 'superseded')->count()];
        $state = $filters['state'] ?? 'submitted';
        $reports = $base->when($state !== 'all', fn ($q) => $q->where('state', $state))->with(['branch', 'actor'])->latest('id_material_reports')->paginate(20)->withQueryString();

        return view('materials.index', compact('filters', 'branches', 'summary', 'reports'));
    }

    public function show(MaterialReport $materialReport)
    {
        Gate::authorize('view', $materialReport);

        return view('materials.show', ['report' => $materialReport->load(['branch', 'actor', 'workOrder', 'supersedes']), 'fields' => MaterialReportFields::all()]);
    }

    public function template()
    {
        Gate::authorize('material-reports');

        return view('materials.template', ['template' => MaterialReportFields::template(), 'fields' => MaterialReportFields::all()]);
    }

    public function downloadTemplate()
    {
        Gate::authorize('material-reports');

        return response(MaterialReportFields::template()."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="template-material-dompis.txt"', 'Cache-Control' => 'private, no-store']);
    }
}
