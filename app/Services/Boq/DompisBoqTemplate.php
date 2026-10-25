<?php

namespace App\Services\Boq;

use App\Models\Package;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Reads the supplied DOMPIS templates without evaluating prices or total formulas. */
class DompisBoqTemplate
{
    public function adapt(array $book, Package $package): ?array
    {
        $header = $book['rows'][6] ?? [];
        $value = fn ($column) => mb_strtoupper(trim($header[$column]['value'] ?? ''));
        if ($value(1) !== 'DESIGNATOR' || $value(6) !== 'VOL') {
            return null;
        }
        foreach ([1, 3, 4, 6, 7] as $column) {
            if (($header[$column]['formula'] ?? false) || ($header[$column]['type'] ?? '') === 'e') {
                throw new InvalidArgumentException('Header template harus teks tanpa formula/error.');
            }
        }
        if (! preg_match('/^HARGA SATUAN \(PAKET-(5|10)\)$/D', $value(4), $match)
            || $value(3) !== 'SATUAN' || $value(7) !== 'TOTAL HARGA (RP.)') {
            throw new InvalidArgumentException('Header template DOMPIS berubah. Gunakan template Paket 5 atau Paket 10 yang disediakan.');
        }
        if (strtoupper($package->code) !== 'PAKET-'.$match[1]) {
            throw new InvalidArgumentException('File ini untuk Paket '.$match[1].'. Pilih paket harga yang sesuai.');
        }
        $rows = [];
        $calculator = new BoqVolumeFormula($book['rows']);
        foreach ($book['rows'] as $index => $row) {
            if ($index <= 8) {
                continue;
            }
            // Footer totals use G too, but are not item rows. Never evaluate them.
            $number = trim($row[0]['value'] ?? '');
            $code = trim($row[1]['value'] ?? '');
            if (in_array(strtoupper($number), ['MATERIAL', 'JASA', 'TOTAL'], true) && $code === '') {
                continue;
            }
            $qty = $row[6] ?? ['value' => '', 'type' => 'inlineStr', 'formula' => false];
            if ($qty['formula']) {
                try {
                    $qty = ['value' => $calculator->quantity($index), 'type' => 'n', 'formula' => false];
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['file' => 'Baris '.$index.' (G'.$index.'): '.$e->getMessage()]);
                }
            }
            if (! $qty['formula'] && $qty['type'] !== 'e' && (trim($qty['value']) === '' || preg_match('/^0+(?:\.0+)?$/D', trim($qty['value'])))) {
                continue;
            }
            $rows[$index] = [0 => $row[1] ?? ['value' => '', 'type' => 'inlineStr', 'formula' => false], 1 => $qty];
        }

        return ['headers' => ['DESIGNATOR', 'QTY'], 'rows' => $rows];
    }
}
