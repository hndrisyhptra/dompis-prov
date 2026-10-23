<?php

namespace Tests\Unit;

use App\Services\BimaRowMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BimaRowMapperTest extends TestCase
{
    public function test_header_accepts_only_documented_whitespace_tolerance(): void
    {
        $mapper = new BimaRowMapper;
        $headers = BimaRowMapper::HEADERS;
        $headers[17] .= ' ';
        $mapper->validateHeader($headers);
        $this->expectException(InvalidArgumentException::class);
        [$headers[7], $headers[16]] = [$headers[16], $headers[7]];
        $mapper->validateHeader($headers);
    }

    public function test_date_parses_excel_iso_and_dmy_without_guessing_invalid_dates(): void
    {
        $mapper = new BimaRowMapper;
        $this->assertSame('2026-10-17', $mapper->date('2026-10-17'));
        $this->assertSame('2026-10-17', $mapper->date('17/10/2026'));
        $this->assertSame('2024-01-01', $mapper->date(45292));
        $this->assertNull($mapper->date(''));
        $this->expectException(InvalidArgumentException::class);
        $mapper->date('31/02/2026');
    }

    public function test_derived_formula_columns_do_not_affect_source_hash(): void
    {
        $row = array_fill(0, 32, '');
        $row[7] = '000001';
        $row[16] = 'SID-0002';
        $mapper = new BimaRowMapper;
        $first = $mapper->map($row);
        $row[28] = '=VLOOKUP(G2,A2:B3,2,FALSE)';
        $row[30] = '=TODAY()-B2';
        $this->assertSame($first, $mapper->map($row));
        $this->assertSame('000001', $first['no_tq_sc_id']);
        $this->assertSame('SID-0002', $first['sid']);
    }

    public function test_formula_in_source_attributes_is_rejected(): void
    {
        $row = array_fill(0, 32, '');
        $row[7] = '=A1';
        $this->expectException(InvalidArgumentException::class);
        (new BimaRowMapper)->map($row);
    }

    public function test_numeric_id_is_rejected_instead_of_losing_leading_zeros(): void
    {
        $row = array_fill(0, 32, '');
        $row[7] = 123;
        $this->expectException(InvalidArgumentException::class);
        (new BimaRowMapper)->map($row);
    }
}
