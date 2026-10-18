<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NamedKeysMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_populated_migration_preserves_ids_relationships_snapshots_and_internal_tables_in_both_directions(): void
    {
        $migration = require database_path('migrations/2026_10_18_000004_name_table_primary_and_foreign_keys.php');
        $migration->down();
        $internal = [];
        foreach (['migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'password_reset_tokens'] as $table) {
            $internal[$table] = [Schema::getColumns($table), Schema::getIndexes($table), Schema::getForeignKeys($table)];
        }
        $password = Hash::make('migration-test-password');
        $snapshot = Crypt::encryptString(json_encode(['2' => ['branch_id' => 201, 'expected_hash' => null, 'data' => []]]));
        DB::table('users')->insert(['id' => 101, 'name' => 'Migration User', 'email' => 'migration@example.test', 'username' => 'migration-test', 'password' => $password, 'role' => Role::AreaHd->value, 'active' => true]);
        DB::table('branches')->insert(['id' => 201, 'code' => 'MIG', 'name' => 'Migration Branch']);
        DB::table('user_branches')->insert(['user_id' => 101, 'branch_id' => 201]);
        DB::table('branch_mappings')->insert(['id' => 301, 'bima_branch' => 'MIGRATION', 'branch_id' => 201]);
        DB::table('work_orders')->insert(['id' => 401, 'external_order_id' => '000123', 'branch_id' => 201, 'source_bima_fields' => '{}', 'source_snapshot_hash' => str_repeat('a', 64), 'operational_status' => 'IN_PROGRESS', 'revision' => 7]);
        DB::table('sync_jobs')->insert(['id' => 501, 'idempotency_key' => '00000000-0000-4000-8000-000000000501', 'actor_id' => 101, 'direction' => 'pull', 'state' => 'preview_ready', 'connection_hash' => str_repeat('b', 64), 'snapshot' => $snapshot]);
        DB::table('sync_job_rows')->insert(['id' => 601, 'sync_job_id' => 501, 'row_index' => 2, 'external_key' => '000123', 'action' => 'new']);
        DB::table('audit_logs')->insert(['id' => 701, 'actor_id' => 101, 'action' => 'migration.fixture', 'entity_type' => 'work_order', 'entity_id' => 401, 'metadata' => '{"branch_id":201,"sync_job_id":501}']);
        DB::table('wo_status_events')->insert(['id' => 801, 'work_order_id' => 401, 'actor_id' => 101, 'old_status' => 'NEW', 'new_status' => 'IN_PROGRESS', 'revision' => 7, 'notes' => 'Keep history']);
        DB::table('wo_issues')->insert(['id' => 901, 'work_order_id' => 401, 'actor_id' => 101, 'category' => 'ACCESS', 'description' => 'Keep issue', 'pic' => 'PIC']);
        DB::table('sessions')->insert(['id' => 'legacy-session', 'user_id' => 101, 'payload' => 'unchanged', 'last_activity' => time()]);
        $tables = ['users', 'branches', 'user_branches', 'branch_mappings', 'work_orders', 'sync_jobs', 'sync_job_rows', 'audit_logs', 'wo_status_events', 'wo_issues'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = (array) DB::table($table)->first();
        }

        $migration->up();
        $keys = ['user_id' => 'id_users', 'actor_id' => 'id_users', 'branch_id' => 'id_branches', 'work_order_id' => 'id_work_orders', 'sync_job_id' => 'id_sync_jobs', 'entity_id' => 'id_entity'];
        foreach ($tables as $table) {
            $expected = [];
            foreach ($before[$table] as $key => $value) {
                $expected[$key === 'id' ? 'id_'.$table : ($keys[$key] ?? $key)] = $value;
            }
            $after = (array) DB::table($table)->first();
            if ($table === 'user_branches') {
                $this->assertGreaterThan(0, $after['id_user_branches']);
                unset($after['id_user_branches']);
            }
            $this->assertEquals($expected, $after, $table.' data preserved');
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame(['id_'.$table], $primary['columns']);
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $this->assertSame([$table === 'branches' && $foreign['foreign_table'] === 'regions' ? 'region_id' : 'id_'.$foreign['foreign_table']], $foreign['columns']);
                $this->assertSame(['id_'.$foreign['foreign_table']], $foreign['foreign_columns']);
            }
        }
        foreach ($internal as $table => $definition) {
            $this->assertEquals($definition, [Schema::getColumns($table), Schema::getIndexes($table), Schema::getForeignKeys($table)], $table.' schema unchanged');
        }
        $this->assertDatabaseHas('sessions', ['id' => 'legacy-session', 'user_id' => 101, 'payload' => 'unchanged']);
        $this->assertSame(101, User::findOrFail(101)->getAuthIdentifier());
        $this->assertSame(201, User::findOrFail(101)->branches->sole()->getKey());
        $this->assertSame(201, BranchMapping::findOrFail(301)->branch->getKey());
        $this->assertSame(101, SyncJob::findOrFail(501)->actor->getKey());
        $this->assertSame(601, SyncJob::findOrFail(501)->rows->sole()->getKey());
        $order = WorkOrder::findOrFail(401);
        $this->assertSame(201, $order->branch->getKey());
        $this->assertSame(801, $order->events->sole()->getKey());
        $this->assertSame(901, $order->issues->sole()->getKey());
        $this->assertSame($snapshot, DB::table('sync_jobs')->value('snapshot'));
        $this->assertSame(201, SyncJob::findOrFail(501)->snapshot[2]['branch_id']);
        $this->assertGreaterThan(201, Branch::create(['code' => 'NEXT', 'name' => 'Next Branch'])->getKey());
        Branch::where('code', 'NEXT')->forceDelete();

        $migration->down();
        foreach ($before as $table => $row) {
            $this->assertEquals($row, (array) DB::table($table)->first(), $table.' rollback preserved');
        }
        $migration->up();
        $this->assertSame(7, WorkOrder::findOrFail(401)->revision);
    }

    public function test_renamed_foreign_keys_and_membership_uniqueness_remain_enforced(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'REL', 'name' => 'Relational Branch']);
        $user->branches()->attach($branch);
        $this->assertDatabaseHas('user_branches', ['id_users' => $user->getKey(), 'id_branches' => $branch->getKey()]);
        try {
            DB::table('user_branches')->insert(['id_users' => $user->getKey(), 'id_branches' => $branch->getKey()]);
            $this->fail('Duplicate branch membership accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('user_branches', 1);
        }
        try {
            BranchMapping::create(['bima_branch' => 'BAD', 'id_branches' => 999999]);
            $this->fail('Orphan foreign key accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('branch_mappings', 0);
        }
        try {
            $branch->forceDelete();
            $this->fail('Referenced branch deletion accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('branches', 1);
        }
        $user->delete();
        $this->assertDatabaseCount('user_branches', 0);
    }
}
