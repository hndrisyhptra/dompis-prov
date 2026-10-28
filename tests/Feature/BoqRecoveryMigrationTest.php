<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BoqJt;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Boq\BoqImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BoqRecoveryMigrationTest extends TestCase
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

    private const TABLES = ['qe_boq_histories', 'qe_boq_items', 'qe_boqs'];

    private function repair(): object
    {
        return require database_path('migrations/2026_10_27_000022_restore_deleted_boq_tables.php');
    }

    public function test_recovery_restores_current_columns_keys_and_import_without_modifying_existing_data(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['role' => Role::SuperAdmin, 'active' => true]);
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'RECOVER-BOQ', 'progress_category' => 'JT',
            'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64)]);
        $before = [];
        foreach (['users', 'work_orders', 'packages', 'designators', 'designator_package_prices', 'migrations'] as $table) {
            $before[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        $schema = [];
        foreach (self::TABLES as $table) {
            $schema[$table] = ['columns' => Schema::getColumnListing($table), 'indexes' => Schema::getIndexes($table), 'foreign_keys' => collect(Schema::getForeignKeys($table))->sortBy(fn ($key) => implode(',', $key['columns']))->values()->all()];
            Schema::drop($table);
        }
        $this->assertFalse(Schema::hasTable('qe_boqs'));
        $this->repair()->up();
        $this->assertTrue(Schema::hasColumns('qe_boqs', ['material_total', 'labor_total']));
        foreach (self::TABLES as $table) {
            $this->assertSame($schema[$table], ['columns' => Schema::getColumnListing($table), 'indexes' => Schema::getIndexes($table), 'foreign_keys' => collect(Schema::getForeignKeys($table))->sortBy(fn ($key) => implode(',', $key['columns']))->values()->all()]);
            $this->assertDatabaseCount($table, 0);
        }
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        $this->assertFalse(Schema::hasColumn('qe_boqs', 'qe_lop_id'));
        (require database_path('migrations/2026_10_28_000023_rename_boq_tables.php'))->up();
        $file = UploadedFile::fake()->createWithContent('recovery.csv', "DESIGNATOR,QTY\nM-RJ45-CAT6,2\n");
        $result = app(BoqImportService::class)->import($actor, $order, $file, ['package_id' => 2, 'order_revision' => 0, 'boq_revision' => 0, 'header_row' => 1]);
        $this->assertTrue($result['changed']);
        $boq = BoqJt::sole();
        $this->assertSame('13000.00', $boq->grand_total);
        $this->assertSame('13000.00', $boq->material_total);
        $this->assertSame('0.00', $boq->labor_total);
        $this->assertDatabaseCount('boq_items', 1);
        $this->assertDatabaseCount('boq_histories', 1);
        (require database_path('migrations/2026_10_28_000023_rename_boq_tables.php'))->down();
        $this->repair()->up();
        $this->repair()->down();
        (require database_path('migrations/2026_10_28_000023_rename_boq_tables.php'))->up();
        $this->assertSame('13000.00', $boq->fresh()->grand_total);
        $this->assertDatabaseCount('boq_items', 1);
        $this->actingAs($actor)->get(route('boqs.show', $order))->assertOk()->assertSee('13.000,00');
        $this->get(route('revenue.index'))->assertOk()->assertSee('13.000,00');
    }

    public function test_partial_deletion_stops_before_any_schema_or_data_changes(): void
    {
        Schema::drop('qe_boq_histories');
        $columns = Schema::getColumnListing('qe_boqs');
        try {
            $this->repair()->up();
            $this->fail('Partial recovery must require reconciliation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Sebagian tabel BOQ', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasTable('qe_boq_histories'));
        $this->assertTrue(Schema::hasTable('qe_boq_items'));
        $this->assertSame($columns, Schema::getColumnListing('qe_boqs'));
        $this->assertDatabaseCount('designator_package_prices', 1448);
        Schema::drop('qe_boq_items');
        Schema::drop('qe_boqs');
        $this->repair()->up();
    }
}
