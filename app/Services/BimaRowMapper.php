<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class BimaRowMapper
{
    public const HEADERS = ['NO', 'TGL ORDER', 'BULAN', 'TAHUN', 'REGIONAL', 'BRANCH', 'STO', 'NO TQ/SC/ID', 'NAMA OLO', 'ALAMAT', 'LAYANAN', 'JENIS PEKERJAAN', 'BW', 'STATUS', 'TASK BIMA', 'TGL PS', 'SID', 'KETERANGAN', 'ODP', 'SN', 'OLT', 'PORT OLT', 'METRO', 'PORT METRO', 'PANJANG DC', 'KATEGORY PROGRES', 'STATUS FINAL', 'BULAN PS', 'BRANCH / DISTRICT', 'PS HARI INI', 'UMUR (HARI)', 'KATEGORI UMUR'];

    public function validateHeader(array $headers): void
    {
        $normalized = array_map(fn ($value) => preg_replace('/\s+/u', ' ', trim((string) $value)), $headers);
        if ($normalized !== self::HEADERS) {
            throw new InvalidArgumentException('Header BIMA_RAW harus persis 32 kolom A:AF sesuai template; hanya whitespace header yang ditoleransi.');
        }
    }

    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial < 1 || $serial > 100000 || floor($serial) === 60.0) {
                throw new InvalidArgumentException('Serial tanggal Excel tidak valid.');
            }

            return (new DateTimeImmutable($serial < 60 ? '1899-12-31' : '1899-12-30'))->modify('+'.(int) $serial.' days')->format('Y-m-d');
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i:s.v'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim((string) $value));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        throw new InvalidArgumentException('Tanggal tidak valid. Gunakan ISO YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY atau serial Excel.');
    }

    public function map(array $row): array
    {
        if (count($row) > 32) {
            throw new InvalidArgumentException('Baris melebihi kontrak 32 kolom A:AF.');
        }
        $row = array_pad($row, 32, '');
        $id = $row[7];
        if (! is_string($id) || trim($id) === '' || mb_strlen($id) > 191 || $id !== trim($id)) {
            throw new InvalidArgumentException('Kolom H wajib ID teks, maksimal 191 karakter, tanpa spasi tepi.');
        }
        foreach (array_slice($row, 0, 28) as $index => $value) {
            if (is_array($value) || is_object($value) || (is_string($value) && str_starts_with(ltrim($value), '='))) {
                throw new InvalidArgumentException('Paste values saja; formula/data kompleks di atribut Bima ditolak.');
            }
            if ($index !== 9 && mb_strlen((string) $value) > 255) {
                // Long notes are kept in JSON, not a VARCHAR column.
                if ($index !== 17) {
                    throw new InvalidArgumentException('Nilai atribut Bima melebihi 255 karakter.');
                }
            }
        }
        $text = fn ($index) => (string) ($row[$index] ?? '');
        // A:AB are inbound attributes. AC:AF are ignored derived fields, never operational progress/KPIs.
        $source = array_combine(array_slice(self::HEADERS, 0, 28), array_map(fn ($v) => (string) ($v ?? ''), array_slice($row, 0, 28)));
        $data = [
            'source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'sid' => $text(16),
            'tgl_order' => $this->date($row[1]), 'tgl_ps' => $this->date($row[15]),
            'branch' => $text(5), 'sto' => $text(6), 'regional' => $text(4),
            'nama_olo' => $text(8), 'alamat' => $text(9), 'layanan' => $text(10),
            'jenis_pekerjaan' => $text(11), 'status_bima' => $text(13), 'task_bima' => $text(14), 'status_final' => $text(26),
            'source_bima_fields' => $source,
        ];
        $data['source_snapshot_hash'] = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $data;
    }
}
