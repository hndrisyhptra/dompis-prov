<?php

namespace App\Support;

class WorkOrderActions
{
    public const MODES = [
        'progress' => ['title' => 'Update Progress', 'categories' => ['OGP BIMA' => 'OGP BIMA', 'RESUBMIT AO' => 'Resubmit AO', 'SURVEY' => 'SURVEY', 'JT' => 'JT', 'INSTALASI' => 'INSTALASI']],
        'issue' => ['title' => 'Kendala', 'categories' => ['CANCEL' => 'Cancel Order', 'HOLD' => 'HOLD', 'BUTUH QE' => 'Butuh QE', 'PERIZINAN' => 'Perizinan']],
        'close' => ['title' => 'Close', 'categories' => ['CLOSE' => 'CLOSE']],
    ];

    public static function definition(string $mode): array
    {
        abort_unless(isset(self::MODES[$mode]), 404);

        return self::MODES[$mode];
    }
}
