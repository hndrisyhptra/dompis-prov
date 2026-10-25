<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\BimaAutoProgressService;
use App\Services\ExcelWorkOrderImportService;
use App\Services\ExcelWorkOrderMapper;
use App\Support\BimaProgressMapping;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\XlsxFile;

class BimaAutoProgressTest extends OloTestCase
{
    private const HEADERS = ['Date Created', 'Date Modified', 'Workorder', 'ORDER', 'SC Order No/Track ID/CSRM No', 'OSS Order ID', 'Service No.', 'Description', 'CRM Order Type', 'Owner Group', 'Status', 'Product Name', 'Address', 'witel', 'Customer Name', 'Workzone', 'Region/Site ID', 'Status Date', 'Sched Start', 'Contact Number', 'Measurement', 'Measurement Date', 'Measurement Result', 'WO Class', 'No. Kontrak(KB/KL/P8)', 'Product Type', 'Booking Date', 'Area TIF', 'District TIF', 'Regional TIF'];

    private function excelRow(string $id, string $statusOrder): array
    {
        $separator = strrpos($statusOrder, '-');

        return array_replace(array_fill(0, 30, ''), [0 => '2026-10-20 10:20:30.123', 3 => $id, 6 => 'SID-'.$id,
            7 => substr($statusOrder, 0, $separator), 8 => 'NEW INSTALL', 9 => 'OLO TEST', 10 => substr($statusOrder, $separator + 1),
            11 => 'METRO', 12 => 'Alamat fiktif', 14 => 'Pelanggan fiktif', 15 => 'TST', 28 => 'BRANCH UJI', 29 => 'REGION UJI']);
    }

