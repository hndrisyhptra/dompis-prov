<?php

namespace Tests\Unit;

use App\Services\WorkOrderDerivedFields;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WorkOrderDerivedFieldsTest extends TestCase
{
    public function test_age_boundaries_calendar_timezone_missing_and_future_dates_match_the_excel_rule(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 17:01:00', 'UTC'));
        try {
            foreach ([0 => '0-7 HARI', 7 => '0-7 HARI', 8 => '7-14 HARI', 14 => '7-14 HARI', 15 => '14-28 HARI', 28 => '14-28 HARI', 29 => '> 28 HARI', -1 => '0-7 HARI'] as $age => $category) {
                $date = CarbonImmutable::parse('2026-10-21', 'Asia/Jakarta')->subDays($age)->toDateString();
                $values = WorkOrderDerivedFields::dates($date);
                $this->assertSame($age, $values['umur_hari']);
                $this->assertSame($category, $values['kategori_umur']);
            }
            $this->assertSame(['bulan' => null, 'tahun' => null, 'umur_hari' => null, 'kategori_umur' => null], WorkOrderDerivedFields::dates(null));
            $this->assertSame('02', WorkOrderDerivedFields::dates('2024-02-29')['bulan']);
            $this->assertSame('2024', WorkOrderDerivedFields::dates('2024-02-29')['tahun']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_status_combination_handles_missing_components_without_losing_task_hyphens(): void
    {
        $this->assertSame('Approval E2E Testing Manual-COMPLETE', WorkOrderDerivedFields::status('Approval E2E Testing Manual', 'COMPLETE'));
        $this->assertSame('Task-E2E-OPEN', WorkOrderDerivedFields::status(' Task-E2E ', ' OPEN '));
        $this->assertSame('OPEN', WorkOrderDerivedFields::status(null, 'OPEN'));
        $this->assertSame('Task', WorkOrderDerivedFields::status('Task', null));
        $this->assertNull(WorkOrderDerivedFields::status('', null));
    }
}
