<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PriorityBulkBookmarkService
{
    public function preview(User $actor, string $input): array
    {
        Gate::forUser($actor)->authorize('priority-orders');
        $numbers = preg_split('/[\s,;]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $numbers || count($numbers) > 50) {
            throw ValidationException::withMessages(['numbers' => 'Masukkan 1–50 No WO. Maksimal 50 entri termasuk nomor duplikat.']);
        }
        foreach ($numbers as $number) {
            if (mb_strlen($number) > 191) {
                throw ValidationException::withMessages(['numbers' => 'Setiap No WO maksimal 191 karakter.']);
            }
        }
        // Database collations may be insensitive; filter again using exact source identity.
        $orders = WorkOrder::visibleTo($actor)->whereIn('no_tq_sc_id', $numbers)
            ->select(['id_work_orders', 'id_branches', 'no_tq_sc_id', 'progress_category', 'status_order', 'task_bima', 'status_bima'])
            ->with('assignedBranch:id_branches,name')
            ->withExists(['priorities as own_priority' => fn ($q) => $actor->role->areaScope()
                ? $q->where('scope_key', 'area')->whereNull('id_branches')
                : $q->whereColumn('work_order_priorities.id_branches', 'work_orders.id_branches')->whereNotNull('id_branches')])
            ->get()->groupBy(fn ($order) => 'wo:'.$order->no_tq_sc_id);
        $rows = $entries = $seen = [];
        $counts = array_fill_keys(['ready', 'selected', 'ps', 'missing', 'duplicate', 'ambiguous'], 0);
        foreach ($numbers as $number) {
            $key = 'wo:'.$number;
            $matches = $orders->get($key, collect());
            $order = $matches->count() === 1 ? $matches->first() : null;
            $state = isset($seen[$key]) ? 'duplicate' : ($matches->isEmpty() ? 'missing' : ($matches->count() > 1 ? 'ambiguous'
                : ($order->isPsEligible() ? 'ps' : ($order->own_priority ? 'selected' : 'ready'))));
            $seen[$key] = true;
            $counts[$state]++;
            $rows[] = ['number' => $number, 'state' => $state, 'branch' => $order?->assignedBranch?->name];
            if ($state === 'ready') {
                $entries[] = ['id' => $order->getKey(), 'branch' => $order->id_branches];
            }
        }
        $token = $entries ? Crypt::encryptString(json_encode(['actor' => $actor->getKey(), 'role' => $actor->role->value,
            'expires' => now()->addMinutes(15)->timestamp, 'batch' => (string) Str::uuid(), 'entries' => $entries], JSON_THROW_ON_ERROR)) : null;

        return compact('rows', 'counts', 'token');
    }

    public function commit(User $actor, string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            throw ValidationException::withMessages(['token' => 'Preview tidak valid. Periksa daftar No WO kembali.']);
        }
        if (! is_array($payload) || ($payload['actor'] ?? null) !== $actor->getKey() || ($payload['role'] ?? null) !== $actor->role->value
            || ($payload['expires'] ?? 0) < now()->timestamp || ! Str::isUuid($payload['batch'] ?? '')
            || ! is_array($payload['entries'] ?? null) || ! count($payload['entries']) || count($payload['entries']) > 50) {
            throw ValidationException::withMessages(['token' => 'Preview kedaluwarsa atau akun berubah. Periksa daftar No WO kembali.']);
        }

        return DB::transaction(function () use ($actor, $payload) {
            $actor = User::lockForUpdate()->findOrFail($actor->getKey());
            Gate::forUser($actor)->authorize('priority-orders');
            if ($actor->role->value !== $payload['role']) {
                $this->stale();
            }
            // The actor lock serializes repeated commits, including retries after a bookmark was removed.
            $previous = AuditLog::where('action', 'priority.bulk_selected')->where('id_users', $actor->getKey())
                ->where('metadata->batch', $payload['batch'])->first();
            if ($previous) {
                return $previous->metadata['result'];
            }
            $entries = collect($payload['entries'])->keyBy('id');
            $orders = WorkOrder::visibleTo($actor)->whereKey($entries->keys())
                ->select(['id_work_orders', 'id_branches', 'progress_category', 'status_order', 'task_bima', 'status_bima'])
                ->orderBy('id_work_orders')->lockForUpdate()->get();
            if ($orders->count() !== $entries->count()) {
                $this->stale();
            }
            foreach ($orders as $order) {
                if ($order->id_branches !== $entries[$order->getKey()]['branch'] || $order->isPsEligible()) {
                    $this->stale();
                }
            }
            $existing = WorkOrderPriority::whereIn('id_work_orders', $orders->modelKeys())
                ->when($actor->role->areaScope(), fn ($q) => $q->where('scope_key', 'area')->whereNull('id_branches'),
                    fn ($q) => $q->whereIn('scope_key', $orders->pluck('id_branches')->unique()->map(fn ($id) => 'branch:'.$id)))
                ->lockForUpdate()->get()
                ->keyBy(fn ($priority) => $priority->id_work_orders.':'.$priority->scope_key);
            $insert = [];
            foreach ($orders as $order) {
                $branch = $actor->role->areaScope() ? null : $order->id_branches;
                $key = $branch ? 'branch:'.$branch : 'area';
                if (! $existing->has($order->getKey().':'.$key)) {
                    $insert[] = ['id_work_orders' => $order->getKey(), 'scope_key' => $key, 'id_branches' => $branch,
                        'id_users' => $actor->getKey(), 'selection_token' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
                }
            }
            if ($insert) {
                WorkOrderPriority::insert($insert);
                $created = WorkOrderPriority::whereIn('selection_token', array_column($insert, 'selection_token'))->get();
                AuditLog::insert($created->map(fn ($priority) => ['id_users' => $actor->getKey(), 'action' => 'priority.selected',
                    'entity_type' => 'work_order_priority', 'id_entity' => $priority->getKey(), 'created_at' => now(),
                    'metadata' => json_encode(['id_work_orders' => $priority->id_work_orders, 'scope_key' => $priority->scope_key,
                        'id_branches' => $priority->id_branches, 'selected_by' => $actor->getKey(), 'batch' => $payload['batch']], JSON_THROW_ON_ERROR)])->all());
            }
            $result = ['added' => count($insert), 'skipped' => $orders->count() - count($insert)];
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'priority.bulk_selected', 'entity_type' => 'user',
                'id_entity' => $actor->getKey(), 'metadata' => ['batch' => $payload['batch'], 'result' => $result, 'order_ids' => $orders->modelKeys()]]);

            return $result;
        }, 3);
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['token' => 'Data atau akses order berubah setelah preview. Belum ada bookmark disimpan. Periksa daftar No WO kembali.']);
    }
}
