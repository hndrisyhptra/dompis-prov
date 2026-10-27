<?php

namespace App\Services;

class BimaWorkOrdersTemplate
{
    public const NAME = 'bima-work-orders-v4-ps-date';

    public const COLUMNS = [
        'tgl_order' => [1, 'Date Created'],
        'tgl_ps' => [2, 'Date Modified'],
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
        $indexed = [];
        foreach ($headers as $index => $header) {
            $indexed[$normalize($header)][] = (string) ($index + 1);
        }
        $mapping = [];
        foreach (self::COLUMNS as $field => [$column, $header]) {
            // BIMA may omit witel/add Booking ID. Match the full header contract, never stale positions.
            // ORDER remains an identity alias only when the complete unique BIMA header contract is present.
            $allowed = $field === 'no_tq_sc_id' ? ['ORDER ID', 'ORDER'] : [$normalize($header)];
            $matches = array_merge(...array_map(fn ($candidate) => $indexed[$candidate] ?? [], $allowed));
            if (count($matches) !== 1) {
                return null;
            }
            $mapping[$field] = $matches[0];
        }

        return $mapping;
    }
}
