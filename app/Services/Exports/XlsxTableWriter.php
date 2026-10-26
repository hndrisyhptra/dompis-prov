<?php

namespace App\Services\Exports;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;
use XMLWriter;
use ZipArchive;

class XlsxTableWriter
{
    public function create(array $document, array $context): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dompis-export-');
        $sheetPath = tempnam(sys_get_temp_dir(), 'dompis-sheet-');
        if ($path === false || $sheetPath === false) {
            throw new RuntimeException('Penyimpanan sementara untuk Excel belum tersedia.');
        }
        try {
            $this->sheet($sheetPath, $document, $context);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('File Excel belum dapat dibuat.');
            }
            foreach ($this->parts($document['title']) as $name => $xml) {
                $zip->addFromString($name, $xml);
            }
            $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
            if (! $zip->close()) {
                throw new RuntimeException('File Excel belum selesai dibuat.');
            }

            return $path;
        } catch (Throwable $error) {
            @unlink($path);
            throw $error;
        } finally {
            @unlink($sheetPath);
        }
    }

    private function sheet(string $path, array $document, array $context): void
    {
        $xml = new XMLWriter;
        if (! $xml->openUri($path)) {
            throw new RuntimeException('Lembar Excel belum dapat dibuat.');
        }
        $columns = $document['columns'];
        $last = $this->column(count($columns));
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->writeAttribute('showGridLines', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('xSplit', '1');
        $xml->writeAttribute('ySplit', '6');
        $xml->writeAttribute('topLeftCell', 'B7');
        $xml->writeAttribute('activePane', 'bottomRight');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('cols');
        foreach ($columns as $i => $column) {
            $xml->startElement('col');
            foreach (['min' => $i + 1, 'max' => $i + 1, 'width' => $column['width'] ?? 18, 'customWidth' => 1] as $key => $value) {
                $xml->writeAttribute($key, (string) $value);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('sheetData');
        foreach ([1 => [$document['title'], 4, 30], 2 => ['Dompis Prov WIBS Area Jawa Bali · '.$context['scope'].' · '.$context['at'].' WIB', 5, 32],
            3 => [$context['filters'], 5, 42], 4 => [$document['note'] ?? '', 5, 42]] as $number => [$text, $style, $height]) {
            $this->row($xml, $number, [$text], [['type' => 'text']], $style, $height);
        }
        $this->row($xml, 6, array_column($columns, 'label'), $columns, 6, 34);
        $number = 6;
        $lastData = 6;
        foreach ($document['rows'] as $row) {
            $total = isset($row['values']) && ($row['total'] ?? false);
            $values = $row['values'] ?? $row;
            $number++;
            $this->row($xml, $number, $values, $columns, $total ? 7 : null, 26, $number % 2 === 0);
            if (! $total) {
                $lastData = $number;
            }
            if ($number % 250 === 0) {
                $xml->flush();
            }
        }
        if ($number === 6) {
            $this->row($xml, 7, ['Tidak ada data sesuai filter.'], $columns, null, 26);
        }
        $xml->endElement();
        $xml->startElement('autoFilter');
        $xml->writeAttribute('ref', 'A6:'.$last.$lastData);
        $xml->endElement();
        $xml->startElement('mergeCells');
        $xml->writeAttribute('count', '4');
        for ($i = 1; $i <= 4; $i++) {
            $xml->startElement('mergeCell');
            $xml->writeAttribute('ref', 'A'.$i.':'.$last.$i);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('pageMargins');
        foreach (['left' => '0.3', 'right' => '0.3', 'top' => '0.5', 'bottom' => '0.5', 'header' => '0.2', 'footer' => '0.2'] as $key => $value) {
            $xml->writeAttribute($key, $value);
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    private function row(XMLWriter $xml, int $number, array $values, array $columns, ?int $style, int $height, bool $band = false): void
    {
        if ($style === null || in_array($style, [6, 7], true)) {
            foreach ($columns as $i => $column) {
                $value = $values[$i] ?? '';
                if (is_string($value)) {
                    $lines = array_sum(array_map(fn ($line) => max(1, (int) ceil(mb_strlen($line) / max(8, ($column['width'] ?? 18) * 1.1))), explode("\n", $value)));
                    $height = max($height, min(160, $lines * 15 + 8));
                }
            }
        }
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $number);
        $xml->writeAttribute('ht', (string) $height);
        $xml->writeAttribute('customHeight', '1');
        foreach ($columns as $i => $column) {
            $value = $values[$i] ?? null;
            $type = $style !== null ? 'text' : ($column['type'] ?? 'text');
            $cellStyle = $style ?? (['text' => 0, 'number' => 1, 'date' => 2, 'datetime' => 3][$type] + ($band ? 9 : 0));
            if ($style === 7 && ($column['type'] ?? '') === 'number') {
                $type = 'number';
                $cellStyle = 8;
            }
            $xml->startElement('c');
            $xml->writeAttribute('r', $this->column($i + 1).$number);
            $xml->writeAttribute('s', (string) $cellStyle);
            if ($value === null) {
                $xml->endElement();

                continue;
            }
            if ($type === 'number' || $type === 'date' || $type === 'datetime') {
                if ($type === 'number') {
                    $numeric = (int) $value;
                } else {
                    $text = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
                    $numeric = (new DateTimeImmutable($text, new DateTimeZone('UTC')))->getTimestamp() / 86400 + 25569;
                }
                $xml->writeElement('v', (string) $numeric);
            } else {
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $xml->text($this->clean((string) ($value ?? '')));
                $xml->endElement();
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function column(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $name = chr(65 + ($index - 1) % 26).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    private function clean(string $text): string
    {
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_substr($text, 0, 32767)) ?? '';
    }

    private function parts(string $title): array
    {
        $sheet = htmlspecialchars(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', '', $title), 0, 31), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="'.$sheet.'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $this->styles(),
        ];
    }

    private function styles(): string
    {
        $base = '<xf numFmtId="%d" fontId="%d" fillId="%d" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="%s" vertical="center" wrapText="1"/></xf>';
        $formats = '';
        foreach ([[0, 0, 0, 'left'], [3, 0, 0, 'right'], [165, 0, 0, 'left'], [164, 0, 0, 'left'], [0, 1, 0, 'left'], [0, 2, 0, 'left'], [0, 3, 2, 'center'], [0, 4, 3, 'left'], [3, 4, 3, 'right'],
            [0, 0, 4, 'left'], [3, 0, 4, 'right'], [165, 0, 4, 'left'], [164, 0, 4, 'left']] as $format) {
            $formats .= sprintf($base, ...$format);
        }

        return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="dd mmm yyyy hh:mm:ss"/><numFmt numFmtId="165" formatCode="dd mmm yyyy"/></numFmts><fonts count="5"><font><sz val="11"/><name val="Arial"/><color rgb="FF17212E"/></font><font><b/><sz val="16"/><name val="Arial"/><color rgb="FF315B8C"/></font><font><sz val="10"/><name val="Arial"/><color rgb="FF62758A"/></font><font><b/><sz val="11"/><name val="Arial"/><color rgb="FFFFFFFF"/></font><font><b/><sz val="11"/><name val="Arial"/><color rgb="FF315B8C"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF315B8C"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEBF1F7"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF7F9FC"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="13">'.$formats.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
