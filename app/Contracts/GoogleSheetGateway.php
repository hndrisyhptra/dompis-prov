<?php

namespace App\Contracts;

interface GoogleSheetGateway
{
    public function readBima(): array;

    /** Replace only the managed monitoring range and verify the entire snapshot. */
    public function publishMonitoring(array $rows): void;
}
