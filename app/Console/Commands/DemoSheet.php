<?php

namespace App\Console\Commands;

use App\Services\BimaRowMapper;
use Illuminate\Console\Command;

class DemoSheet extends Command
{
    protected $signature = 'olo:demo-sheet';

    protected $description = 'Create an explicitly synthetic local BIMA_RAW fixture, without reading customer templates';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Fixture demo dilarang di production.');

            return self::FAILURE;
        }
        $path = config('olo.sheets.fake_path');
        if (is_file($path)) {
            $this->error('Fixture sudah ada; tidak ditimpa.');

            return self::FAILURE;
        }
        $rows = [BimaRowMapper::HEADERS];
        foreach ([1, 2, 3] as $index) {
            $row = array_fill(0, 32, '');
            foreach ([0 => (string) $index, 1 => '2026-10-17', 4 => 'JAWA–BALI', 5 => 'DEMO BRANCH', 6 => 'D01', 7 => 'DEMO-000'.$index,
                8 => 'Pelanggan simulasi '.$index, 9 => 'Alamat simulasi', 10 => 'METRO', 11 => 'NEW INSTALL', 13 => 'OPEN', 16 => 'SID-DEMO-000'.$index, 26 => 'OPEN'] as $column => $value) {
                $row[$column] = $value;
            }
            $rows[] = $row;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $this->info('Fixture simulasi dibuat. Gunakan SHEETS_DRIVER=fake; buat mapping DEMO BRANCH pada web.');

        return self::SUCCESS;
    }
}
