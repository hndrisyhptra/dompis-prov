<?php

namespace Tests\Unit;

use App\Services\ExcelWorkOrderMapper;
use App\Services\WorkOrderXlsxReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\XlsxFile;
use ZipArchive;

class WorkOrderXlsxReaderTest extends TestCase
{
    public function test_work_orders_is_selected_before_pivot_and_empty_formatted_rows_do_not_count_as_orders(): void
    {
        $file = XlsxFile::make(['ORDER-ID'], [['000123']], 'Work Orders');
        try {
            $zip = new ZipArchive;
            $zip->open($file->getRealPath());
            $book = $zip->getFromName('xl/workbook.xml');
            $zip->addFromString('xl/workbook.xml', str_replace('<sheets>', '<sheets><sheet name="Pivot" sheetId="2" r:id="rId2"/>', $book));
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>', $rels));
            $zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="3"><c r="A3" t="inlineStr"><is><t>Pivot summary</t></is></c></row></sheetData></worksheet>');
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('</sheetData>', '<row r="20121"><c r="A20121" s="1"/></row></sheetData>', $sheet));
            $zip->close();
            $data = (new WorkOrderXlsxReader)->read($file->getRealPath());
            $this->assertSame('Work Orders', $data['sheet']);
            $this->assertCount(1, $data['rows']);
            $this->assertSame('000123', $data['rows'][2][0]['value']);
        } finally {
            unlink($file->getRealPath());
        }
    }

    public function test_shared_rich_strings_sparse_columns_custom_header_and_date_system_are_preserved(): void
    {
        $file = XlsxFile::make(['NO TQ/SC/ID', 'STO', 'TGL ORDER'], [[['type' => 's', 'value' => '0'], 'TST', ['type' => 'n', 'value' => '0']]], 'EXPORT', 3, true);
        try {
            $zip = new ZipArchive;
            $zip->open($file->getRealPath());
            $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>000</t></r><r><t>123</t></r></si></sst>');
            $zip->close();
            $data = (new WorkOrderXlsxReader)->read($file->getRealPath(), 'EXPORT', 3);
            $this->assertTrue($data['date1904']);
            $this->assertSame('000123', $data['rows'][4][0]['value']);
            $mapped = (new ExcelWorkOrderMapper)->map($data['rows'][4], ['no_tq_sc_id' => 1, 'sto' => 2, 'tgl_order' => 3], true);
            $this->assertSame('1904-01-01', $mapped['tgl_order']);
            $this->assertSame('DX', WorkOrderXlsxReader::column(127));
        } finally {
            unlink($file->getRealPath());
        }
    }

    public function test_external_connections_entity_xml_and_malformed_zip_are_rejected(): void
    {
        foreach (['xl/connections.xml' => '<connections/>', 'xl/worksheets/sheet1.xml' => '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///etc/passwd">]><worksheet/>'] as $part => $content) {
            $file = XlsxFile::make(['ID'], [['0001']]);
            try {
                $zip = new ZipArchive;
                $zip->open($file->getRealPath());
                $zip->addFromString($part, $content);
                $zip->close();
                try {
                    (new WorkOrderXlsxReader)->read($file->getRealPath());
                    $this->fail('Unsafe workbook was accepted');
                } catch (InvalidArgumentException $exception) {
                    $this->assertNotEmpty($exception->getMessage());
                }
            } finally {
                unlink($file->getRealPath());
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'olo-invalid-');
        file_put_contents($path, 'not a workbook');
        try {
            $this->expectException(InvalidArgumentException::class);
            (new WorkOrderXlsxReader)->read($path);
        } finally {
            unlink($path);
        }
    }

    public function test_missing_sheet_empty_orders_and_formula_headers_are_rejected(): void
    {
        foreach ([['headers' => ['ID'], 'rows' => [], 'sheet' => null], ['headers' => ['ID'], 'rows' => [['1']], 'sheet' => 'MISSING'], ['headers' => [['type' => 'str', 'value' => 'ID', 'formula' => 'A1']], 'rows' => [['1']], 'sheet' => null]] as $case) {
            $file = XlsxFile::make($case['headers'], $case['rows']);
            try {
                try {
                    (new WorkOrderXlsxReader)->read($file->getRealPath(), $case['sheet']);
                    $this->fail('Invalid workbook was accepted');
                } catch (InvalidArgumentException $exception) {
                    $this->assertNotEmpty($exception->getMessage());
                }
            } finally {
                unlink($file->getRealPath());
            }
        }
    }

    public function test_limits_reject_too_many_rows_columns_and_oversized_cells(): void
    {
        foreach ([array_fill(0, 10001, ['ID']), [[128 => 'too many columns']], [[str_repeat('x', 8193)]]] as $rows) {
            $file = XlsxFile::make(['ID'], $rows);
            try {
                try {
                    (new WorkOrderXlsxReader)->read($file->getRealPath());
                    $this->fail('Workbook limit was ignored');
                } catch (InvalidArgumentException $exception) {
                    $this->assertNotEmpty($exception->getMessage());
                }
            } finally {
                unlink($file->getRealPath());
            }
        }
    }
}
