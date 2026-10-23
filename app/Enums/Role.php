<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case AreaCoordinator = 'area_coordinator';
    case AreaAdmin = 'area_admin';
    case AreaHd = 'area_hd';
    case BranchAdmin = 'branch_admin';
    case BranchHd = 'branch_hd';
    case TeamLeader = 'team_leader';
    case MaterialAdmin = 'material_admin';
    case Bot = 'bot';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Superadmin',
            self::AreaCoordinator => 'Koordinator Area',
            self::AreaAdmin => 'Admin Area',
            self::AreaHd => 'HD Area',
            self::BranchAdmin => 'Admin Branch',
            self::BranchHd => 'HD Branch',
            self::TeamLeader => 'Team Leader',
            self::MaterialAdmin => 'Admin Material',
            self::Bot => 'Bot',
        };
    }

    public function areaScope(): bool
    {
        return in_array($this, [self::SuperAdmin, self::AreaCoordinator, self::AreaAdmin, self::AreaHd], true);
    }

    public function sync(): bool
    {
        return in_array($this, [self::SuperAdmin, self::AreaAdmin, self::AreaHd], true);
    }

    public function managesMasterData(): bool
    {
        return in_array($this, [self::SuperAdmin, self::AreaCoordinator, self::AreaAdmin, self::AreaHd], true);
    }

    public function progress(): bool
    {
        return $this->areaScope() || in_array($this, [self::BranchAdmin, self::BranchHd, self::TeamLeader], true);
    }
}
