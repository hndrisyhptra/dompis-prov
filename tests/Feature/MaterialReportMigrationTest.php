<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\MaterialReportFields;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaterialReportMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_material_migration_has_all_fields_and_rolls_back_without_mutating_existing_tables(): void
    {
        $user = User::factory()->create();
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'MATERIAL-MIGRATION', 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('b', 64), 'progress_category' => 'SURVEY']);
        $before = $order->fresh()->getRawOriginal();
        $userBefore = $user->fresh()->getRawOriginal();
        $this->assertTrue(Schema::hasColumns('material_reports', array_keys(MaterialReportFields::all())));
        $this->assertTrue(Schema::hasColumn('material_reports', 'id_material_reports'));
        $migration = require database_path('migrations/2026_10_25_000021_create_telegram_material_reports.php');
        $migration->down();
        foreach (['material_reports', 'telegram_updates', 'telegram_outbox', 'telegram_identities', 'telegram_chats'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame($userBefore, $user->fresh()->getRawOriginal());
        $migration->up();
        $this->assertDatabaseCount('material_reports', 0);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }
}
