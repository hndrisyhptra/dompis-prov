<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkOrderKmlMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_named_keys_additive_migration_and_rollback_preserve_work_orders_and_catalog(): void
    {
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'KML-MIGRATION', 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64), 'progress_category' => 'JT']);
        $before = $order->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_25_000019_create_work_order_kmls.php');
        $this->assertTrue(Schema::hasColumns('work_order_kmls', ['id_work_order_kmls', 'id_work_orders', 'id_users', 'geojson']));
        $migration->down();
        $this->assertFalse(Schema::hasTable('work_order_kmls'));
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('designator_package_prices', 1448);
        $migration->up();
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('work_order_kmls', 0);
    }
}