    private function excelPreview(array $rows): SyncJob
    {
        // Exercise the real upload throttle without sending repeated fixtures in one minute.
        $this->travel(1)->minutes();
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $file = XlsxFile::make(self::HEADERS, $rows, 'Work Orders');
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])
                ->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::latest('id_sync_jobs')->firstOrFail();
        $mapping = (new ExcelWorkOrderMapper)->suggest(self::HEADERS);
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => $mapping])->assertSessionHasNoErrors()->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('preview_ready', $job->fresh()->state);

        return $job->fresh();
    }

    private function excelCommit(SyncJob $job): SyncJob
    {
        $this->actingAs($this->area)->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('success', $job->fresh()->state);

        return $job->fresh();
    }

    public function test_all_59_exact_statuses_apply_only_after_excel_confirmation_with_events_and_audit(): void
    {
        $statuses = [...BimaProgressMapping::SURVEY, ...BimaProgressMapping::INSTALASI];
        $rows = array_map(fn ($status, $index) => $this->excelRow('AUTO-'.$index, $status), $statuses, array_keys($statuses));
        $job = $this->excelPreview($rows);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->get(route('sync.show', $job))->assertOk()->assertSee('SURVEY')->assertSee('INSTALASI')->assertSee('Riwayat progress Branch tetap tersimpan');
        $this->excelCommit($job);
        $this->assertSame(59, $job->fresh()->stats['inserted']);
        foreach ($statuses as $i => $status) {
            $order = WorkOrder::where('no_tq_sc_id', 'AUTO-'.$i)->sole();
            $expected = $i < 22 ? 'SURVEY' : 'INSTALASI';
            $this->assertSame($status, $order->status_order);
            $this->assertSame($expected, $order->progress_category, $status);
            $this->assertSame($i < 22 ? 'SURVEY' : 'IN_PROGRESS', $order->operational_status);
            $this->assertSame(1, $order->revision);
            $this->assertSame($this->branch->getKey(), $order->id_branches);
            $this->assertNull($order->tgl_ps);
            $this->assertNull($order->branch_completed_at);
            $this->assertNull($order->field_done_at);
            $this->assertFalse($order->isPsEligible());
            $event = $order->events()->sole();
            $this->assertSame($this->area->getKey(), $event->id_users);
            $this->assertNull($event->old_progress_category);
            $this->assertSame($expected, $event->new_progress_category);
            $this->assertStringContainsString($status, $event->notes);
            $this->assertNotNull($event->created_at);
        }
        $this->assertDatabaseCount('wo_status_events', 59);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->assertSame(59, AuditLog::where('action', 'progress.auto_mapped_from_bima')->count());
        $this->assertSame(0, $this->sheets->reads);
    }

    public function test_changed_and_identical_excel_preserve_branch_history_issue_notes_devices_and_stale_edit_protection(): void
    {
        $survey = $this->excelRow('HISTORY', 'Site-Survey Manual-COMPLETE');
        $this->excelCommit($this->excelPreview([$survey]));
        $order = WorkOrder::sole();
        $branchActor = $this->user(Role::BranchHd);
        $branchActor->branches()->attach($this->branch);
        $progressUrl = route('work-orders.action.update', [$order, 'progress']);
        $issueUrl = route('work-orders.action.update', [$order, 'issue']);
        $this->actingAs($branchActor)->patch($progressUrl, ['revision' => 1, 'progress_category' => 'JT', 'notes' => 'Branch menjadwalkan pekerjaan.'])->assertSessionHasNoErrors();
        $this->patch($issueUrl, ['revision' => 2, 'progress_category' => 'HOLD', 'notes' => 'Akses gedung belum tersedia.'])->assertSessionHasNoErrors();
        $this->patch($issueUrl, ['revision' => 3, 'progress_category' => 'HOLD', 'notes' => 'PIC sedang mengurus izin akses.'])->assertSessionHasNoErrors();
        $order->refresh()->update(['odp' => 'ODP-LOCAL', 'sn' => 'SN-LOCAL', 'device_overrides' => ['odp', 'sn']]);
        $history = $order->events()->get()->mapWithKeys(fn ($event) => [$event->getKey() => $event->getAttributes()])->all();
        $issue = $order->issues()->sole();
        $issueBefore = $issue->getAttributes();
        $note = $issue->notes()->sole();
        $noteBefore = $note->getAttributes();
        $hash = $order->source_snapshot_hash;

        $this->travel(2)->minutes();
        $install = $this->excelRow('HISTORY', 'Install NTE Manual-STARTWORK');
        $job = $this->excelPreview([$install]);
        $this->assertSame('HOLD', $order->fresh()->progress_category);
        $this->assertSame(4, $order->fresh()->revision);
        $this->excelCommit($job);
        $order->refresh();
        $this->assertSame('INSTALASI', $order->progress_category);
        $this->assertSame(5, $order->revision);
        $this->assertNotSame($hash, $order->source_snapshot_hash);
        $this->assertSame('ODP-LOCAL', $order->odp);
        $this->assertSame('SN-LOCAL', $order->sn);
        $this->assertEquals($issueBefore, $issue->fresh()->getAttributes());
        $this->assertEquals($noteBefore, $note->fresh()->getAttributes());
        foreach ($history as $id => $attributes) {
            $this->assertEquals($attributes, $order->events()->findOrFail($id)->getAttributes());
        }
        $this->assertSame('HOLD', $order->events()->first()->old_progress_category);
        $this->assertSame('INSTALASI', $order->events()->first()->new_progress_category);
        $this->assertSame('Site-Survey Manual-COMPLETE', $order->taskLogs()->sole()->old_status_order);
        $this->assertSame('Install NTE Manual-STARTWORK', $order->taskLogs()->sole()->new_status_order);
        $audit = AuditLog::where('action', 'progress.auto_mapped_from_bima')->latest('id_audit_logs')->firstOrFail();
        $this->assertSame(1, $audit->metadata['open_issues_preserved']);
        $this->assertSame($job->getKey(), $audit->metadata['id_sync_jobs']);
        $this->actingAs($branchActor)->get(route('work-orders.show', $order))->assertOk()
            ->assertSee('Branch menjadwalkan pekerjaan.')->assertSee('Akses gedung belum tersedia.')->assertSee('PIC sedang mengurus izin akses.')
            ->assertSee('Penyesuaian otomatis INSTALASI')->assertSee($order->events()->first()->created_at->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB');
        $this->patch($progressUrl, ['revision' => 4, 'progress_category' => 'JT', 'notes' => 'Form lama.'])->assertConflict();

        // A retry of the same job and another identical file add no duplicate progress/task events.
        app(ExcelWorkOrderImportService::class)->commit($job->fresh());
        $before = $order->fresh()->getAttributes();
        $this->travel(1)->minutes();
        $same = $this->excelCommit($this->excelPreview([$install]));
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertEquals($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('wo_status_events', 5);
        $this->assertDatabaseCount('bima_task_logs', 1);

        // A later Branch update is retained in history even when an identical source maps it again.
        $this->actingAs($branchActor)->patch($progressUrl, ['revision' => 5, 'progress_category' => 'JT', 'notes' => 'Branch meminta penjadwalan ulang.'])->assertSessionHasNoErrors();
        $hash = $order->fresh()->source_snapshot_hash;
        $same = $this->excelCommit($this->excelPreview([$install]));
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertSame('INSTALASI', $order->fresh()->progress_category);
        $this->assertSame(7, $order->fresh()->revision);
        $this->assertSame($hash, $order->fresh()->source_snapshot_hash);
        $this->assertSame('Branch meminta penjadwalan ulang.', $order->events()->where('revision', 6)->sole()->notes);
        $this->assertDatabaseCount('wo_status_events', 7);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->assertEquals($noteBefore, $note->fresh()->getAttributes());
        $this->excelCommit($this->excelPreview([$this->excelRow('HISTORY', 'Activate Service-STARTWORK')]));
        $this->assertDatabaseCount('wo_status_events', 7);
        $this->assertDatabaseCount('bima_task_logs', 2);
        $this->excelCommit($this->excelPreview([$this->excelRow('HISTORY', 'Resource Allocation-STARTWORK')]));
        $this->assertSame('SURVEY', $order->fresh()->progress_category);
        $this->assertSame(8, $order->fresh()->revision);
        $this->assertDatabaseCount('wo_status_events', 8);
        $this->assertDatabaseCount('bima_task_logs', 3);
    }

    public function test_terminal_orders_are_not_reopened_and_unknown_status_preserves_branch_progress(): void
    {
        foreach (['CLOSE' => 'FIELD_DONE', 'CANCEL' => 'CANCELLED', 'CLOSE ON DESK' => 'PENDING_VALIDATION', 'JT' => 'COMPLETED'] as $category => $status) {
            foreach (['Approval Survey-COMPLETE', 'Activate Global-COMPLETE'] as $source) {
                $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $category.$source, 'status_order' => $source,
                    'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $category.$source),
                    'task_bima' => substr($source, 0, strrpos($source, '-')), 'status_bima' => 'COMPLETE',
                    'progress_category' => $category, 'operational_status' => $status, 'revision' => 4]);
                $before = $order->fresh()->getAttributes();
                $this->assertFalse(app(BimaAutoProgressService::class)->apply($order, $this->area));
                $this->assertEquals($before, $order->fresh()->getAttributes());
            }
        }
        $row = $this->excelRow('UNKNOWN', 'Activate Service-COMPLETE');
        $this->excelCommit($this->excelPreview([$row]));
        $order = WorkOrder::where('no_tq_sc_id', 'UNKNOWN')->sole();
        $order->update(['progress_category' => 'JT', 'operational_status' => 'IN_PROGRESS', 'revision' => 3]);
        $this->excelCommit($this->excelPreview([$this->excelRow('UNKNOWN', 'Review Order TSQ-STARTWORK')]));
        $this->assertSame('JT', $order->fresh()->progress_category);
        $this->assertSame(3, $order->fresh()->revision);
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertSame(0, AuditLog::where('action', 'progress.auto_mapped_from_bima')->count());
    }

    public function test_legacy_pull_uses_the_same_rules_for_new_changed_unchanged_and_close_without_losing_logs(): void
    {
        $row = $this->row('LEGACY', [14 => 'Site-Survey Manual', 13 => 'STARTWORK']);
        $this->pull([$row]);
        $order = WorkOrder::sole();
        $this->assertSame('SURVEY', $order->progress_category);
        $this->assertSame(1, $order->revision);
        $this->pull([$row]);
        $this->assertDatabaseCount('wo_status_events', 1);
        $order->update(['progress_category' => 'JT', 'operational_status' => 'IN_PROGRESS', 'revision' => 2]);
        $same = $this->pull([$row]);
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertSame('SURVEY', $order->fresh()->progress_category);
        $this->assertSame(3, $order->fresh()->revision);
        $this->pull([$this->row('LEGACY', [14 => 'Activate Cross Connect', 13 => 'CANCLWORK'])]);
        $this->assertSame('INSTALASI', $order->fresh()->progress_category);
        $this->pull([$this->row('LEGACY', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE'])]);
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertTrue($order->fresh()->isPsEligible());
        $this->pull([$row]);
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertSame(5, $order->fresh()->revision);
        $this->assertDatabaseCount('wo_status_events', 4);
        $this->assertDatabaseCount('bima_task_logs', 3);
    }

    public function test_failed_progress_event_rolls_back_source_and_task_log_atomically(): void
    {
        $this->excelCommit($this->excelPreview([$this->excelRow('ATOMIC', 'Review Order TSQ-STARTWORK')]));
        $order = WorkOrder::sole();
        // A unique revision collision must fail the complete row transaction, retaining its snapshot for retry.
        $order->events()->create(['id_users' => $this->area->getKey(), 'old_status' => 'NEW', 'new_status' => 'NEW', 'revision' => 1, 'notes' => 'Riwayat yang sudah ada.']);
        $before = $order->getAttributes();
        $job = $this->excelPreview([$this->excelRow('ATOMIC', 'Install NTE Manual-STARTWORK')]);
        try {
            app(ExcelWorkOrderImportService::class)->commit($job);
            $this->fail('Expected unique event revision violation.');
        } catch (QueryException $exception) {
            $this->assertTrue(str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate'));
        }
        $this->assertEquals($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->assertDatabaseCount('wo_status_events', 1);
        $this->assertNotNull($job->rows()->sole()->payload);
        $this->assertNull($job->rows()->sole()->applied_at);
        $this->assertSame(0, AuditLog::where('action', 'progress.auto_mapped_from_bima')->count());
    }

    public function test_branch_inactive_and_non_bima_cannot_apply_automatic_progress(): void
    {
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'AUTH', 'task_bima' => 'Activate Service', 'status_bima' => 'STARTWORK',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', 'AUTH')]);
        foreach ([$this->user(Role::BranchAdmin), $this->user(Role::AreaHd, false)] as $actor) {
            try {
                app(BimaAutoProgressService::class)->apply($order, $actor);
                $this->fail('Expected forbidden.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $order->update(['source_system' => 'LOCAL']);
        $this->assertFalse(app(BimaAutoProgressService::class)->apply($order, $this->area));
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
