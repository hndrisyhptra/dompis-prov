<?php

namespace App\Support;

use App\Models\User;

class ScopedOrderListPermissions
{
    /** For rendering rows already returned by WorkOrder::visibleTo. Endpoints still enforce policies. */
    public static function for(User $actor): array
    {
        return ['update' => $actor->active && $actor->role->progress(), 'prioritize' => $actor->active && $actor->role->priorityOrders()];
    }
}
