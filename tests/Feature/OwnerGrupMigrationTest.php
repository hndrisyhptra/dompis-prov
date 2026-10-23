<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnerGrupMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_nullable_owner_grup_migration_preserves_existing_data_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_10_21_000014_add_owner_grup_to_work_orders.php');
        $migration->down();
        $id = DB::table('work_orders')->insertGetId(['source_system' => 'BIMA', 'no_tq_sc_id' => 'OWNER-MIGRATION',
            'source_snapshot_hash' => str_repeat('a', 64), 'source_bima_fields' => '{}', 'progress_category' => 'SURVEY', 'revision' => 7,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-02 00:00:00'], 'id_work_orders');
        $before = (array) DB::table('work_orders')->where('id_work_orders', $id)->first();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('work_orders', 'owner_grup'));
        $this->assertSame($before + ['owner_grup' => null], (array) DB::table('work_orders')->where('id_work_orders', $id)->first());
        DB::table('work_orders')->where('id_work_orders', $id)->update(['owner_grup' => 'OWNER UJI']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('work_orders', 'owner_grup'));
        $this->assertSame($before, (array) DB::table('work_orders')->where('id_work_orders', $id)->first());
        $migration->up();
    }
}
