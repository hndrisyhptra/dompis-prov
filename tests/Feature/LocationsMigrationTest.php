<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_users_and_branches_survive_import_and_rollback_and_only_matching_branch_references_are_linked(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 3, '--force' => true])->assertSuccessful();
        $user = User::factory()->create();
        $branch = new Branch(['code' => 'SDA', 'name' => 'SIDOARJO', 'regional' => 'REGION JATIM']);
        $branch->forceFill(['id_branches' => 2])->save();
        $wrong = new Branch(['code' => 'OTHER', 'name' => 'Unrelated', 'regional' => 'OTHER REGION']);
        $wrong->forceFill(['id_branches' => 3])->save();
        $usersBefore = DB::table('users')->get()->map(fn ($row) => (array) $row)->all();
        $branchesBefore = DB::table('branches')->orderBy('id_branches')->get()->map(fn ($row) => (array) $row)->all();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('regions', 3);
        $this->assertDatabaseCount('service_areas', 392);
        $this->assertSame(29, ServiceArea::where('id_branches', 2)->count());
        $this->assertSame(0, ServiceArea::where('id_branches', 3)->count());
        $this->assertNull($user->fresh()->id_regions);
        $this->assertNull($user->fresh()->id_service_areas);
        $this->assertSame($branchesBefore, DB::table('branches')->select(array_keys($branchesBefore[0]))->orderBy('id_branches')->get()->map(fn ($row) => (array) $row)->all());
        $this->artisan('migrate:rollback', ['--step' => 3, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('regions'));
        $this->assertFalse(Schema::hasTable('service_areas'));
        $this->assertSame($usersBefore, DB::table('users')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($branchesBefore, DB::table('branches')->select(array_keys($branchesBefore[0]))->orderBy('id_branches')->get()->map(fn ($row) => (array) $row)->all());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }
}
