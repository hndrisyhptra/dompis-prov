<?php

namespace App\Support;

class MaterialReportFields
{
    public const TEXT = [
        'no_tq_sc_id' => 'NO TQ/SC/ID', 'nama_pelanggan' => 'Nama Pelanggan', 'alamat' => 'Alamat', 'no_voice' => 'No Voice', 'no_inet' => 'No inet',
        'sn_ont' => 'SN_ONT', 'merek_ont' => 'MEREK_ONT', 'sn_stb' => 'SN_STB', 'merek_stb' => 'MEREK_STB', 'odp' => 'ODP', 'qr_code_odp' => 'Qr_Code_ODP',
        'qr_code_dc' => 'Qr_Code_DC', 'port_odp' => 'PORT ODP', 'valins_id' => 'Valins ID', 'nik_teknisi' => 'Nik Teknisi', 'teknisi' => 'Teknisi', 'jenis_transaksi' => 'JENIS TRANSAKSI',
    ];

    public const QUANTITIES = [
        'tiang' => 'Tiang', 'tiang_galvanis' => 'Tiang Galvanis', 'total_dc' => 'Total DC', 's_clamp' => 'S-CLAMP', 'c_hook' => 'C-HOOK', 'otp' => 'OTP', 'prekso' => 'PREKSO',
        'tray' => 'TRAY', 'm_dc_soc' => 'M-DC-SOC', 'pp_of_in' => 'PP-OF-IN', 'dd_s3_1' => 'DD-S3-1', 'dd_v4_1' => 'DD-V4-1', 'dcd_pvc_1' => 'DCD-PVC-1', 'dd_rod' => 'DD-ROD',
        'klem_hdpe' => 'Klem-HDPE', 'bc_tr_0_4' => 'BC-TR-0.4', 'bc_tr_0_6' => 'BC-TR-0.6', 'hh_pit_p_ha' => 'HH-PIT-P-HA', 'hh_pit_p_odp' => 'HH-PIT-P-ODP',
        'dd_hdpe_40_1' => 'DD-HDPE-40-1', 'tali_tambang_nilon' => 'Tali Tambang nilon', 'pc_sc_lc_10m' => 'PC SC-LC 10m', 'pl_ring' => 'PL-RING',
    ];

    public const COORDINATES = ['longitude_odp' => 'LONGITUDE_odp', 'latitude_odp' => 'LATITUDE_ODP', 'longitude_ont' => 'LONGITUDE_ONT', 'latitude_ont' => 'LATITUDE_ONT'];

    public static function all(): array
    {
        // Preserve the owner's order: Koord Tiang follows Tiang Galvanis.
        return self::TEXT + array_slice(self::QUANTITIES, 0, 2, true) + ['koord_tiang' => 'Koord Tiang'] + array_slice(self::QUANTITIES, 2, null, true) + self::COORDINATES;
    }

    public static function template(?string $order = null): string
    {
        return '/material'."\n".implode("\n", array_map(fn ($key, $label) => $label.': '.($key === 'no_tq_sc_id' ? ($order ?? '') : '-'), array_keys(self::all()), self::all()));
    }
}
