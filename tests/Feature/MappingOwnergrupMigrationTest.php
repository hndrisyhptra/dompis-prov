<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MappingOwnergrupMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_master_is_empty_unique_and_reversible_without_modifying_existing_orders(): void
    {
        $migration = require database_path('migrations/2026_10_21_000015_create_mapping_ownergrup_table.php');
        $migration->down();
        DB::table('work_orders')->insert(['source_system' => 'BIMA', 'no_tq_sc_id' => 'OWNER-MIGRATION', 'owner_grup' => 'OWNER-UJI',
            'source_snapshot_hash' => str_repeat('a', 64), 'source_bima_fields' => '{}', 'progress_category' => 'SURVEY', 'revision' => 5]);
        $before = (array) DB::table('work_orders')->sole();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('mapping_ownergrup', 'id_mapping_ownergrup'));
        $this->assertDatabaseCount('mapping_ownergrup', 0);
        DB::table('mapping_ownergrup')->insert(['owner_grup' => 'OWNER-UJI', 'kelompok_ownergrup' => 'DISTRICT']);
        try {
            DB::table('mapping_ownergrup')->insert(['owner_grup' => 'OWNER-UJI', 'kelompok_ownergrup' => 'PMD']);
            $this->fail('Duplicate owner_grup accepted');
        } catch (QueryException) {
            $this->assertDatabaseCount('mapping_ownergrup', 1);
        }
        $migration->down();
        $this->assertFalse(Schema::hasTable('mapping_ownergrup'));
        $this->assertSame($before, (array) DB::table('work_orders')->sole());
        $migration->up();
    }
}
