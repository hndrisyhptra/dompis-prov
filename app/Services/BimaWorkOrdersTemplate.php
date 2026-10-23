<?php

namespace App\Services;

class BimaWorkOrdersTemplate
{
    public const NAME = 'bima-work-orders-v3';

    public const COLUMNS = [
        'tgl_order' => [1, 'Date Created'],
        'no_tq_sc_id' => [4, 'ORDER ID'],
        'sid' => [7, 'Service No.'],
        'task_bima' => [8, 'Description'],
        'jenis_pekerjaan' => [9, 'CRM Order Type'],
        'owner_grup' => [10, 'Owner Group'],
        'status_bima' => [11, 'Status'],
        'layanan' => [12, 'Product Name'],
        'alamat' => [13, 'Address'],
        'nama_olo' => [15, 'Customer Name'],
        'sto' => [16, 'Workzone'],
        'branch' => [29, 'District TIF'],
        'regional' => [30, 'Regional TIF'],
    ];

    public static function mapping(array $headers): ?array
    {
        $normalize = fn ($value) => mb_strtoupper(preg_replace('/[\s-]+/u', ' ', trim($value)));
        $mapping = [];
        foreach (self::COLUMNS as $field => [$column, $header]) {
            $actual = $normalize($headers[$column - 1] ?? '');
            // The latest BIMA export labels D as ORDER. Accept it only inside the complete, position-checked preset.
            $allowed = $field === 'no_tq_sc_id' ? ['ORDER ID', 'ORDER'] : [$normalize($header)];
            if (! in_array($actual, $allowed, true)) {
                return null;
            }
            $mapping[$field] = (string) $column;
        }

        return $mapping;
    }
}
