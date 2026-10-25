<?php

namespace App\Services\Material;

use App\Support\MaterialReportFields;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

class MaterialReportParser
{
    public function parse(string $text): array
    {
        if (mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('Laporan maksimal 4.096 karakter.');
        }
        $labels = [];
        foreach (MaterialReportFields::all() as $key => $label) {
            $labels[$this->normalize($label)] = $key;
        }
        $values = array_fill_keys(array_keys(MaterialReportFields::all()), null);
        $seen = [];
        $lines = preg_split('/\R/u', trim($text));
        $command = array_shift($lines);
        if (! preg_match('/^\/(material|koreksi)(?:@[a-zA-Z0-9_]+)?(?: ([A-Z0-9]{26}))?$/D', $command, $match)) {
            throw new InvalidArgumentException('Mulai pesan dengan /material, lalu isi satu kolom: nilai per baris sesuai template.');
        }
        $correction = ($match[1] === 'koreksi') ? ($match[2] ?? null) : null;
        if ($match[1] === 'material' && isset($match[2])) {
            throw new InvalidArgumentException('Gunakan /material tanpa nomor laporan; untuk koreksi gunakan /koreksi NOMOR_LAPORAN.');
        }
        if ($match[1] === 'koreksi' && ! $correction) {
            throw new InvalidArgumentException('Koreksi memerlukan nomor laporan: /koreksi NOMOR_LAPORAN, lalu template lengkap.');
        }
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! str_contains($line, ':')) {
                throw new InvalidArgumentException('Baris '.($index + 2).' harus berupa nama kolom: nilai.');
            }
            [$label, $value] = explode(':', $line, 2);
            $key = $labels[$this->normalize($label)] ?? null;
            if (! $key) {
                throw new InvalidArgumentException('Nama kolom pada baris '.($index + 2).' tidak dikenali. Gunakan template /material.');
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Kolom '.MaterialReportFields::all()[$key].' ditulis dua kali.');
            }
            $seen[$key] = true;
            $value = trim($value);
            if ($value === '' || $value === '-') {
                continue;
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) || mb_strlen($value) > ($key === 'alamat' || $key === 'koord_tiang' ? 1000 : 255)) {
                throw new InvalidArgumentException('Nilai '.MaterialReportFields::all()[$key].' terlalu panjang atau berisi karakter kontrol.');
            }
            if (isset(MaterialReportFields::QUANTITIES[$key])) {
                if (! preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', $value)) {
                    throw new InvalidArgumentException(MaterialReportFields::all()[$key].' harus angka 0 atau positif, maksimal 3 desimal (titik), tanpa pemisah ribuan.');
                }
                $value = (string) BigDecimal::of($value)->toScale(3);
            } elseif (isset(MaterialReportFields::COORDINATES[$key])) {
                if (! preg_match('/^-?\d{1,3}(?:\.\d{1,8})?$/D', $value) || BigDecimal::of($value)->abs()->isGreaterThan(str_starts_with($key, 'longitude') ? 180 : 90)) {
                    throw new InvalidArgumentException(MaterialReportFields::all()[$key].' harus derajat desimal valid (titik); longitude ±180, latitude ±90.');
                }
                $value = (string) BigDecimal::of($value)->toScale(8);
            }
            $values[$key] = $value;
        }
        if (! $values['no_tq_sc_id']) {
            throw new InvalidArgumentException('NO TQ/SC/ID wajib diisi untuk menentukan order dan Branch. Kolom lainnya boleh - atau kosong.');
        }

        return ['fields' => $values, 'correction' => $correction];
    }

    private function normalize(string $label): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($label)));
    }
}
