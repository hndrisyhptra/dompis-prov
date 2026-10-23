<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkOrderColumnNamesMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_physical_column_names_match_the_requested_list_and_populated_rename_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_10_20_000010_rename_work_order_source_columns.php');
        $logEvolution = require database_path('migrations/2026_10_20_000013_add_bima_log_field_changes.php');
        $logEvolution->down();
        $evolution = require database_path('migrations/2026_10_20_000011_add_bima_status_task_log_and_mapping.php');
        $evolution->down();
        $migration->down();
        $branch = Branch::create(['code' => 'RENAME', 'name' => 'Cabang Uji']);
        $user = User::factory()->create();
        $values = ['2026-10-01', 'OKTOBER', '2026', 'REGIONAL UJI', 'CABANG UJI', 'TST', 'Case-WO', 'Nama Uji', 'Alamat Uji', 'METRO', 'NEW INSTALL', '100M', 'COMPLETE', 'ACTIVATION', '2026-10-20', '000SID', 'Catatan sumber', 'ODP-UJI', '000SN', 'OLT-UJI', '0/1', 'METRO-UJI', '0/2', 'CLOSE', 'OKTOBER', '1', '19', '15-30'];
        $data = array_combine(array_keys($migration::COLUMNS), $values) + [
            'source_system' => 'BIMA', 'id_branches' => $branch->getKey(), 'source_bima_fields' => '{"SID":"000SID"}', 'source_snapshot_hash' => str_repeat('a', 64),
            'progress_category' => 'SURVEY', 'operational_status' => 'SURVEY', 'revision' => 7, 'verification_status' => 'UNVERIFIED',
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-02 00:00:00', 'last_progress_at' => '2026-10-02 00:00:00',
        ];
        $id = DB::table('work_orders')->insertGetId($data, 'id_work_orders');
        DB::table('work_orders')->insert(array_replace($data, ['external_order_id' => 'case-WO']));
        DB::table('wo_status_events')->insert(['id_work_orders' => $id, 'id_users' => $user->getKey(), 'old_status' => 'NEW', 'new_status' => 'SURVEY', 'revision' => 7, 'notes' => 'Catatan tetap']);
        DB::table('wo_issues')->insert(['id_work_orders' => $id, 'id_users' => $user->getKey(), 'category' => 'OTHER', 'description' => 'Kendala tetap', 'pic' => 'PIC Uji']);
        $before = (array) DB::table('work_orders')->where('id_work_orders', $id)->first();
        $types = collect(Schema::getColumns('work_orders'))->keyBy('name');
        $migration->up();
        $after = (array) DB::table('work_orders')->where('id_work_orders', $id)->first();
        $newTypes = collect(Schema::getColumns('work_orders'))->keyBy('name');
        foreach ($before as $old => $value) {
            $new = $migration::COLUMNS[$old] ?? $old;
            $this->assertSame($value, $after[$new], $new.' value preserved');
            $this->assertSame($types[$old]['type'], $newTypes[$new]['type'], $new.' type preserved');
            $this->assertSame($types[$old]['nullable'], $newTypes[$new]['nullable'], $new.' nullable preserved');
        }
        foreach ($migration::COLUMNS as $old => $new) {
            $this->assertTrue(Schema::hasColumn('work_orders', $new), $new);
            if ($old !== $new) {
                $this->assertFalse(Schema::hasColumn('work_orders', $old), $old);
            }
        }
        $this->assertSame(['tgl_order', 'bulan', 'tahun', 'regional', 'branch', 'sto', 'no_tq_sc_id', 'nama_olo', 'alamat', 'layanan', 'jenis_pekerjaan', 'bw', 'status_order', 'task_bima', 'tgl_ps', 'sid', 'keterangan', 'odp', 'sn', 'olt', 'port_olt', 'metro', 'port_metro', 'status_final', 'bulan_ps', 'ps_hari_ini', 'umur_hari', 'kategori_umur'], array_values($migration::COLUMNS));
        $this->assertTrue(collect(Schema::getIndexes('work_orders'))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['source_system', 'no_tq_sc_id']));
        $this->assertDatabaseHas('wo_status_events', ['id_work_orders' => $id, 'revision' => 7]);
        $this->assertDatabaseHas('wo_issues', ['id_work_orders' => $id, 'description' => 'Kendala tetap']);
        try {
            DB::table('work_orders')->insert(collect($data)->mapWithKeys(fn ($value, $field) => [($migration::COLUMNS[$field] ?? $field) => $value])->all());
            $this->fail('Duplicate source identity was accepted');
        } catch (QueryException) {
            $this->assertDatabaseCount('work_orders', 2);
        }
        $migration->down();
        $this->assertSame($before, (array) DB::table('work_orders')->where('id_work_orders', $id)->first());
        $migration->up();
        $evolution->up();
        $logEvolution->up();
    }
}
