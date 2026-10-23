<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BimaLogChangesMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_legacy_task_logs_are_preserved_when_adding_and_rolling_back_nullable_change_details(): void
    {
        $migration = require database_path('migrations/2026_10_20_000013_add_bima_log_field_changes.php');
        $migration->down();
        $id = DB::table('work_orders')->insertGetId(['source_system' => 'BIMA', 'no_tq_sc_id' => '000LOG-MIGRATION', 'source_bima_fields' => '{}', 'source_snapshot_hash' => str_repeat('a', 64)], 'id_work_orders');
        DB::table('bima_task_logs')->insert(['id_work_orders' => $id, 'old_task' => 'Survey', 'new_task' => 'Activation', 'old_status_bima' => 'OPEN', 'new_status_bima' => 'COMPLETE', 'old_status_order' => 'Survey-OPEN', 'new_status_order' => 'Activation-COMPLETE', 'changed_at' => '2026-10-20 01:02:03']);
        $before = (array) DB::table('bima_task_logs')->first();
        $order = (array) DB::table('work_orders')->first();
        $migration->up();
        $this->assertSame($before + ['field_changes' => null], (array) DB::table('bima_task_logs')->first());
        $this->assertSame($order, (array) DB::table('work_orders')->first());
        $migration->down();
        $this->assertFalse(Schema::hasColumn('bima_task_logs', 'field_changes'));
        $this->assertSame($before, (array) DB::table('bima_task_logs')->first());
        $migration->up();
    }
}
