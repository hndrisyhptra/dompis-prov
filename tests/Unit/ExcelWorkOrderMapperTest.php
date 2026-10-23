<?php

namespace Tests\Unit;

use App\Services\ExcelWorkOrderMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ExcelWorkOrderMapperTest extends TestCase
{
    public function test_owner_group_is_optional_and_validates_length_and_formula_cells(): void
    {
        $mapper = new ExcelWorkOrderMapper;
        $this->assertSame(['no_tq_sc_id' => '1', 'owner_grup' => '2'], $mapper->suggest(['NO TQ/SC/ID', 'Owner Group']));
        $cells = [['type' => 's', 'value' => 'WO-OWNER', 'formula' => false], ['type' => 's', 'value' => 'GROUP UJI', 'formula' => false]];
        $this->assertArrayNotHasKey('owner_grup', $mapper->map($cells, ['no_tq_sc_id' => 1]));
        foreach ([['value' => str_repeat('X', 256), 'formula' => false], ['value' => 'GROUP UJI', 'formula' => true]] as $invalid) {
            $cells[1] = ['type' => 's'] + $invalid;
            try {
                $mapper->map($cells, ['no_tq_sc_id' => 1, 'owner_grup' => 2]);
                $this->fail('Invalid Owner Group accepted');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('OWNER GROUP', $exception->getMessage());
            }
        }
    }

    public function test_sid_and_serial_numbers_preserve_text_zeros_and_reject_numeric_identity_cells(): void
    {
        $mapper = new ExcelWorkOrderMapper;
        $this->assertSame('0', $mapper->map([['type' => 's', 'value' => '0', 'formula' => false]], ['no_tq_sc_id' => 1])['no_tq_sc_id']);
        foreach (['sid', 'sn'] as $field) {
            $cells = [['type' => 'inlineStr', 'value' => '000WO', 'formula' => false], ['type' => 's', 'value' => '000123', 'formula' => false]];
            $this->assertSame('000123', $mapper->map($cells, ['no_tq_sc_id' => 1, $field => 2])[$field]);
            $cells[1] = ['type' => 'n', 'value' => '123', 'formula' => false];
            try {
                $mapper->map($cells, ['no_tq_sc_id' => 1, $field => 2]);
                $this->fail('Numeric identity cell was accepted');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('harus teks asli', $exception->getMessage());
            }
        }
    }
}
