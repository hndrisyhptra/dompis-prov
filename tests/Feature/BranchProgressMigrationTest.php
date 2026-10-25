<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchProgressMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_legacy_data_is_preserved_only_evidenced_completion_dates_are_backfilled_and_rollback_works(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 10, '--force' => true])->assertSuccessful();
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'SAFE', 'name' => 'Branch Uji']);
        foreach (['NEW', 'SURVEY', 'COMPLETED', 'COMPLETED', 'CANCELLED', 'FIELD_DONE'] as $index => $status) {
            DB::table('work_orders')->insert(['source_system' => 'BIMA', 'external_order_id' => 'SAFE-'.$index,
                'id_branches' => $branch->getKey(), 'operational_status' => $status, 'revision' => 7,
                'source_bima_fields' => '{}', 'source_snapshot_hash' => hash('sha256', 'SAFE-'.$index),
                'verified_at' => $index === 2 ? '2026-10-17 17:30:00' : null,
                'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-02 00:00:00']);
        }
        $id = DB::table('work_orders')->orderBy('id_work_orders')->value('id_work_orders');
        $event = ['id_work_orders' => $id, 'id_users' => $user->getKey(), 'old_status' => 'NEW', 'new_status' => 'SURVEY', 'revision' => 7, 'notes' => 'Riwayat asli'];
        DB::table('wo_status_events')->insert($event);
        $issueId = DB::table('wo_issues')->insertGetId(['id_work_orders' => $id, 'id_users' => $user->getKey(), 'category' => 'OTHER', 'description' => 'Detail asli', 'pic' => 'PIC Uji'], 'id_wo_issues');
        $before = [];
        foreach (['work_orders', 'wo_status_events', 'wo_issues', 'users', 'branches'] as $table) {
            $before[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame([null, 'SURVEY', 'CLOSE', 'CLOSE', 'CANCEL', null], DB::table('work_orders')->orderBy('id_work_orders')->pluck('progress_category')->all());
        $dates = DB::table('work_orders')->orderBy('id_work_orders')->pluck('branch_completed_at')->all();
        $this->assertSame([null, null, '2026-10-17 17:30:00', null, null, null], $dates);
        foreach ($before as $table => $rows) {
            $columns = $table === 'work_orders' ? (require database_path('migrations/2026_10_20_000010_rename_work_order_source_columns.php'))::COLUMNS : [];
            $expected = array_map(fn ($row) => collect($row)->mapWithKeys(fn ($value, $field) => [($columns[$field] ?? $field) => $value])->all(), $rows);
            $this->assertSame($expected, DB::table($table)->select(array_keys($expected[0]))->get()->map(fn ($row) => (array) $row)->all());
        }
        DB::table('wo_issue_notes')->insert(['id_wo_issues' => $issueId, 'id_users' => $user->getKey(), 'note' => 'Tindak lanjut']);
        try {
            DB::table('wo_issue_notes')->insert(['id_wo_issues' => 999999, 'id_users' => $user->getKey(), 'note' => 'Orphan']);
            $this->fail('Foreign key must reject an orphan note.');
        } catch (QueryException) {
            $this->assertDatabaseCount('wo_issue_notes', 1);
        }
        $this->artisan('migrate:rollback', ['--step' => 10, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('wo_issue_notes'));
        $this->assertFalse(Schema::hasColumn('work_orders', 'progress_category'));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->map(fn ($row) => (array) $row)->all());
        }
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}
