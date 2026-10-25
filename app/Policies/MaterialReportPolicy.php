<?php

namespace App\Policies;

use App\Models\MaterialReport;
use App\Models\User;

class MaterialReportPolicy
{
    public function view(User $user, MaterialReport $report): bool
    {
        return MaterialReport::visibleTo($user)->whereKey($report->getKey())->exists();
    }
}
