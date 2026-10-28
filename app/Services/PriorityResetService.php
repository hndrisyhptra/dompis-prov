<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PriorityResetService
{
    public function preview(User $actor): array
    {
        Gate::forUser($actor)->authorize('priority-orders');
        $snapshot = $this->digest($this->entries($this->owned($actor))->toBase()->cursor());
        $token = $snapshot['count'] ? Crypt::encryptString(json_encode($snapshot + [
            'actor' => $actor->getKey(), 'role' => $actor->role->value, 'expires' => now()->addMinutes(15)->timestamp, 'batch' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR)) : null;

        return ['count' => $snapshot['count'], 'token' => $token];
    }

    public function reset(User $actor, string $token): int
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            $this->stale();
        }
        if (! is_array($payload) || ($payload['actor'] ?? null) !== $actor->getKey() || ($payload['role'] ?? null) !== $actor->role->value
            || ($payload['expires'] ?? 0) < now()->timestamp || ! Str::isUuid($payload['batch'] ?? '')
            || ! is_int($payload['count'] ?? null) || $payload['count'] < 1 || ! preg_match('/^[a-f0-9]{64}$/', $payload['digest'] ?? '')) {
            $this->stale();
        }

        return DB::transaction(function () use ($actor, $payload) {
            $actor = User::lockForUpdate()->findOrFail($actor->getKey());
            Gate::forUser($actor)->authorize('priority-orders');
            if ($actor->role->value !== $payload['role']) {
                $this->stale();
            }
            $previous = AuditLog::where('action', 'priority.reset')->where('id_users', $actor->getKey())->where('metadata->batch', $payload['batch'])->first();
            if ($previous) {
                return (int) $previous->metadata['removed'];
            }
            $owned = $this->owned($actor);
            // Follow selection/BIMA lock order: actor -> WO in ID order -> bookmark.
            $orders = WorkOrder::visibleTo($actor)->whereIn('id_work_orders', (clone $owned)->select('work_order_priorities.id_work_orders'))
                ->orderBy('id_work_orders')->lockForUpdate()->pluck('id_work_orders');
            $entries = $this->entries((clone $owned)->whereIn('id_work_orders', $orders))->toBase()->lockForUpdate()->get();
            $current = $this->digest($entries);
            if ($current['count'] !== $payload['count'] || ! hash_equals($payload['digest'], $current['digest'])) {
                $this->stale();
            }
            foreach ($entries->chunk(100) as $chunk) {
                AuditLog::insert($chunk->map(fn ($priority) => [
                    'id_users' => $actor->getKey(), 'action' => 'priority.removed', 'entity_type' => 'work_order_priority',
                    'id_entity' => $priority->id_work_order_priorities, 'created_at' => now(),
                    'metadata' => json_encode(['id_work_orders' => $priority->id_work_orders, 'scope_key' => $priority->scope_key,
                        'id_branches' => $priority->id_branches, 'selected_by' => $priority->id_users, 'origin' => 'reset', 'batch' => $payload['batch']], JSON_THROW_ON_ERROR),
                ])->all());
                // Delete only the locked snapshot IDs; a concurrent new selection is never swept up.
                WorkOrderPriority::whereKey($chunk->pluck('id_work_order_priorities')->all())->delete();
            }
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'priority.reset', 'entity_type' => 'user', 'id_entity' => $actor->getKey(),
                'metadata' => ['batch' => $payload['batch'], 'removed' => $current['count'], 'scope' => $actor->role->areaScope() ? 'area' : 'branch']]);

            return $current['count'];
        }, 3);
    }

    private function owned(User $actor): Builder
    {
        $query = WorkOrderPriority::visibleTo($actor);
        if ($actor->role->areaScope()) {
            return $query;
        }
        $key = DB::getDriverName() === 'mysql' ? "BINARY scope_key = BINARY CONCAT('branch:', id_branches)" : "scope_key = 'branch:' || id_branches";

        return $query->whereNotNull('id_branches')->whereRaw($key);
    }

    private function entries(Builder $query): Builder
    {
        return $query->select(['id_work_order_priorities', 'id_work_orders', 'id_branches', 'id_users', 'scope_key', 'selection_token'])->orderBy('id_work_order_priorities');
    }

    private function digest(iterable $entries): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        foreach ($entries as $priority) {
            hash_update($hash, json_encode((array) $priority, JSON_THROW_ON_ERROR)."\n");
            $count++;
        }

        return ['count' => $count, 'digest' => hash_final($hash)];
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['reset_token' => 'Daftar bookmark atau akses sudah berubah. Klik Perbarui lalu ulangi Reset Bookmark. Belum ada bookmark yang dilepas.']);
    }
}
