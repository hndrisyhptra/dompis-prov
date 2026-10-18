<?php

namespace Tests\Fakes;

use App\Contracts\GoogleSheetGateway;
use App\Services\Sheets\SheetException;

class SheetGateway implements GoogleSheetGateway
{
    public array $monitoring = [];

    public bool $fail = false;

    public int $reads = 0;

    public function __construct(public array $rows = []) {}

    public function readBima(): array
    {
        $this->reads++;
        if ($this->fail) {
            throw new SheetException('Google simulasi tidak tersedia.');
        }

        return $this->rows;
    }

    public function publishMonitoring(array $rows): void
    {
        if ($this->fail) {
            throw new SheetException('Google simulasi tidak tersedia.');
        }
        $this->monitoring = $rows;
    }
}
