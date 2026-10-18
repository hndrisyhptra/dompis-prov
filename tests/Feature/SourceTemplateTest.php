<?php

namespace Tests\Feature;

use App\Models\BranchMapping;
use App\Models\WorkOrder;
use App\Services\BimaRowMapper;
use ZipArchive;

class SourceTemplateTest extends OloTestCase
{
    /** Read-only OOXML extraction. No template data is persisted outside the test's SQLite memory. */
    private function readSheet(string $file, string $name): array
    {
        $path = storage_path('template/'.$file);
        if (! is_file($path)) {
            $this->markTestSkipped('User reference workbook is not distributed with source code.');
        }
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = fn ($entry) => simplexml_load_string($zip->getFromName($entry), options: LIBXML_NONET);
        $shared = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($xml('xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') as $item) {
                $shared[] = implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]')));
            }
        }
        $sheetId = null;
        foreach ($xml('xl/workbook.xml')->xpath('//*[local-name()="sheet"]') as $sheet) {
            if ((string) $sheet['name'] === $name) {
                $sheetId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            }
        }
        $target = null;
        foreach ($xml('xl/_rels/workbook.xml.rels')->xpath('//*[local-name()="Relationship"]') as $relationship) {
            if ((string) $relationship['Id'] === $sheetId) {
                $target = (string) $relationship['Target'];
            }
        }
        $this->assertNotNull($target);
        $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        $result = [];
        foreach ($xml($sheetPath)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $index = (int) $row['r'] - 1;
            $values = array_fill(0, 32, '');
            foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                preg_match('/^([A-Z]+)/', (string) $cell['r'], $match);
                $column = 0;
                foreach (str_split($match[1]) as $letter) {
                    $column = $column * 26 + ord($letter) - 64;
                }
                $value = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                if ((string) $cell['t'] === 's') {
                    $value = $shared[(int) $value];
                } elseif ((string) $cell['t'] === 'inlineStr') {
                    $value = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]')));
                }
                $values[$column - 1] = $value;
            }
            $result[$index] = $values;
        }
        $zip->close();

        // Fill blank OOXML rows so source row numbers remain exact.
        return array_replace(array_fill(0, max(array_keys($result)) + 1, []), $result);
    }

    public function test_provided_bima_imports_all_3298_ids_once_and_templates_are_untouched(): void
    {
        $path = storage_path('template/EXPORT-BIMA.xlsx');
        $rows = $this->readSheet('EXPORT-BIMA.xlsx', 'OLO AREA-3');
        $before = hash_file('sha256', $path);
        (new BimaRowMapper)->validateHeader(array_shift($rows));
        foreach ($rows as $row) {
            if (trim($row[7] ?? '') !== '') {
                // Test-only mapping to an isolated test branch; no production branch inference.
                BranchMapping::firstOrCreate(['bima_branch' => mb_strtoupper(trim($row[5])), 'sto_code' => ''], ['id_branches' => $this->branch->getKey()]);
            }
        }
        $first = $this->pull($rows);
        $this->assertSame(3298, $first->stats['inserted']);
        $second = $this->pull($rows);
        $this->assertSame(3298, $second->stats['unchanged']);
        $this->assertSame(0, $second->stats['inserted']);
        $this->assertSame(3298, WorkOrder::count());
        $this->assertSame($before, hash_file('sha256', $path));
    }

    public function test_lhpp_source_contract_is_exactly_32_columns_without_editing_sample(): void
    {
        $rows = $this->readSheet('LHPP OLO.xlsx', 'Sheet1');
        $this->assertSame(['SID', 'Tipe', 'Nama_Pelanggan', 'Alamat_Instalasi', 'No_Voice', 'No_SID', 'SN_ONT', 'MEREK_ONT', 'SN_STB', 'MEREK_STB', 'SN_AP', 'Merek_AP', 'ODP', 'Qr_Code_ODP', 'Qr_Code_DC', 'PORT ODP', 'Valins ID', 'Nik Teknisi', 'Teknisi', 'Nik Teknisi (2)', 'Nama Teknisi (2)', 'CP Pelanggan', 'JENIS TRANSAKSI', 'Total DC', 'S-CLAMP', 'C-HOOK', 'OTP', 'PREKSO', 'TRAY', 'M-DC-SOC', 'LAT PELANGGAN', 'LONG PELANGGAN'], $rows[0]);
        $this->assertSame('2cf8b271038771c1b22879521eec3886d71054e9cca48148686acb5aa0dd33fe', hash_file('sha256', storage_path('template/LHPP OLO.xlsx')));
    }
}
