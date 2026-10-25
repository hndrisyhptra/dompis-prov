<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function prioritize(User $user, WorkOrder $workOrder): bool
    {
        return $user->active && $user->role->priorityOrders() && $this->view($user, $workOrder);
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return WorkOrder::visibleTo($user)->whereKey($workOrder->getKey())->exists();
    }

    public function editDevices(User $user, WorkOrder $workOrder): bool
    {
        return in_array($user->role, [Role::SuperAdmin, Role::AreaAdmin, Role::AreaHd, Role::BranchAdmin, Role::BranchHd], true)
            && $this->view($user, $workOrder);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->role->progress() && $this->view($user, $workOrder);
    }
}
