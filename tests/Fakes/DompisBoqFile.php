<?php

namespace Tests\Fakes;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class DompisBoqFile
{
    /** Anonymous volume calculations transcribed from the supplied example; no project/customer data. */
    public const EXAMPLE_VOLUMES = [53 => '=ROUND((20)+((20)*2%),0)+((8*1)+10)', 54 => '=G53',
        419 => '=ROUND((G53+G55)/200,0)', 420 => '=G419', 505 => '=G504', 507 => '=G506',
        535 => '6', 555 => '36', 556 => '=G555', 563 => '2', 564 => '=G563', 595 => '=G594', 597 => '=G596',
        611 => '=ROUND((G53+G55)/200,0)', 612 => '=G611', 613 => '=ROUND((G53+G55)/400,0)', 614 => '=G613',
        615 => '=ROUND((G53+G55)/40,0)-G611', 616 => '=G615', 629 => '=ROUND(0/47,0)', 630 => '=G629', 701 => '1', 702 => '=G701'];

    public static function make(array $volumes = self::EXAMPLE_VOLUMES, string $cache = '999999'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'olo-boq-volumes-');
        copy(resource_path('templates/boq/paket-5.xlsx'), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $doc = new DOMDocument;
        $doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($volumes as $row => $value) {
            $parent = $xpath->query('//s:row[@r="'.$row.'"]')->item(0);
            $old = $xpath->query('s:c[@r="G'.$row.'"]', $parent)->item(0);
            if ($old) {
                $parent->removeChild($old);
            }
            $cell = $doc->createElementNS($parent->namespaceURI, 'c');
            $cell->setAttribute('r', 'G'.$row);
            $cell->setAttribute('t', 'n');
            if (str_starts_with((string) $value, '=')) {
                $cell->appendChild($doc->createElementNS($parent->namespaceURI, 'f', substr($value, 1)));
                $value = $cache;
            }
            $cell->appendChild($doc->createElementNS($parent->namespaceURI, 'v', (string) $value));
            $parent->appendChild($cell);
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $doc->saveXML());
        $zip->close();

        return new UploadedFile($path, 'anonymous-boq.xlsx', null, null, true);
    }
}
