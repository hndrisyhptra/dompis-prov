<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BoqValueMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_28_000023_rename_boq_tables.php'))->down();
    }

    protected function tearDown(): void
    {
        (require database_path('migrations/2026_10_28_000023_rename_boq_tables.php'))->up();
        parent::tearDown();
    }

    public function test_backfill_uses_only_active_snapshot_items_and_preserves_order_header_hash_price_and_history(): void
    {
        $migration = require database_path('migrations/2026_10_25_000020_add_boq_value_breakdown.php');
        $migration->down();
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'REV-MIGRATION', 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64), 'progress_category' => 'JT']);
        $boq = DB::table('qe_boqs')->insertGetId(['id_work_orders' => $order->getKey(), 'package_id' => 2, 'grand_total' => '123.45', 'item_count' => 2,
            'file_path' => 'boq/test.xlsx', 'original_name' => 'test.xlsx', 'file_hash' => str_repeat('b', 64), 'content_hash' => str_repeat('c', 64), 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00']);
        foreach ([['MATERIAL', '100.00', null], ['JASA', '23.45', null], ['MATERIAL', '9999.99', '2026-08-01 00:00:00']] as [$type, $amount, $deleted]) {
            DB::table('qe_boq_items')->insert(['id_qe_boqs' => $boq, 'designator_id' => 531, 'designator_code' => 'SNAPSHOT', 'item_name' => 'Snapshot item', 'unit' => 'pcs',
                'type' => $type, 'qty' => 1, 'unit_price' => $amount, 'total_price' => $amount, 'deleted_at' => $deleted]);
        }
        $headerBefore = (array) DB::table('qe_boqs')->where('id_qe_boqs', $boq)->first();
        $orderBefore = $order->fresh()->getRawOriginal();
        $itemsBefore = DB::table('qe_boq_items')->orderBy('id_qe_boq_items')->get()->toJson();
        $pricesBefore = DB::table('designator_package_prices')->orderBy('id_designator_package_price')->get()->toJson();
        $migration->up();
        $header = (array) DB::table('qe_boqs')->where('id_qe_boqs', $boq)->first();
        $this->assertEquals('100.00', $header['material_total']);
        $this->assertEquals('23.45', $header['labor_total']);
        unset($header['material_total'], $header['labor_total']);
        $this->assertEquals($headerBefore, $header);
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($itemsBefore, DB::table('qe_boq_items')->orderBy('id_qe_boq_items')->get()->toJson());
        $this->assertSame($pricesBefore, DB::table('designator_package_prices')->orderBy('id_designator_package_price')->get()->toJson());
        $this->assertDatabaseCount('qe_boq_histories', 0);
        $migration->up(); // Retry after DDL/backfill does not duplicate columns or rewrite history.
        $this->assertDatabaseCount('qe_boq_histories', 0);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('qe_boqs', 'material_total'));
        $this->assertEquals($headerBefore, (array) DB::table('qe_boqs')->where('id_qe_boqs', $boq)->first());
        $migration->up();
    }

    public function test_inconsistent_existing_total_requires_reconciliation_before_ddl(): void
    {
        $migration = require database_path('migrations/2026_10_25_000020_add_boq_value_breakdown.php');
        $migration->down();
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'REV-BAD-TOTAL', 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64)]);
        $id = DB::table('qe_boqs')->insertGetId(['id_work_orders' => $order->getKey(), 'package_id' => 2, 'grand_total' => '1.00', 'file_path' => 'boq/test.xlsx',
            'original_name' => 'test.xlsx', 'file_hash' => str_repeat('b', 64), 'content_hash' => str_repeat('c', 64)]);
        try {
            $migration->up();
            $this->fail('A mismatched financial total must be explicit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rekonsiliasi', $e->getMessage());
            $this->assertFalse(Schema::hasColumn('qe_boqs', 'material_total'));
        }
        DB::table('qe_boqs')->where('id_qe_boqs', $id)->update(['grand_total' => 0]);
        $migration->up();
    }
}
