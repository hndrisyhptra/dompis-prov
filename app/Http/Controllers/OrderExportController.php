<?php

namespace App\Http\Controllers;

use App\Http\Requests\PriorityOrderListRequest;
use App\Http\Requests\WorkOrderListRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Exports\OrderExportService;
use App\Services\Exports\XlsxTableWriter;
use App\Services\PriorityOrderQuery;
use App\Services\WorkOrderListQuery;
use App\Services\WorkOrderQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Throwable;

class OrderExportController extends Controller
{
    public function orders(WorkOrderListRequest $request, WorkOrderListQuery $list, WorkOrderQueueService $queues, OrderExportService $exports)
    {
        Gate::authorize('order-export');
        $filters = $request->validated();
        $query = $list->query($request->user(), $filters);
        $queues->apply($query, $filters['queue'] ?? 'all', CarbonImmutable::now('Asia/Jakarta')->startOfDay());
        $query->orderByRaw('CASE WHEN last_progress_at IS NULL THEN 0 ELSE 1 END')->latest('tgl_order')->latest('id_work_orders');

        return $this->download($request->user(), $filters, 'work-orders', $exports->document($query), $exports->context($request->user(), $filters, false));
    }

    public function priorities(PriorityOrderListRequest $request, PriorityOrderQuery $priorities, OrderExportService $exports)
    {
        $filters = $request->validated();
        $query = $priorities->ordered($request->user(), $filters)->setEagerLoads([]);

        return $this->download($request->user(), $filters, 'priority-orders', $exports->document($query, true), $exports->context($request->user(), $filters, true));
    }

    private function download(User $user, array $filters, string $table, array $document, array $context)
    {
        $path = app(XlsxTableWriter::class)->create($document, $context);
        try {
            AuditLog::create(['id_users' => $user->getKey(), 'action' => 'orders.exported', 'entity_type' => 'order_export', 'id_entity' => 0,
                'metadata' => ['table' => $table, 'filter_keys' => array_keys($filters), 'filter_hash' => hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR))]]);

            return response()->download($path, 'dompis-'.$table.'-'.CarbonImmutable::now('Asia/Jakarta')->format('Ymd-His').'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }
}
