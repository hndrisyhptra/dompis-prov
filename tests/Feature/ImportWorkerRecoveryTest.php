<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\MappingOrder;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\BimaWorkOrdersTemplate;
use App\Services\ExcelWorkOrderImportService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;

class ImportWorkerRecoveryTest extends OloTestCase
{
    private function upload(): SyncJob
    {
        // Deliberately put Date Modified last: recovery must keep header-based mapping.
        $headers = ['Date Created', 'ORDER ID', 'Service No.', 'Description', 'CRM Order Type', 'Owner Group', 'Status',
            'Product Name', 'Address', 'Customer Name', 'Workzone', 'District TIF', 'Regional TIF', 'Date Modified'];
        $file = XlsxFile::make($headers, [['2026-07-15', 'RECOVER-001', '000SID', 'Approval E2E Testing Manual', 'NEW INSTALL', 'DISTRICT', 'COMPLETE',
            'METRO', 'Alamat sintetis', 'Pelanggan sintetis', 'TST', 'BRANCH UJI', 'REGION UJI', '2026-10-26 08:12:34.123']]);
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])
                ->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::latest('id_sync_jobs')->firstOrFail();
        $this->get(route('imports.mapping', $job))->assertOk()->assertViewHas('mapping', BimaWorkOrdersTemplate::mapping($headers));
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => BimaWorkOrdersTemplate::mapping($headers)])
            ->assertSessionHasNoErrors()->assertRedirect();

        return $job->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        MappingOrder::create(['status_order' => 'Approval E2E Testing Manual-COMPLETE', 'mapping_order' => 'PS']);
    }

    public function test_current_upload_retries_after_stale_worker_failure_without_reupload_or_unconfirmed_writes(): void
    {
        $job = $this->upload();
        $raw = $job->rows()->sole()->payload['raw'];
        $job->update(['state' => 'failed', 'error' => 'Versi import berubah. Upload ulang file.', 'finished_at' => now(), 'expires_at' => now()->subMinute()]);
        $this->get(route('sync.show', $job))->assertOk()->assertSee('Worker antrean perlu dimuat ulang.')->assertSee('Coba ulang preview')
            ->assertDontSee('Versi import berubah. Upload ulang file.');
        $this->post(route('sync.retry', $job))->assertRedirect(route('sync.show', $job));
        $this->post(route('sync.retry', $job))->assertConflict();
        Queue::assertPushed(ProcessWorkOrderImport::class, 2);
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $job->refresh();
        $this->assertSame('preview_ready', $job->state);
        $this->assertTrue($job->expires_at->isFuture());
        $this->assertSame($raw, $job->rows()->sole()->payload['raw']);
        $this->assertSame('2026-10-26', $job->rows()->sole()->payload['prepared']['data']['tgl_ps']);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sync.retry', 'id_entity' => $job->getKey()]);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('success', $job->fresh()->state);
        $this->assertSame('2026-10-26', WorkOrder::sole()->tgl_ps->toDateString());
        $this->assertSame('CLOSE', WorkOrder::sole()->progress_category);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertDatabaseCount('work_orders', 1);
    }

    public function test_retry_requires_active_sync_permission_and_original_operator(): void
    {
        $job = $this->upload();
        $job->update(['state' => 'failed', 'error' => 'Versi import berubah. Upload ulang file.']);
        foreach ([Role::AreaHd, Role::BranchAdmin] as $role) {
            $this->actingAs($this->user($role))->post(route('sync.retry', $job))->assertForbidden();
        }
        $this->area->forceFill(['active' => false])->save();
        $this->actingAs($this->area)->post(route('sync.retry', $job))->assertForbidden();
        $this->assertSame('failed', $job->fresh()->state);
        Queue::assertPushed(ProcessWorkOrderImport::class, 1);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_old_contract_remains_blocked_in_retry_and_worker_including_commit(): void
    {
        $job = $this->upload();
        $job->update(['connection_hash' => hash('sha256', 'olo-work-order-xlsx-v4-task-status'), 'state' => 'failed']);
        $this->get(route('sync.show', $job))->assertOk()->assertSee('untuk memakai struktur kolom terbaru')->assertDontSee('Coba ulang preview');
        $this->post(route('sync.retry', $job))->assertConflict();
        foreach (['preview', 'commit'] as $stage) {
            $job->update(['stage' => $stage, 'state' => 'queued']);
            (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
            $this->assertSame('failed', $job->fresh()->state);
            $this->assertStringContainsString('Muat ulang worker antrean', $job->fresh()->error);
        }
        $this->assertDatabaseCount('work_orders', 0);
    }
}
