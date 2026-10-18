<?php

namespace App\Services;

use App\Enums\ProgressCategory;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BranchProgressService
{
    public function update(User $actor, WorkOrder $workOrder, array $data): void
    {
        DB::transaction(function () use ($actor, $workOrder, $data) {
            $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('update', $order);
            abort_unless($order->revision === (int) $data['revision'], 409, 'Progress sudah berubah. Muat ulang sebelum menyimpan.');
            if (isset($data['progress_category'])) {
                $this->updateCategory($actor, $order, $data);

                return;
            }
            $target = $data['operational_status'];
            abort_if($order->terminal() && ! $actor->role->areaScope(), 403, 'Koreksi order yang sudah ditutup memerlukan Admin Area.');
            abort_if(in_array($order->operational_status, ['COMPLETED', 'CANCELLED']) && ! $actor->role->areaScope(), 403, 'Pembukaan kembali status akhir memerlukan otorisasi Area.');
            abort_if(in_array($target, ['COMPLETED', 'CANCELLED']) && ! $actor->role->areaScope(), 403, 'Verifikasi akhir memerlukan otorisasi Area.');
            abort_if($target === 'COMPLETED' && $order->issues()->where('status', 'OPEN')->exists(), 422, 'Selesaikan kendala aktif sebelum verifikasi complete.');
            abort_if($target === 'COMPLETED' && ! in_array($order->operational_status, ['FIELD_DONE', 'PENDING_VALIDATION', 'COMPLETED']), 422, 'Verifikasi complete hanya setelah pekerjaan lapangan selesai.');
            $old = $order->operational_status;
            if ($old === $target) {
                return;
            }
            $revision = $order->revision + 1;
            $category = match ($target) {
                'SURVEY' => 'SURVEY', 'COMPLETED' => 'CLOSE', 'CANCELLED' => 'CANCEL', default => null
            };
            $oldCategory = $order->progress_category;
            $order->update(['progress_category' => $category, 'branch_completed_at' => $target === 'COMPLETED' ? ($order->branch_completed_at ?? now()) : null, 'operational_status' => $target, 'revision' => $revision, 'last_progress_at' => now(),
                'field_done_at' => in_array($target, ['FIELD_DONE', 'PENDING_VALIDATION', 'COMPLETED']) ? ($order->field_done_at ?? now()) : null,
                'verified_at' => $target === 'COMPLETED' ? now() : null,
                'verification_status' => $target === 'COMPLETED' ? 'VERIFIED' : 'UNVERIFIED']);
            $order->events()->create(['id_users' => $actor->getKey(), 'old_status' => $old, 'new_status' => $target, 'old_progress_category' => $oldCategory, 'new_progress_category' => $category, 'revision' => $revision, 'notes' => $data['notes']]);
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'progress.changed', 'entity_type' => 'work_order', 'id_entity' => $order->getKey(),
                'metadata' => ['old' => $old, 'new' => $target, 'revision' => $revision]]);
        }, 3);
    }

    private function updateCategory(User $actor, WorkOrder $order, array $data): void
    {
        $category = ProgressCategory::from($data['progress_category']);
        $oldCategory = $order->progress_category;
        abort_if($order->terminal() && $oldCategory !== $category->value && ! $actor->role->areaScope(), 403, 'Koreksi order yang sudah ditutup memerlukan Admin Area.');
        if (in_array($category, [ProgressCategory::Close, ProgressCategory::CloseOnDesk], true) && $order->issues()->where('status', 'OPEN')->exists()) {
            throw ValidationException::withMessages(['progress_category' => 'Selesaikan kendala aktif sebelum menutup order.']);
        }
        $notes = trim($data['notes']);
        $needsIssue = $category === ProgressCategory::Kendala && ! $order->issues()->where('status', 'OPEN')->exists();
        if (! $needsIssue && $oldCategory === $category->value && $order->events()->first()?->notes === $notes) {
            return;
        }
        $oldStatus = $order->operational_status;
        $revision = $order->revision + 1;
        $verified = $order->verification_status === 'VERIFIED' && $category->complete();
        $order->update([
            'progress_category' => $category->value,
            'operational_status' => $verified ? 'COMPLETED' : $category->operationalStatus(),
            'branch_completed_at' => $category->complete() ? ($order->branch_completed_at ?? now()) : null,
            'field_done_at' => $category->complete() ? ($order->field_done_at ?? now()) : null,
            'verification_status' => $verified ? 'VERIFIED' : 'UNVERIFIED',
            'verified_at' => $verified ? $order->verified_at : null,
            'revision' => $revision, 'last_progress_at' => now(),
        ]);
        $order->events()->create(['id_users' => $actor->getKey(), 'old_status' => $oldStatus, 'new_status' => $order->operational_status,
            'old_progress_category' => $oldCategory, 'new_progress_category' => $category->value, 'revision' => $revision, 'notes' => $notes]);
        if ($needsIssue) {
            $issue = $order->issues()->create(['id_users' => $actor->getKey(), 'category' => 'OTHER', 'description' => $notes, 'pic' => mb_substr($actor->name, 0, 120)]);
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'issue.opened', 'entity_type' => 'wo_issue', 'id_entity' => $issue->getKey(), 'metadata' => ['id_work_orders' => $order->getKey(), 'origin' => 'progress_kendala']]);
        }
        AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'progress.changed', 'entity_type' => 'work_order', 'id_entity' => $order->getKey(),
            'metadata' => ['old_category' => $oldCategory, 'new_category' => $category->value, 'revision' => $revision]]);
    }
}
