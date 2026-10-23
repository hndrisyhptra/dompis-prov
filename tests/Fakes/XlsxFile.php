<?php

namespace Tests\Fakes;

use App\Services\WorkOrderXlsxReader;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class XlsxFile
{
    public static function make(array $headers, array $rows, string $name = 'OLO AREA-3', int $headerRow = 1, bool $date1904 = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'olo-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $escape = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="'.($date1904 ? '1' : '0').'"/><sheets><sheet name="'.$escape($name).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ([$headers, ...$rows] as $i => $values) {
            $r = $headerRow + $i;
            $xml .= '<row r="'.$r.'">';
            foreach ($values as $column => $value) {
                $cell = is_array($value) ? $value : ['value' => $value, 'type' => 'inlineStr'];
                $type = $cell['type'] ?? 'inlineStr';
                $xml .= '<c r="'.WorkOrderXlsxReader::column($column).$r.'" t="'.$type.'">';
                if (isset($cell['formula'])) {
                    $xml .= '<f>'.$escape($cell['formula']).'</f>';
                }
                $xml .= $type === 'inlineStr' ? '<is><t>'.$escape($cell['value']).'</t></is>' : '<v>'.$escape($cell['value']).'</v>';
                $xml .= '</c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->close();

        return new UploadedFile($path, 'orders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
