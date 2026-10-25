<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchRegionMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_backfill_matches_names_and_codes_without_guessing_or_changing_existing_data_and_rolls_back(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 16, '--force' => true])->assertSuccessful();
        $user = User::factory()->create();
        foreach (['REGION JATIM', ' jatengDIY ', 'REGION BALNUS', 'UNKNOWN', null] as $offset => $label) {
            DB::table('branches')->insert(['code' => 'B'.$offset, 'name' => 'Branch '.$offset, 'regional' => $label, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-02 00:00:00']);
        }
        $before = DB::table('branches')->orderBy('id_branches')->get()->map(fn ($row) => (array) $row)->all();
        $oldUser = (array) DB::table('users')->where('id_users', $user->getKey())->first();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame([1, 2, 3, null, null], Branch::orderBy('id_branches')->pluck('region_id')->all());
        $this->assertSame($before, DB::table('branches')->select(array_keys($before[0]))->orderBy('id_branches')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($oldUser, (array) DB::table('users')->select(array_keys($oldUser))->where('id_users', $user->getKey())->first());
        $this->assertSame(0, $user->fresh()->management_revision);
        $this->assertSame('JATIM', Branch::where('code', 'B0')->firstOrFail()->region->code);
        $this->assertSame('B0', Region::findOrFail(1)->branches->sole()->code);
        try {
            Branch::where('code', 'B0')->update(['region_id' => 99999]);
            $this->fail('Orphan region accepted.');
        } catch (QueryException) {
            $this->assertSame(1, Branch::where('code', 'B0')->value('region_id'));
        }
        try {
            Region::findOrFail(1)->forceDelete();
            $this->fail('Region with branches deleted.');
        } catch (QueryException) {
            $this->assertNotNull(Region::find(1));
        }
        $this->artisan('migrate:rollback', ['--step' => 16, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('branches', 'region_id'));
        $this->assertFalse(Schema::hasColumn('users', 'management_revision'));
        $this->assertSame($before, DB::table('branches')->orderBy('id_branches')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($oldUser, (array) DB::table('users')->where('id_users', $user->getKey())->first());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}
