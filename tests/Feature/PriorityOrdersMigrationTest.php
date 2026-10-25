<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriorityOrdersMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_is_additive_reversible_and_unique_without_modifying_existing_orders(): void
    {
        $actor = User::factory()->create();
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'PRIORITY-MIGRATION', 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64), 'progress_category' => 'SURVEY']);
        $before = $order->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_24_000016_create_work_order_priorities.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('work_order_priorities'));
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $migration->up();
        $this->assertTrue(Schema::hasColumn('work_order_priorities', 'id_work_order_priorities'));
        $this->assertDatabaseCount('work_order_priorities', 0);
        $values = ['id_work_orders' => $order->getKey(), 'id_users' => $actor->getKey(), 'scope_key' => 'area', 'selection_token' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('work_order_priorities')->insert($values);
        try {
            DB::table('work_order_priorities')->insert(array_replace($values, ['selection_token' => (string) Str::uuid()]));
            $this->fail('Duplicate scope must be rejected by database');
        } catch (QueryException) {
            $this->assertDatabaseCount('work_order_priorities', 1);
        }
        try {
            DB::table('work_orders')->where('id_work_orders', $order->getKey())->delete();
            $this->fail('Referenced work order must be protected');
        } catch (QueryException) {
            $this->assertSame($before, $order->fresh()->getRawOriginal());
        }
        $migration->down();
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertNotNull($actor->fresh());
        $migration->up();
    }
}
