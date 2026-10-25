<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SingleBranchMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_second_branch_is_archived_and_removed_without_changing_operational_data_and_can_be_restored(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 12, '--force' => true])->assertSuccessful();
        foreach (['DISTRICT LAMA', '', null] as $i => $district) {
            DB::table('work_orders')->insert(['source_system' => 'BIMA', 'external_order_id' => '000'.$i, 'source_branch_name' => 'BRANCH UJI', 'source_district' => $district,
                'source_bima_fields' => json_encode(['BRANCH' => 'BRANCH UJI', 'SN' => '000SN']), 'source_snapshot_hash' => str_repeat('a', 64),
                'progress_category' => 'SURVEY', 'revision' => 9, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-02 00:00:00']);
        }
        $before = DB::table('work_orders')->orderBy('id_work_orders')->get();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('work_orders', 'source_district'));
        foreach ($before as $old) {
            $row = DB::table('work_orders')->where('id_work_orders', $old->id_work_orders)->first();
            foreach ((array) $row as $field => $value) {
                if ($field !== 'source_bima_fields') {
                    $columns = array_flip((require database_path('migrations/2026_10_20_000010_rename_work_order_source_columns.php'))::COLUMNS);
                    $legacy = $columns[$field] ?? $field;
                    if (property_exists($old, $legacy)) {
                        $this->assertSame($old->$legacy, $value, $field);
                    } else {
                        $this->assertNull($value, $field);
                    }
                }
            }
            $source = json_decode($row->source_bima_fields, true);
            $this->assertSame('BRANCH UJI', $source['BRANCH']);
            $this->assertSame('000SN', $source['SN']);
            if ($old->source_district !== null) {
                $this->assertSame($old->source_district, $source['_legacy_source_district']);
            }
        }
        $this->artisan('migrate:rollback', ['--step' => 12, '--force' => true])->assertSuccessful();
        $this->assertSame(['DISTRICT LAMA', '', null], DB::table('work_orders')->orderBy('id_work_orders')->pluck('source_district')->all());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('work_orders', 3);
    }
}
