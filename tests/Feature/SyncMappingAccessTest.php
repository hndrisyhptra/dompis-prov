<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessSheetSync;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\BimaRowMapper;
use App\Services\BimaSheetPullService;
use App\Services\ExcelWorkOrderImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\Fakes\XlsxFile;

class SyncMappingAccessTest extends OloTestCase
{
    private PDO $pdo;

    private bool $stringifyFetches;

    private const MAPPING = ['no_tq_sc_id' => 1, 'branch' => 2, 'sto' => 3, 'task_bima' => 4, 'status_bima' => 5];

    protected function setUp(): void
    {
        parent::setUp();
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $this->pdo = DB::connection()->getPdo();
        $this->stringifyFetches = $this->pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    }

    protected function tearDown(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $this->stringifyFetches);
        parent::tearDown();
    }

    private function upload(?string $key = null): SyncJob
    {
        $file = XlsxFile::make(['ORDER ID', 'District TIF', 'Workzone', 'Description', 'Status'], [
            ['STRING-ID-001', 'BRANCH UJI', 'TST', 'Site-Survey Manual', 'STARTWORK'],
        ]);
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => $key ?? (string) Str::uuid()])
                ->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }

        return SyncJob::latest('id_sync_jobs')->firstOrFail();
    }

    public function test_original_operator_can_resume_and_complete_import_when_database_returns_string_ids(): void
    {
        $key = (string) Str::uuid();
        $job = $this->upload($key);
        $this->assertSame((string) $this->area->getKey(), DB::table('sync_jobs')->where('id_sync_jobs', $job->getKey())->value('id_users'));
        $this->assertSame('awaiting_mapping', $job->state);
        Queue::assertNothingPushed();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('awaiting_mapping', $job->fresh()->state);
        $this->assertDatabaseCount('work_orders', 0);
        $this->get(route('sync.show', $job))->assertRedirect(route('imports.mapping', $job));
        $this->get(route('imports.mapping', $job))->assertOk()->assertSee('Mapping import Excel');
        $this->upload($key);
        $this->assertDatabaseCount('sync_jobs', 1);
        $this->assertDatabaseCount('sync_job_rows', 1);
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => self::MAPPING])->assertRedirect();
        Queue::assertPushed(ProcessWorkOrderImport::class, 1);
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('preview_ready', $job->fresh()->state);
        $this->assertDatabaseCount('work_orders', 0);
        $this->get(route('sync.show', $job))->assertOk()->assertSee('Konfirmasi &amp; import ke MySQL', false);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('success', $job->fresh()->state);
        $this->assertSame('STRING-ID-001', WorkOrder::sole()->no_tq_sc_id);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        Queue::assertPushed(ProcessWorkOrderImport::class, 2);
        $this->assertDatabaseCount('work_orders', 1);
    }

    public function test_other_operator_reads_pending_history_but_cannot_take_over_mapping_or_mutations(): void
    {
        $job = $this->upload();
        $before = $job->getRawOriginal();
        $this->actingAs($this->user(Role::SuperAdmin));
        $this->get(route('sync.show', $job))->assertOk()->assertSee('Menunggu konfirmasi kolom oleh pengunggah')
            ->assertDontSee('name="confirmed_mapping"', false);
        $this->getJson(route('sync.show', $job))->assertOk()->assertJsonPath('state', 'awaiting_mapping')->assertJsonMissingPath('snapshot');
        $this->get(route('imports.mapping', $job))->assertForbidden();
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => self::MAPPING])->assertForbidden();
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertForbidden();
        $this->post(route('sync.retry', $job))->assertForbidden();
        $this->assertSame($before, $job->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
        foreach ([Role::BranchAdmin, Role::AreaCoordinator] as $role) {
            $this->actingAs($this->user($role))->get(route('sync.show', $job))->assertForbidden();
            $this->get(route('imports.mapping', $job))->assertForbidden();
        }
    }

    public function test_retry_and_legacy_preview_replay_keep_original_operator_checks_with_string_ids(): void
    {
        $job = $this->upload();
        $job->update(['stage' => 'preview', 'state' => 'failed', 'error' => 'Synthetic failure']);
        $this->get(route('sync.show', $job))->assertOk()->assertSee('Coba ulang preview');
        $this->post(route('sync.retry', $job))->assertRedirect();
        Queue::assertPushed(ProcessWorkOrderImport::class, 1);

        config(['olo.inbound' => 'sheets']);
        $key = (string) Str::uuid();
        $this->post(route('sync.preview'), ['idempotency_key' => $key])->assertRedirect();
        $this->post(route('sync.preview'), ['idempotency_key' => $key])->assertRedirect();
        Queue::assertPushed(ProcessSheetSync::class, 1);
        $pull = SyncJob::where('idempotency_key', $key)->firstOrFail();
        $this->sheets->rows = [BimaRowMapper::HEADERS, $this->row()];
        app(BimaSheetPullService::class)->preview($pull);
        $this->get(route('sync.show', $pull))->assertOk()->assertSee('Konfirmasi &amp; tarik ke MySQL', false);
        $this->post(route('sync.commit', $pull), ['confirmed' => 1])->assertRedirect();
        Queue::assertPushed(ProcessSheetSync::class, 2);
        $this->actingAs($this->user(Role::AreaAdmin))->post(route('sync.commit', $pull), ['confirmed' => 1])->assertForbidden();
    }
}
