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

class BoqRenameMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_rename_and_rollback_preserve_ids_prices_versions_history_and_work_order(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['role' => Role::SuperAdmin, 'active' => true]);
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'RENAME-BOQ', 'progress_category' => 'JT',
            'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64)]);
        foreach ([2, 3] as $i => $qty) {
            app(BoqImportService::class)->import($actor, $order, UploadedFile::fake()->createWithContent('rename.csv', "DESIGNATOR,QTY\nM-RJ45-CAT6,$qty\n"),
                ['package_id' => 2, 'order_revision' => 0, 'boq_revision' => $i, 'header_row' => 1]);
        }
        $tables = ['boq_jt' => ['qe_boqs', 'id_boq_jt'], 'boq_items' => ['qe_boq_items', 'id_boq_items'], 'boq_histories' => ['qe_boq_histories', 'id_boq_histories']];
        $before = [];
        foreach ($tables as $new => [$old, $pk]) {
            $before[$new] = DB::table($new)->orderBy($pk)->get()->map(fn ($row) => (array) $row)->all();
        }
        $sourceBefore = $order->fresh()->getRawOriginal();
        $pricesBefore = DB::table('designator_package_prices')->orderBy('id_designator_package_price')->get()->toJson();
        $migration = require database_path('migrations/2026_10_28_000023_rename_boq_tables.php');
        $migration->down();
        foreach ($tables as $new => [$old, $pk]) {
            $this->assertFalse(Schema::hasTable($new));
            $this->assertTrue(Schema::hasTable($old));
        }
        $migration->up();
        // Simulate interruption after the replacement index exists but before the old one is removed.
        Schema::table('boq_jt', fn ($table) => $table->unique('id_work_orders', 'qe_boqs_id_work_orders_unique'));
        $migration->up();
        foreach ($tables as $new => [$old, $pk]) {
            $this->assertFalse(Schema::hasTable($old));
            $this->assertSame($before[$new], DB::table($new)->orderBy($pk)->get()->map(fn ($row) => (array) $row)->all());
            foreach (Schema::getForeignKeys($new) as $foreign) {
                $this->assertStringNotContainsString('qe_boq', $foreign['foreign_table']);
                $this->assertStringNotContainsString('qe_boq', $foreign['name'] ?? '');
            }
            foreach (Schema::getIndexes($new) as $index) {
                $this->assertStringNotContainsString('qe_boq', $index['name']);
            }
        }
        $this->assertSame($sourceBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($pricesBefore, DB::table('designator_package_prices')->orderBy('id_designator_package_price')->get()->toJson());
        $boq = BoqJt::with(['items', 'histories'])->sole();
        $this->assertSame('19500.00', $boq->grand_total);
        $this->assertSame(2, $boq->revision);
        $this->assertCount(2, $boq->histories);
        $this->assertSame(2, $boq->items()->withTrashed()->count());
        $this->actingAs($actor)->get(route('boqs.show', $order))->assertOk()->assertSee('19.500,00');
        $this->get(route('revenue.index'))->assertOk()->assertSee('19.500,00');
    }

    public function test_conflicting_old_and_new_tables_stop_before_altering_existing_schema(): void
    {
        Schema::create('qe_boqs', fn ($table) => $table->id());
        $before = Schema::getForeignKeys('boq_items');
        try {
            try {
                (require database_path('migrations/2026_10_28_000023_rename_boq_tables.php'))->up();
                $this->fail('Conflicting tables must not be overwritten.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Rekonsiliasi', $exception->getMessage());
            }
            $this->assertSame($before, Schema::getForeignKeys('boq_items'));
            $this->assertTrue(Schema::hasTable('qe_boqs'));
            $this->assertTrue(Schema::hasTable('boq_jt'));
        } finally {
            Schema::drop('qe_boqs');
        }
    }
}
