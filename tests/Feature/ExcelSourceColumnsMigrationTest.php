<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExcelSourceColumnsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_source_columns_backfill_preserves_identity_progress_and_timestamps(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 9, '--force' => true])->assertSuccessful();
        $id = DB::table('work_orders')->insertGetId(['source_system' => 'BIMA', 'external_order_id' => '000001', 'progress_category' => 'SURVEY', 'revision' => 9,
            'source_snapshot_hash' => str_repeat('a', 64), 'source_bima_fields' => json_encode(['BW' => '100M', 'SN' => '000SN', 'TASK BIMA' => 'ACTIVATION', 'KETERANGAN' => 'Sumber lama', 'UMUR (HARI)' => '12']), 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00'], 'id_work_orders');
        $before = DB::table('work_orders')->first();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $after = DB::table('work_orders')->first();
        $order = WorkOrder::findOrFail($id);
        foreach (get_object_vars($before) as $field => $value) {
            $columns = (require database_path('migrations/2026_10_20_000010_rename_work_order_source_columns.php'))::COLUMNS;
            $current = $columns[$field] ?? $field;
            if ($field === 'bima_status') {
                $this->assertEquals($value, $after->status_bima, $field);
            } else {
                $this->assertEquals($value, $after->$current, $field);
            }
        }
        $this->assertSame('100M', $order->fresh()->bw);
        $this->assertSame('000SN', $order->fresh()->sn);
        $this->assertNull($order->fresh()->umur_hari);
        $this->assertSame('12', $order->source_bima_fields['UMUR (HARI)']);
        $this->assertFalse(Schema::hasColumn('work_orders', 'source_district'));
        $this->assertTrue(Schema::hasColumn('sync_job_rows', 'payload'));
        $this->artisan('migrate:rollback', ['--step' => 9, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('work_orders', 'bandwidth'));
        $this->assertSame('SURVEY', $order->fresh()->progress_category);
        $this->assertDatabaseCount('work_orders', 1);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}
