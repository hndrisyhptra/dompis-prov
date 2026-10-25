<?php

namespace App\Services;

use App\Support\WorkOrderUpload;
use InvalidArgumentException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class WorkOrderXlsxReader
{
    public const MAX_ROWS = 10000;

    private const MAX_COLUMNS = 128;

    public static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    public function read(string $path, ?string $sheetName = null, int $headerRow = 1, bool $includeFormulas = false): array
    {
        if (! is_file($path) || filesize($path) > WorkOrderUpload::MAX_BYTES || $headerRow < 1 || $headerRow > 50) {
            throw new InvalidArgumentException('Gunakan file XLSX maksimal 10 MB dan baris header 1–50.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('File bukan workbook XLSX yang dapat dibaca.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $total = 0;
            $names = [];
            if ($zip->numFiles > 2048) {
                throw new InvalidArgumentException('Workbook memiliki terlalu banyak bagian.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (isset($names[$stat['name']])) {
                    throw new InvalidArgumentException('Bagian workbook ganda tidak didukung.');
                }
                $names[$stat['name']] = true;
                $total += $stat['size'];
                if ($total > 64 * 1024 * 1024 || $stat['size'] > 24 * 1024 * 1024) {
                    throw new InvalidArgumentException('Workbook melebihi batas pembacaan aman.');
                }
                if (preg_match('~(?:vbaProject\.bin|^xl/externalLinks/|^xl/connections\.xml)~i', $stat['name'])) {
                    throw new InvalidArgumentException('Workbook macro atau koneksi data eksternal tidak didukung.');
                }
            }
            $workbook = $this->xml($this->part($zip, 'xl/workbook.xml'));
            $relationships = $this->xml($this->part($zip, 'xl/_rels/workbook.xml.rels'));
            $rels = [];
            foreach ($relationships->xpath('//*[local-name()="Relationship"]') as $rel) {
                if ((string) $rel['TargetMode'] === 'External') {
                    continue;
                }
                $target = (string) $rel['Target'];
                $parts = [];
                foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target) as $part) {
                    if ($part === '..') {
                        array_pop($parts);
                    } elseif ($part !== '.' && $part !== '') {
                        $parts[] = $part;
                    }
                }
                $rels[(string) $rel['Id']] = implode('/', $parts);
            }
            $sheets = [];
            foreach ($workbook->xpath('//*[local-name()="sheet"]') as $sheet) {
                if ((string) $sheet['state'] === 'veryHidden' || (string) $sheet['state'] === 'hidden') {
                    continue;
                }
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (isset($rels[$id]) && str_starts_with($rels[$id], 'xl/worksheets/')) {
                    $sheets[(string) $sheet['name']] = $rels[$id];
                }
            }
            $selected = $sheetName ?: (isset($sheets['Work Orders']) ? 'Work Orders' : (isset($sheets['OLO AREA-3']) ? 'OLO AREA-3' : array_key_first($sheets)));
            if (! $selected || ! isset($sheets[$selected])) {
                throw new InvalidArgumentException('Sheet tidak ditemukan atau disembunyikan. Periksa nama sheet dan upload ulang.');
            }
            $properties = $workbook->xpath('//*[local-name()="workbookPr"]');
            $date1904 = in_array((string) ($properties[0]['date1904'] ?? ''), ['1', 'true'], true);
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $reader = $this->reader($this->part($zip, 'xl/sharedStrings.xml'));
                while ($reader->read()) {
                    if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                        $shared[] = $this->text($this->xml($reader->readOuterXml()));
                        if (count($shared) > 200000) {
                            throw new InvalidArgumentException('Workbook memiliki terlalu banyak teks bersama.');
                        }
                    }
                }
                $reader->close();
                $this->checkErrors();
            }
            $reader = $this->reader($this->part($zip, $sheets[$selected]));
            $rows = [];
            $headers = null;
            $lastRow = 0;
            $scannedRows = 0;
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }
                $row = $this->xml($reader->readOuterXml());
                $index = (int) $row['r'];
                if ($index <= 0 || $index <= $lastRow || $index > 1048576 || ++$scannedRows > 100000) {
                    throw new InvalidArgumentException('Nomor baris tidak valid atau melebihi batas pembacaan sheet.');
                }
                $lastRow = $index;
                if ($index < $headerRow) {
                    continue;
                }
                $cells = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    if (! preg_match('/^([A-Z]+)([1-9]\d*)$/', (string) $cell['r'], $match) || (int) $match[2] !== $index) {
                        throw new InvalidArgumentException('Alamat sel Excel tidak valid.');
                    }
                    $column = 0;
                    foreach (str_split($match[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > self::MAX_COLUMNS) {
                        throw new InvalidArgumentException('Maksimal 128 kolom per sheet.');
                    }
                    if (isset($cells[$column - 1])) {
                        throw new InvalidArgumentException('Alamat sel ganda pada workbook.');
                    }
                    $type = (string) $cell['t'] ?: 'n';
                    $value = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                    if ($type === 's') {
                        if (! ctype_digit($value) || ! array_key_exists((int) $value, $shared)) {
                            throw new InvalidArgumentException('Referensi teks Excel tidak valid.');
                        }
                        $value = $shared[(int) $value];
                    } elseif ($type === 'inlineStr') {
                        $value = $this->text($cell);
                    }
                    if (mb_strlen($value) > 8192) {
                        throw new InvalidArgumentException('Teks sel melebihi 8.192 karakter.');
                    }
                    $cells[$column - 1] = ['value' => $value, 'type' => $type, 'formula' => (bool) $cell->xpath('./*[local-name()="f"]')];
                    if ($includeFormulas && $cells[$column - 1]['formula']) {
                        $formula = $cell->xpath('./*[local-name()="f"]')[0];
                        $cells[$column - 1]['expression'] = (string) $formula;
                        $cells[$column - 1]['shared_formula'] = (string) $formula['t'] === 'shared';
                    }
                }
                if (strlen(json_encode($cells, JSON_THROW_ON_ERROR)) > 65536) {
                    throw new InvalidArgumentException('Data dalam satu baris melebihi batas aman.');
                }
                if ($index === $headerRow) {
                    if (! $cells) {
                        throw new InvalidArgumentException('Baris header kosong.');
                    }
                    $headers = array_fill(0, max(array_keys($cells)) + 1, '');
                    foreach ($cells as $column => $cell) {
                        if ($cell['formula'] || $cell['type'] === 'e') {
                            throw new InvalidArgumentException('Header harus teks tanpa formula/error.');
                        }
                        $headers[$column] = trim($cell['value']);
                    }
                } elseif (collect($cells)->contains(fn ($cell) => $cell['value'] !== '' || $cell['formula'])) {
                    if (! $headers) {
                        throw new InvalidArgumentException('Baris header tidak tersedia.');
                    }
                    $rows[$index] = $cells;
                    if (count($rows) > self::MAX_ROWS) {
                        throw new InvalidArgumentException('Maksimal 10.000 order dalam satu upload.');
                    }
                }
            }
            $reader->close();
            $this->checkErrors();
            if (! $headers || ! array_filter($headers)) {
                throw new InvalidArgumentException('Header sheet kosong atau tidak ditemukan.');
            }
            if (! $rows) {
                throw new InvalidArgumentException('Sheet tidak memiliki baris order.');
            }

            return ['headers' => $headers, 'rows' => $rows, 'sheet' => $selected, 'sheets' => array_keys($sheets), 'date1904' => $date1904];
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function part(ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);
        if ($content === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            throw new InvalidArgumentException('Struktur XML workbook tidak didukung.');
        }

        return $content;
    }

    private function xml(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content, options: LIBXML_NONET);
        if ($xml === false) {
            throw new InvalidArgumentException('Struktur XML workbook tidak valid.');
        }

        return $xml;
    }

    private function reader(string $content): XMLReader
    {
        $reader = new XMLReader;
        if (! $reader->XML($content, null, LIBXML_NONET)) {
            throw new InvalidArgumentException('XML workbook tidak dapat dibaca.');
        }

        return $reader;
    }

    private function text(SimpleXMLElement $xml): string
    {
        return implode('', array_map('strval', $xml->xpath('.//*[local-name()="t" and not(ancestor::*[local-name()="rPh"])]')));
    }

    private function checkErrors(): void
    {
        if (libxml_get_errors()) {
            throw new InvalidArgumentException('Workbook memiliki XML rusak.');
        }
    }
}
