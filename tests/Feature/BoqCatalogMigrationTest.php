<?php

namespace Tests\Feature;

use App\Models\Designator;
use App\Models\DesignatorType;
use App\Models\Package;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BoqCatalogMigrationTest extends TestCase
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

    public function test_catalog_reference_counts_values_and_named_keys_without_categories_or_legacy_qe_orders(): void
    {
        $this->assertDatabaseCount('designator_types', 5);
        $this->assertDatabaseCount('designators', 724);
        $this->assertDatabaseCount('packages', 2);
        $this->assertDatabaseCount('designator_package_prices', 1448);
        $this->assertDatabaseCount('qe_boqs', 0);
        $this->assertDatabaseCount('qe_boq_items', 0);
        $this->assertDatabaseCount('qe_boq_histories', 0);
        $this->assertSame('MATERIAL', DesignatorType::findOrFail(1)->code);
        $this->assertSame('JASA', DesignatorType::findOrFail(2)->code);
        $this->assertSame('Paket-5', Package::findOrFail(2)->code);
        $this->assertSame('Paket-10', Package::findOrFail(3)->code);
        $this->assertSame('M-RJ45-CAT6', Designator::findOrFail(531)->code);
        $this->assertFalse(Schema::hasTable('designator_categories'));
        $this->assertFalse(Schema::hasColumn('designators', 'designator_category_id'));
        $this->assertNull(Designator::findOrFail(531)->created_by);
        $reference = json_decode(file_get_contents(database_path('data/boq_catalog_20261025.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['designator_types', 'designators', 'packages', 'designator_package_prices'] as $table) {
            foreach ($reference[$table] as $source) {
                unset($source['designator_category_id']);
                $source['created_by'] = $source['updated_by'] = null;
                $pk = array_key_first($source);
                $row = (array) DB::table($table)->where($pk, $source[$pk])->first();
                foreach ($source as $column => $value) {
                    $this->assertEquals($value, $row[$column], $table.'.'.$column);
                }
            }
        }
    }

    public function test_migration_is_additive_and_reversible_and_preserves_work_order_and_user_attributes(): void
    {
        $actor = User::factory()->create();
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'BOQ-MIGRATION', 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64), 'progress_category' => 'JT']);
        $before = $order->fresh()->getRawOriginal();
        $userBefore = $actor->fresh()->getRawOriginal();
        $valueMigration = require database_path('migrations/2026_10_25_000020_add_boq_value_breakdown.php');
        $valueMigration->down();
        $migration = require database_path('migrations/2026_10_25_000017_create_work_order_boq_catalog.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('qe_boqs'));
        $this->assertFalse(Schema::hasTable('designators'));
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame($userBefore, $actor->fresh()->getRawOriginal());
        $migration->up();
        $valueMigration->up();
        $this->assertTrue(Schema::hasColumn('qe_boqs', 'id_qe_boqs'));
        $this->assertTrue(Schema::hasColumn('qe_boqs', 'id_work_orders'));
        $this->assertTrue(Schema::hasColumn('qe_boq_histories', 'id_users'));
        $this->assertFalse(Schema::hasColumn('qe_boqs', 'qe_lop_id'));
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }
}
