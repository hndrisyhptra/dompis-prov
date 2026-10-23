<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const COLUMNS = [
        'order_date' => 'tgl_order', 'order_month' => 'bulan', 'order_year' => 'tahun', 'regional' => 'regional',
        'source_branch_name' => 'branch', 'sto_code' => 'sto', 'external_order_id' => 'no_tq_sc_id',
        'customer_name' => 'nama_olo', 'installation_address' => 'alamat', 'service_name' => 'layanan',
        'job_type' => 'jenis_pekerjaan', 'bandwidth' => 'bw', 'bima_status' => 'status_order', 'bima_task' => 'task_bima',
        'bima_ps_date' => 'tgl_ps', 'source_sid' => 'sid', 'bima_notes' => 'keterangan', 'source_odp' => 'odp', 'source_sn' => 'sn',
        'olt' => 'olt', 'olt_port' => 'port_olt', 'metro' => 'metro', 'metro_port' => 'port_metro',
        'bima_final_status' => 'status_final', 'bima_ps_month' => 'bulan_ps', 'source_ps_today' => 'ps_hari_ini',
        'source_age_days' => 'umur_hari', 'source_age_category' => 'kategori_umur',
    ];

    public function up(): void
    {
        $this->rename(self::COLUMNS);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::COLUMNS));
    }

    private function rename(array $columns): void
    {
        // Rename in place: retain values, types, collation, indexes, foreign keys and timestamps.
        Schema::table('work_orders', function (Blueprint $table) use ($columns) {
            foreach ($columns as $old => $new) {
                if ($old !== $new) {
                    $table->renameColumn($old, $new);
                }
            }
        });
    }
};
