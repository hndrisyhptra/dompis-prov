<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OrderMappingMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_open_category_extension_preserves_master_ids_foreign_keys_and_refuses_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_20_000012_allow_open_order_mapping.php');
        $migration->down();
        $id = DB::table('mapping_order')->insertGetId(['status_order' => 'Test-COMPLETE', 'mapping_order' => 'PS', 'active' => true,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00'], 'id_mapping_order');
        DB::table('work_orders')->insert(['source_system' => 'BIMA', 'no_tq_sc_id' => '000ENUM', 'source_bima_fields' => '{}', 'source_snapshot_hash' => str_repeat('a', 64), 'status_order' => 'Test-COMPLETE', 'id_mapping_order' => $id, 'mapping_order' => 'PS', 'revision' => 9]);
        $before = (array) DB::table('mapping_order')->where('id_mapping_order', $id)->first();
        $order = (array) DB::table('work_orders')->first();
        $migration->up();
        $this->assertSame($before, (array) DB::table('mapping_order')->where('id_mapping_order', $id)->first());
        $this->assertSame($order, (array) DB::table('work_orders')->first());
        $open = DB::table('mapping_order')->insertGetId(['status_order' => 'Test-STARTWORK', 'mapping_order' => 'OPEN', 'active' => true], 'id_mapping_order');
        try {
            $migration->down();
            $this->fail('OPEN data must not be lost during rollback');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('masih berisi OPEN', $exception->getMessage());
            $this->assertSame('OPEN', DB::table('mapping_order')->where('id_mapping_order', $open)->value('mapping_order'));
        }
        DB::table('mapping_order')->where('id_mapping_order', $open)->delete(); // Isolated disposable fixture only.
        $migration->down();
        $this->assertSame($before, (array) DB::table('mapping_order')->where('id_mapping_order', $id)->first());
        $migration->up();
    }
}
