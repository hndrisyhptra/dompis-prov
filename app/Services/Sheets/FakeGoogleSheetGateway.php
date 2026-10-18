<?php

namespace App\Services\Sheets;

use App\Contracts\GoogleSheetGateway;

/** Explicit local simulation. Never bound in production. */
class FakeGoogleSheetGateway implements GoogleSheetGateway
{
    public function readBima(): array
    {
        $path = config('olo.sheets.fake_path');
        if (! is_file($path)) {
            throw new SheetException('Fixture lokal belum dibuat. Jalankan php artisan olo:demo-sheet.');
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    public function publishMonitoring(array $rows): void
    {
        file_put_contents(dirname(config('olo.sheets.fake_path')).'/monitoring-demo.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
