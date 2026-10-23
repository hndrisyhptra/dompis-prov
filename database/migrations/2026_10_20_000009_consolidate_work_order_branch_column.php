<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARCHIVE = '_legacy_source_district';

    public function up(): void
    {
        // Retain the old reference in source JSON; never replace the actual branch or assignment.
        DB::table('work_orders')->whereNotNull('source_district')->orderBy('id_work_orders')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $source = json_decode($row->source_bima_fields, true, flags: JSON_THROW_ON_ERROR);
                if (array_key_exists(self::ARCHIVE, $source) && $source[self::ARCHIVE] !== $row->source_district) {
                    throw new RuntimeException('Arsip BRANCH lama bertentangan. Periksa data sebelum melanjutkan migrasi.');
                }
                $source[self::ARCHIVE] = $row->source_district;
                DB::table('work_orders')->where('id_work_orders', $row->id_work_orders)->update(['source_bima_fields' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
        }, 'id_work_orders');
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn('source_district'));
    }

    public function down(): void
    {
        Schema::table('work_orders', fn (Blueprint $table) => $table->string('source_district')->nullable());
        DB::table('work_orders')->orderBy('id_work_orders')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $source = json_decode($row->source_bima_fields, true, flags: JSON_THROW_ON_ERROR);
                if (isset($source[self::ARCHIVE])) {
                    DB::table('work_orders')->where('id_work_orders', $row->id_work_orders)->update(['source_district' => $source[self::ARCHIVE]]);
                }
            }
        }, 'id_work_orders');
    }
};
