<?php

namespace Tests\Unit;

use App\Services\Exports\XlsxTableWriter;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class XlsxTableWriterTest extends TestCase
{
    public function test_large_generator_exports_all_rows_as_valid_xml_with_typed_dates_counts_text_and_actual_blank_cells(): void
    {
        $rows = (function () {
            for ($i = 1; $i <= 1001; $i++) {
                yield [str_pad((string) $i, 8, '0', STR_PAD_LEFT), $i, '2026-10-26', null, "=1+1 & <TEST>\x01"];
            }
        })();
        $path = (new XlsxTableWriter)->create(['title' => 'Data uji', 'columns' => [['label' => 'No WO'], ['label' => 'Jumlah', 'type' => 'number'], ['label' => 'Tanggal', 'type' => 'date'], ['label' => 'Belum berjalan', 'type' => 'number'], ['label' => 'Teks sumber']], 'rows' => $rows],
            ['scope' => 'Branch', 'filters' => 'Jenis pekerjaan: Semua jenis', 'at' => '26 Oct 2026 08:00:00']);
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $dom = new DOMDocument;
            $this->assertTrue($dom->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET));
            $zip->close();
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame(1006, $xpath->query('//s:sheetData/s:row')->length); // 4 context rows + header + 1001 data rows.
            $this->assertSame('1001', $xpath->evaluate('string(//s:c[@r="B1007"]/s:v)'));
            $this->assertSame('00000001', $xpath->evaluate('string(//s:c[@r="A7"]/s:is/s:t)'));
            $this->assertSame('inlineStr', $xpath->evaluate('string(//s:c[@r="A7"]/@t)'));
            $this->assertSame('46321', $xpath->evaluate('string(//s:c[@r="C7"]/s:v)'));
            $this->assertSame(0, $xpath->query('//s:c[@r="D7"]/*')->length);
            $this->assertSame('=1+1 & <TEST>', $xpath->evaluate('string(//s:c[@r="E7"]/s:is/s:t)'));
            $this->assertSame(0, $xpath->query('//s:f')->length);
        } finally {
            unlink($path);
        }
    }
}
