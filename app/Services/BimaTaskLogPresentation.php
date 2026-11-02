<?php

namespace App\Services;

use App\Models\BimaTaskLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BimaTaskLogPresentation
{
    public function read(BimaTaskLog $log): array
    {
        try {
            $changes = $log->field_changes;
        } catch (DecryptException) {
            return ['readable' => false, 'changes' => [], 'reason' => 'decrypt_failed'];
        }
        if ($changes === null) {
            return $log->getRawOriginal('field_changes') === null
                ? ['readable' => true, 'changes' => [], 'reason' => null]
                : ['readable' => false, 'changes' => [], 'reason' => 'invalid_format'];
        }
        if (! is_array($changes)) {
            return ['readable' => false, 'changes' => [], 'reason' => 'invalid_format'];
        }
        foreach ($changes as $change) {
            if (! is_array($change) || ! isset($change['label']) || ! is_string($change['label'])
                || ! array_key_exists('before', $change) || ! array_key_exists('after', $change)
                || ($change['before'] !== null && ! is_scalar($change['before'])) || ($change['after'] !== null && ! is_scalar($change['after']))) {
                return ['readable' => false, 'changes' => [], 'reason' => 'invalid_format'];
            }
        }

        return ['readable' => true, 'changes' => $changes, 'reason' => null];
    }

    public function forPage(Collection $logs): Collection
    {
        $details = $logs->mapWithKeys(fn (BimaTaskLog $log) => [$log->getKey() => $this->read($log)]);
        $unreadable = $details->filter(fn ($entry) => ! $entry['readable'])->keys()->all();
        if ($unreadable) {
            // Report identifiers only; never log keys, ciphertext, field values or exception payloads.
            Log::warning('bima_task_log.unreadable', ['id_work_orders' => (int) $logs->first()->id_work_orders, 'id_bima_task_logs' => $unreadable]);
        }

        return $details;
    }
}
