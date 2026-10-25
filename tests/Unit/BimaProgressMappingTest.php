<?php

namespace Tests\Unit;

use App\Enums\ProgressCategory;
use App\Support\BimaProgressMapping;
use App\Support\PsEligibility;
use PHPUnit\Framework\TestCase;

class BimaProgressMappingTest extends TestCase
{
    public function test_owner_lists_are_distinct_and_do_not_overlap_the_ps_rules(): void
    {
        $this->assertCount(22, BimaProgressMapping::SURVEY);
        $this->assertCount(37, BimaProgressMapping::INSTALASI);
        $statuses = [...BimaProgressMapping::SURVEY, ...BimaProgressMapping::INSTALASI];
        $this->assertCount(59, array_unique($statuses));
        $this->assertSame([], array_intersect($statuses, PsEligibility::STATUSES));
        $this->assertSame(ProgressCategory::Survey, BimaProgressMapping::category('Survey-Ondesk Manual-CANCLWORK'));
        $this->assertSame(ProgressCategory::Instalasi, BimaProgressMapping::category('Pickup NTE from SCM Manual-CANCLWORK'));
        $this->assertSame(ProgressCategory::Instalasi, BimaProgressMapping::category('Activate Cross Connect-CANCLWORK'));
        $this->assertSame(ProgressCategory::Survey, BimaProgressMapping::category('  Site-Survey Manual-COMPLETE  '));
    }

    public function test_full_case_sensitive_status_is_required_without_guessing_from_suffixes_or_substrings(): void
    {
        foreach ([null, '', 'COMPLETE', 'STARTWORK', 'CANCLWORK', 'Survey', 'Site-Survey Manual',
            'Site-Survey Manual-CANCLWORK', 'Activate Service-COMPLETE', 'Activate service-STARTWORK',
            'Activate Service-STARTWORK tambahan', 'X Activate Service-STARTWORK', 'Review Order TSQ-STARTWORK'] as $status) {
            $this->assertNull(BimaProgressMapping::category($status), (string) $status);
        }
    }
}
