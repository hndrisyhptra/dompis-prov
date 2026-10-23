<?php

namespace App\Services;

use App\Models\MappingOrder;

class WorkOrderMappingResolver
{
    public function resolve(?string $task, ?string $status): ?MappingOrder
    {
        $key = WorkOrderDerivedFields::status($task, $status);
        if ($key === null) {
            return null;
        }
        $keys = [$key];
        // The owner reference explicitly contains -COMPWORK and -STARTWORK for an empty task.
        if (trim($task ?? '') === '' && trim($status ?? '') !== '') {
            $keys[] = '-'.trim($status);
        }
        $rules = MappingOrder::where('active', true)->whereIn('status_order', $keys)->get()->keyBy('status_order');
        foreach ($keys as $candidate) {
            if ($rules->has($candidate)) {
                return $rules->get($candidate);
            }
        }

        return null;
    }
}
