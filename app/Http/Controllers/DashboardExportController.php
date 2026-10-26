<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Http\Requests\WorkOrderListRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Exports\DashboardExportService;
use App\Services\Exports\DashboardXlsxWriter;
use App\Services\WorkOrderListQuery;
use App\Services\WorkOrderQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Throwable;

class DashboardExportController extends Controller
{
    public function table(DashboardRequest $request, string $table, DashboardExportService $exports)
    {
        Gate::authorize('dashboard-export');
        $area = $request->routeIs('area.*');
        if ($area) {
            Gate::authorize('area-dashboard');
        }
        abort_unless(in_array($table, $area ? ['progress', 'owners', 'months', 'types', 'daily'] : ['owners', 'months', 'daily', 'attention'], true), 404);
        $filters = $request->validated();

        return $this->download($request->user(), $filters, $table, $exports->table($request->user(), $filters, $table, $area), $exports->context($request->user(), $filters, $area));
    }

    public function orders(WorkOrderListRequest $request, WorkOrderListQuery $list, WorkOrderQueueService $queues, DashboardExportService $exports)
    {
        Gate::authorize('dashboard-export');
        $filters = $request->validated();
        $query = $list->query($request->user(), $filters)->with('assignedBranch')->withCount(['issues as open_issues_count' => fn ($q) => $q->where('status', 'OPEN')]);
        $queues->apply($query, $filters['queue'] ?? 'all', CarbonImmutable::now('Asia/Jakarta')->startOfDay());
        $query->latest('tgl_order')->latest('id_work_orders');

        return $this->download($request->user(), $filters, 'orders', $exports->orders($query), array_replace($exports->context($request->user(), $filters, false), ['scope' => 'Daftar order dashboard']));
    }

    private function download(User $user, array $filters, string $table, array $document, array $context)
    {
        $path = app(DashboardXlsxWriter::class)->create($document, $context);
        try {
            AuditLog::create(['id_users' => $user->getKey(), 'action' => 'dashboard.exported', 'entity_type' => 'dashboard_export', 'id_entity' => 0,
                'metadata' => ['table' => $table, 'filter_keys' => array_keys($filters), 'filter_hash' => hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR))]]);

            return response()->download($path, 'dompis-'.$table.'-'.CarbonImmutable::now('Asia/Jakarta')->format('Ymd-His').'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }
}
