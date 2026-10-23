<?php

namespace App\Services;

use InvalidArgumentException;

class ExcelWorkOrderMapper
{
    public const FIELDS = ['tgl_order' => 'TGL ORDER', 'bulan' => 'BULAN', 'tahun' => 'TAHUN', 'regional' => 'REGIONAL',
        'branch' => 'BRANCH', 'sto' => 'STO', 'no_tq_sc_id' => 'NO TQ/SC/ID', 'nama_olo' => 'NAMA OLO',
        'alamat' => 'ALAMAT', 'layanan' => 'LAYANAN', 'jenis_pekerjaan' => 'JENIS PEKERJAAN', 'bw' => 'BW', 'status_order' => 'STATUS ORDER',
        'status_bima' => 'STATUS BIMA', 'mapping_order' => 'MAPPING ORDER', 'task_bima' => 'TASK BIMA', 'owner_grup' => 'OWNER GROUP', 'tgl_ps' => 'TGL PS', 'sid' => 'SID', 'keterangan' => 'KETERANGAN', 'odp' => 'ODP', 'sn' => 'SN',
        'olt' => 'OLT', 'port_olt' => 'PORT OLT', 'metro' => 'METRO', 'port_metro' => 'PORT METRO', 'status_final' => 'STATUS FINAL',
        'bulan_ps' => 'BULAN PS', 'ps_hari_ini' => 'PS HARI INI', 'umur_hari' => 'UMUR (HARI)',
        'kategori_umur' => 'KATEGORI UMUR'];

    public const DERIVED_FIELDS = ['bulan', 'tahun', 'status_order', 'mapping_order', 'umur_hari', 'kategori_umur'];

    public const REFERENCE_FIELDS = ['ps_hari_ini'];

    public static function inputFields(): array
    {
        return array_diff_key(self::FIELDS, array_flip(self::DERIVED_FIELDS));
    }

    public function suggest(array $headers): array
    {
        $preset = BimaWorkOrdersTemplate::mapping($headers);
        if ($preset !== null) {
            return $preset;
        }
        $mapping = [];
        foreach ($headers as $index => $header) {
            $normalized = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($header)));
            $field = match ($normalized) {
                'BRANCH / DISTRICT','DISTRICT' => null,
                'STATUS','STATUS ORDER' => 'status_bima',
                'STATUS FINAL (OPEN,CLOSE,CANCEL)','STATUS FINAL (OPEN, CLOSE, CANCEL)' => 'status_final',
                default => array_search($normalized, self::inputFields(), true) ?: null,
            };
            if ($field && ! isset($mapping[$field])) {
                $mapping[$field] = (string) ($index + 1);
            }
        }

        return $mapping;
    }

    public function map(array $cells, array $mapping, bool $date1904 = false): array
    {
        $data = ['source_system' => 'BIMA'];
        $source = [];
        foreach (self::inputFields() as $field => $label) {
            if (empty($mapping[$field])) {
                continue;
            }
            $cell = $cells[(int) $mapping[$field] - 1] ?? ['value' => '', 'type' => 's', 'formula' => false];
            $value = $cell['value'];
            if (($cell['type'] ?? '') === 'e') {
                throw new InvalidArgumentException('Sel berisi error Excel pada '.$label.'.');
            }
            if (($cell['formula'] ?? false) && ! in_array($field, self::REFERENCE_FIELDS, true)) {
                throw new InvalidArgumentException('Formula pada '.$label.' tidak diimpor. Gunakan nilai hasil export, bukan formula.');
            }
            if ($field === 'no_tq_sc_id') {
                if (! in_array($cell['type'], ['s', 'inlineStr', 'str'], true) || $cell['formula'] || trim($value) === '' || $value !== trim($value) || mb_strlen($value) > 191) {
                    throw new InvalidArgumentException('NO TQ/SC/ID wajib teks asli tanpa formula/spasi tepi, maksimal 191 karakter. ID numerik perlu diekspor sebagai teks agar nol depan tidak hilang.');
                }
            }
            if (in_array($field, ['sid', 'sn'], true) && $value !== '' && ! in_array($cell['type'], ['s', 'inlineStr', 'str'], true)) {
                throw new InvalidArgumentException($label.' harus teks asli agar identitas dan nol depan tidak berubah. Periksa nilai pada sumber BIMA.');
            }
            if (in_array($field, ['tgl_order', 'tgl_ps'], true)) {
                $dateValue = $value;
                if ($date1904 && is_numeric($dateValue)) {
                    $dateValue = (string) ((float) $dateValue + 1462);
                }
                $value = (new BimaRowMapper)->date($dateValue);
            } else {
                if (mb_strlen($value) > (in_array($field, ['alamat', 'keterangan']) ? 8192 : ($field === 'no_tq_sc_id' ? 191 : 255))) {
                    throw new InvalidArgumentException('Nilai '.$label.' terlalu panjang.');
                }
                $value = $value === '' ? null : $value;
            }
            if ($field === 'status_final' && $value !== null) {
                $value = strtoupper(trim($value));
                if (! in_array($value, ['OPEN', 'CLOSE', 'CANCEL'], true)) {
                    throw new InvalidArgumentException('STATUS FINAL hanya OPEN, CLOSE atau CANCEL.');
                }
            }
            if ($field === 'tahun' && $value !== null && ! preg_match('/^\d{4}$/', $value)) {
                throw new InvalidArgumentException('TAHUN harus empat digit.');
            }
            $data[$field] = $value;
            $source[$label] = $cell['value'];
        }
        if (! isset($data['no_tq_sc_id']) || $data['no_tq_sc_id'] === '') {
            throw new InvalidArgumentException('NO TQ/SC/ID kosong.');
        }
        ksort($source);
        $data['source_bima_fields'] = $source;

        return $data;
    }
}
