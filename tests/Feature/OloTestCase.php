<?php

namespace Tests\Feature;

use App\Contracts\GoogleSheetGateway;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\BimaRowMapper;
use App\Services\BimaSheetPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\SheetGateway;
use Tests\TestCase;

abstract class OloTestCase extends TestCase
{
    use RefreshDatabase;

    protected SheetGateway $sheets;

    protected User $area;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheets = new SheetGateway([BimaRowMapper::HEADERS]);
        $this->app->instance(GoogleSheetGateway::class, $this->sheets);
        $this->area = $this->user(Role::AreaHd);
        $this->branch = Branch::create(['code' => 'TEST', 'name' => 'Branch Uji']);
        BranchMapping::create(['bima_branch' => 'BRANCH UJI', 'id_branches' => $this->branch->getKey()]);
    }

    protected function user(Role $role = Role::BranchAdmin, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'active' => $active])->save();

        return $user;
    }

    protected function row(string $id = '000123', array $changes = []): array
    {
        $row = array_fill(0, 32, '');
        foreach ([0 => '1', 1 => '2026-10-17', 4 => 'AREA UJI', 5 => 'BRANCH UJI', 6 => 'TST', 7 => $id,
            8 => 'Pelanggan Uji', 9 => 'Alamat Uji', 10 => 'METRO', 11 => 'NEW INSTALL', 13 => 'OPEN', 16 => 'SID-0099', 19 => '000SN', 26 => 'OPEN'] + $changes as $column => $value) {
            $row[$column] = $value;
        }
        foreach ($changes as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }

    protected function preview(array $rows): SyncJob
    {
        $this->sheets->rows = [BimaRowMapper::HEADERS, ...$rows];
        $job = SyncJob::create(['idempotency_key' => Str::uuid(), 'id_users' => $this->area->getKey(), 'direction' => 'pull', 'connection_hash' => BimaSheetPullService::connectionHash()]);
        app(BimaSheetPullService::class)->preview($job);

        return $job->fresh();
    }

    protected function pull(array $rows): SyncJob
    {
        $job = $this->preview($rows);
        $job->update(['stage' => 'commit']);
        app(BimaSheetPullService::class)->pull($job);

        return $job->fresh();
    }
}
