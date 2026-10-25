<?php

namespace Tests\Unit;

use App\Services\Boq\BoqVolumeFormula;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BoqVolumeFormulaTest extends TestCase
{
    private function calculator(array $values): BoqVolumeFormula
    {
        $rows = [];
        foreach ($values as $row => $value) {
            $rows[$row][6] = is_array($value) ? $value : ['value' => str_starts_with((string) $value, '=') ? '999999' : (string) $value,
                'formula' => str_starts_with((string) $value, '='), 'expression' => ltrim((string) $value, '='), 'type' => 'n'];
        }

        return new BoqVolumeFormula($rows);
    }

    public function test_example_rounding_percentages_references_blank_as_zero_and_unary_arithmetic(): void
    {
        $calculator = $this->calculator([53 => '=ROUND((20)+((20)*2%),0)+((8*1)+10)', 54 => '=$G$53', 615 => '=ROUND((G53+G55)/40,0)-G611',
            616 => '=G615', 9 => '=ROUND(1/3,3)', 10 => '=ROUND(-1.5,0)+3', 11 => '=ROUND(125,-1)', 12 => '=+(.5+0.25)*100%', 13 => '=ROUND(0.145,2)']);
        foreach ([53 => '38', 54 => '38', 615 => '1', 616 => '1', 9 => '0.333', 10 => '1', 11 => '130', 12 => '0.75', 13 => '0.15'] as $row => $expected) {
            $this->assertSame($expected, $calculator->quantity($row));
        }
    }

    public function test_unsafe_unsupported_cycles_precision_range_errors_and_division_zero_fail(): void
    {
        foreach (['=G9', '=SUM(G10:G12)', '=H10', '=G733', '=G8', '=Other!G10', '=[remote.xlsx]Sheet1!G10', '=WEBSERVICE("https://example.org")', '=1/0', '=1/3', '=-1', '=1000000000000', '=ROUND(1,99)', '=1+1 garbage', '=G10+'] as $formula) {
            try {
                $this->calculator([9 => $formula])->quantity(9);
                $this->fail('Invalid formula must not be executed');
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        foreach ([['value' => '1', 'formula' => true, 'expression' => 'G10', 'shared_formula' => true, 'type' => 'n'], ['value' => '#REF!', 'formula' => false, 'type' => 'e']] as $cell) {
            try {
                $this->calculator([9 => $cell])->quantity(9);
                $this->fail('Shared/error formulas must be explicit errors');
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
