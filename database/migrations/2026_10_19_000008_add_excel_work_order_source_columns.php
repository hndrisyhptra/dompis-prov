<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCES = ['order_month' => 'BULAN', 'order_year' => 'TAHUN', 'bandwidth' => 'BW', 'bima_task' => 'TASK BIMA',
        'bima_notes' => 'KETERANGAN', 'source_odp' => 'ODP', 'source_sn' => 'SN', 'olt' => 'OLT', 'olt_port' => 'PORT OLT',
        'metro' => 'METRO', 'metro_port' => 'PORT METRO', 'bima_ps_month' => 'BULAN PS', 'source_district' => 'BRANCH / DISTRICT',
        'source_ps_today' => 'PS HARI INI', 'source_age_days' => 'UMUR (HARI)', 'source_age_category' => 'KATEGORI UMUR'];

    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            foreach (array_keys(self::SOURCES) as $column) {
                if ($column === 'bima_notes') {
                    $table->text($column)->nullable();
                } else {
                    $table->string($column)->nullable();
                }
            }
        });
        Schema::table('sync_job_rows', fn (Blueprint $table) => $table->longText('payload')->nullable());
        // Only copy exact existing source values; no operational or derived-value inference.
        DB::table('work_orders')->orderBy('id_work_orders')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $source = json_decode($row->source_bima_fields, true) ?: [];
                $values = [];
                foreach (self::SOURCES as $column => $header) {
                    $value = $source[$header] ?? null;
                    if (is_scalar($value) && (string) $value !== '' && mb_strlen((string) $value) <= ($column === 'bima_notes' ? 8192 : 255)) {
                        $values[$column] = (string) $value;
                    }
                }
                if ($values) {
                    DB::table('work_orders')->where('id_work_orders', $row->id_work_orders)->update($values);
                }
            }
        }, 'id_work_orders');
    }

    public function down(): void
    {
        Schema::table('sync_job_rows', fn (Blueprint $table) => $table->dropColumn('payload'));
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn(array_keys(self::SOURCES)));
    }
};
