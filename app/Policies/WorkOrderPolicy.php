<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return WorkOrder::visibleTo($user)->whereKey($workOrder->getKey())->exists();
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->role->progress() && $this->view($user, $workOrder);
    }
}
