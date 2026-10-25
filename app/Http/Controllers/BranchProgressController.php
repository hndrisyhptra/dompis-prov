<?php

namespace App\Http\Controllers;

use App\Enums\ProgressCategory;
use App\Models\AuditLog;
use App\Models\WoIssue;
use App\Models\WorkOrder;
use App\Services\BranchProgressService;
use App\Support\WorkOrderActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BranchProgressController extends Controller
{
    public const CATEGORIES = ['ACCESS', 'MATERIAL', 'NETWORK', 'CUSTOMER', 'PERMIT', 'OTHER'];

    public function updateAction(Request $request, WorkOrder $workOrder, string $mode, BranchProgressService $service)
    {
        Gate::authorize('update', $workOrder);
        $definition = WorkOrderActions::definition($mode);
        $data = $request->validate(['revision' => 'required|integer|min:0', 'progress_category' => ['required', Rule::in(array_keys($definition['categories']))],
            'notes' => 'required|string|max:2000', 'boq_next' => ['nullable', Rule::in(['upload', 'skip']), Rule::prohibitedIf($mode !== 'progress' || $request->input('progress_category') !== 'JT')], 'operational_status' => 'prohibited', 'resolve_issues' => ['sometimes', 'boolean', Rule::prohibitedIf($mode === 'issue')]],
            ['progress_category.in' => 'Status tidak tersedia pada halaman ini.', 'notes.required' => $mode === 'issue' ? 'Detail kendala wajib diisi.' : 'Catatan pembaruan wajib diisi.']);
        $service->update($request->user(), $workOrder, $data + ['workflow_mode' => $mode]);

        if (($data['boq_next'] ?? null) === 'upload' && $data['progress_category'] === 'JT') {
            return redirect()->route('boqs.create', $workOrder)->with('status', 'Progress JT tersimpan. RAB/BOQ opsional dan dapat diunggah belakangan.');
        }

        return redirect()->route('work-orders.show', $workOrder)->with('status', $definition['title'].' disimpan dan dicatat pada riwayat order.');
    }

    public function update(Request $request, WorkOrder $workOrder, BranchProgressService $service)
    {
        Gate::authorize('update', $workOrder);
        $data = $request->validate(['revision' => 'required|integer|min:0', 'operational_status' => ['required_without:progress_category', Rule::prohibitedIf($request->filled('progress_category')), Rule::in(WorkOrder::STATUSES)], 'progress_category' => ['required_without:operational_status', Rule::enum(ProgressCategory::class)], 'notes' => 'required|string|max:2000', 'resolve_issues' => ['sometimes', 'boolean', Rule::prohibitedIf(! $request->filled('progress_category') || $request->input('progress_category') === 'KENDALA')]]);
        $service->update($request->user(), $workOrder, $data);

        return back()->with('status', 'Progress diperbarui dan dicatat pada timeline.');
    }

    public function storeIssue(Request $request, WorkOrder $workOrder)
    {
        Gate::authorize('update', $workOrder);
        $data = $request->validate(['revision' => 'required|integer|min:0', 'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => 'required|string|max:2000', 'pic' => 'required|string|max:120', 'eta' => 'nullable|date_format:Y-m-d']);
        DB::transaction(function () use ($request, $workOrder, $data) {
            $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
            Gate::authorize('update', $order);
            abort_unless($order->revision === (int) $data['revision'], 409, 'WO sudah berubah. Muat ulang.');
            abort_if($order->terminal(), 422, 'Buka kembali WO melalui Area sebelum mencatat kendala baru.');
            unset($data['revision']);
            $issue = $order->issues()->create($data + ['id_users' => $request->user()->getKey()]);
            $order->increment('revision');
            $order->update(['last_progress_at' => now()]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'issue.opened', 'entity_type' => 'wo_issue', 'id_entity' => $issue->getKey(),
                'metadata' => ['id_work_orders' => $order->getKey(), 'category' => $data['category']]]);
        });

        return back()->with('status', 'Kendala berhasil dicatat. Pilih progress KENDALA bila pekerjaan terhenti.');
    }

    public function resolveIssue(Request $request, WoIssue $issue)
    {
        Gate::authorize('update', $issue->workOrder);
        $data = $request->validate(['revision' => 'required|integer|min:0', 'resolution' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $issue, $data) {
            $order = WorkOrder::lockForUpdate()->findOrFail($issue->id_work_orders);
            Gate::authorize('update', $order);
            abort_unless($order->revision === (int) $data['revision'], 409, 'WO sudah berubah. Muat ulang.');
            $locked = WoIssue::lockForUpdate()->findOrFail($issue->getKey());
            abort_unless($locked->status === 'OPEN', 409, 'Kendala sudah ditutup.');
            $locked->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolution' => $data['resolution']]);
            $order->increment('revision');
            $order->update(['last_progress_at' => now()]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'issue.resolved', 'entity_type' => 'wo_issue', 'id_entity' => $locked->getKey(), 'metadata' => ['id_work_orders' => $order->getKey()]]);
        });

        return back()->with('status', 'Kendala diselesaikan. Sesuaikan progress sesuai kondisi lapangan.');
    }

    public function addIssueNote(Request $request, WoIssue $issue)
    {
        Gate::authorize('update', $issue->workOrder);
        $data = $request->validate(['revision' => 'required|integer|min:0', 'note' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $issue, $data) {
            $order = WorkOrder::lockForUpdate()->findOrFail($issue->id_work_orders);
            Gate::authorize('update', $order);
            abort_unless($order->revision === (int) $data['revision'], 409, 'Order sudah berubah. Muat ulang sebelum menambah catatan.');
            $locked = WoIssue::lockForUpdate()->findOrFail($issue->getKey());
            abort_unless($locked->status === 'OPEN' && ! $order->terminal(), 422, 'Catatan hanya dapat ditambahkan pada kendala aktif dan order yang belum ditutup.');
            $note = $locked->notes()->create(['id_users' => $request->user()->getKey(), 'note' => trim($data['note'])]);
            $order->increment('revision');
            $order->update(['last_progress_at' => now()]);
            AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'issue.note_added', 'entity_type' => 'wo_issue_note', 'id_entity' => $note->getKey(), 'metadata' => ['id_wo_issues' => $locked->getKey(), 'id_work_orders' => $order->getKey()]]);
        }, 3);

        return back()->with('status', 'Catatan tindak lanjut ditambahkan.');
    }
}
