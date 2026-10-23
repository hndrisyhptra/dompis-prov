<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BimaStatusMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_raw_status_is_backfilled_without_touching_progress_hash_or_timestamps_and_rollback_restores_raw_status(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'Asia/Jakarta'));
        $migration = require database_path('migrations/2026_10_20_000011_add_bima_status_task_log_and_mapping.php');
        $logEvolution = require database_path('migrations/2026_10_20_000013_add_bima_log_field_changes.php');
        $logEvolution->down();
        $migration->down();
        $id = DB::table('work_orders')->insertGetId(['source_system' => 'BIMA', 'no_tq_sc_id' => '000MIGRATION', 'status_order' => 'COMPLETE',
            'task_bima' => 'Approval E2E Testing Manual', 'tgl_order' => '2026-10-01', 'bulan' => 'SALAH', 'tahun' => '2025', 'umur_hari' => '999',
            'kategori_umur' => 'LAMA', 'progress_category' => 'CLOSE', 'operational_status' => 'FIELD_DONE', 'revision' => 8, 'source_snapshot_hash' => str_repeat('a', 64),
            'source_bima_fields' => '{"STATUS":"COMPLETE"}', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-02 00:00:00',
            'last_progress_at' => '2026-10-02 00:00:00', 'branch_completed_at' => '2026-10-02 00:00:00', 'odp' => 'ODP-TETAP', 'sn' => '000SN'], 'id_work_orders');
        $before = (array) DB::table('work_orders')->where('id_work_orders', $id)->first();
        $migration->up();
        $after = (array) DB::table('work_orders')->where('id_work_orders', $id)->first();
        foreach ($before as $field => $value) {
            if (! in_array($field, ['status_order', 'bulan', 'tahun', 'umur_hari', 'kategori_umur'], true)) {
                $this->assertSame($value, $after[$field], $field);
            }
        }
        $this->assertSame('COMPLETE', $after['status_bima']);
        $this->assertSame('Approval E2E Testing Manual-COMPLETE', $after['status_order']);
        $this->assertSame('10', $after['bulan']);
        $this->assertSame('2026', $after['tahun']);
        $this->assertSame('19', (string) $after['umur_hari']);
        $this->assertSame('14-28 HARI', $after['kategori_umur']);
        $this->assertNull($after['mapping_order']);
        $this->assertNull($after['id_mapping_order']);
        $this->assertDatabaseCount('mapping_order', 0);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('work_orders', 'status_bima'));
        $this->assertFalse(Schema::hasTable('mapping_order'));
        $this->assertSame('COMPLETE', DB::table('work_orders')->where('id_work_orders', $id)->value('status_order'));
        $this->assertSame(8, DB::table('work_orders')->where('id_work_orders', $id)->value('revision'));
        $migration->up();
        $logEvolution->up();
    }
}
