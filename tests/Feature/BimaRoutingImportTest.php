<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\ServiceArea;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use App\Services\BimaSheetPullService;
use App\Services\ExcelWorkOrderImportService;
use App\Services\ExcelWorkOrderMapper;
use App\Services\PriorityOrderService;
use App\Services\WorkOrderDeviceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;

class BimaRoutingImportTest extends OloTestCase
{
    private const HEADERS = ['Date Created', 'Date Modified', 'Workorder', 'ORDER', 'SC Order No/Track ID/CSRM No', 'OSS Order ID', 'Service No.', 'Description', 'CRM Order Type', 'Owner Group', 'Status', 'Product Name', 'Address', 'witel', 'Customer Name', 'Workzone', 'Region/Site ID', 'Status Date', 'Sched Start', 'Contact Number', 'Measurement', 'Measurement Date', 'Measurement Result', 'WO Class', 'No. Kontrak(KB/KL/P8)', 'Product Type', 'Booking Date', 'Area TIF', 'District TIF', 'Regional TIF'];

    private function excelRow(array $changes = []): array
    {
        return array_replace(array_fill(0, 30, ''), [0 => '2026-10-20 10:20:30.123', 3 => 'ROUTING-001', 6 => 'SID-001', 7 => 'Review Order TSQ',
            8 => 'NEW INSTALL', 9 => 'OLO TEST', 10 => 'STARTWORK', 11 => 'METRO', 12 => 'Alamat fiktif', 14 => 'Pelanggan fiktif',
            15 => 'TST', 28 => 'BRANCH UJI', 29 => 'REGION UJI'], $changes);
    }

    private function excelPreview(array $rows, ?array $mapping = null): SyncJob
    {
        $this->travel(1)->minutes();
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $file = XlsxFile::make(self::HEADERS, $rows, 'Work Orders');
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::latest('id_sync_jobs')->firstOrFail();
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => $mapping ?? (new ExcelWorkOrderMapper)->suggest(self::HEADERS)])->assertSessionHasNoErrors()->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('preview_ready', $job->fresh()->state);

        return $job->fresh();
    }

    private function excelCommit(SyncJob $job): SyncJob
    {
        $this->actingAs($this->area)->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));

        return $job->fresh();
    }

    private function branchActor(Branch $branch)
    {
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($branch);

        return $actor;
    }

    public function test_latest_excel_sto_branch_and_region_move_one_order_preserving_history_devices_issues_and_priority_privacy(): void
    {
        $this->branch->update(['region_id' => 1]);
        $next = Branch::create(['code' => 'NEXT', 'name' => 'Branch Tujuan', 'region_id' => 2]);
        // The BIMA Branch is newer than the shared STO ownership reference.
        $sto = ServiceArea::create(['workzone' => 'TST2', 'name' => 'STO Uji', 'id_regions' => 1, 'id_branches' => $this->branch->getKey(), 'is_active' => true]);
        $this->excelCommit($this->excelPreview([$this->excelRow()]));
        $order = WorkOrder::sole();
        $oldActor = $this->branchActor($this->branch);
        $newActor = $this->branchActor($next);
        $both = $this->branchActor($this->branch);
        $both->branches()->attach($next);
        $this->actingAs($oldActor)->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 0, 'progress_category' => 'HOLD', 'notes' => 'Branch asal menunggu akses gedung.'])->assertSessionHasNoErrors();
        $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 1, 'progress_category' => 'HOLD', 'notes' => 'PIC sudah dihubungi oleh Branch asal.'])->assertSessionHasNoErrors();
        app(WorkOrderDeviceService::class)->update($oldActor, $order, ['revision' => 2, 'odp' => 'ODP-LOCAL', 'sn' => 'SN-LOCAL', 'olt' => 'OLT-LOCAL', 'port_olt' => '1/1', 'metro' => 'METRO-LOCAL', 'port_metro' => '2/2']);
        app(PriorityOrderService::class)->set($this->area, $order, true);
        app(PriorityOrderService::class)->set($oldActor, $order, true);
        $preservedFields = ['progress_category', 'operational_status', 'verification_status', 'last_progress_at', 'verified_at', 'field_done_at', 'branch_completed_at',
            'tgl_ps', 'odp', 'sn', 'olt', 'port_olt', 'metro', 'port_metro', 'device_overrides'];
        $preserved = $order->fresh()->only($preservedFields);
        $history = $order->events()->get()->mapWithKeys(fn ($event) => [$event->getKey() => $event->getAttributes()]);
        $issue = $order->issues()->sole();
        $issueBefore = $issue->getAttributes();
        $note = $issue->notes()->sole();
        $noteBefore = $note->getAttributes();
        $hash = $order->fresh()->source_snapshot_hash;
        $row = $this->excelRow([15 => 'TST2', 28 => 'Branch Tujuan', 29 => 'REGION TERBARU']);
        $job = $this->excelPreview([$row]);
        $this->assertSame(1, $job->stats['changed']);
        $this->assertSame(0, $job->stats['conflict']);
        $this->assertSame($this->branch->getKey(), $order->fresh()->id_branches);
        $this->assertSame('TST', $order->fresh()->sto);
        $this->get(route('sync.show', $job))->assertOk()->assertSee('STO dan Branch mengikuti file terbaru');
        $this->assertSame('success', $this->excelCommit($job)->state);
        $order->refresh();
        $this->assertSame($next->getKey(), $order->id_branches);
        $this->assertSame('Branch Tujuan', $order->branch);
        $this->assertSame('TST2', $order->sto);
        $this->assertSame('REGION TERBARU', $order->regional);
        $this->assertSame(4, $order->revision);
        $this->assertNotSame($hash, $order->source_snapshot_hash);
        $this->assertEquals($preserved, $order->only($preservedFields));
        $this->assertEquals($issueBefore, $issue->fresh()->getAttributes());
        $this->assertEquals($noteBefore, $note->fresh()->getAttributes());
        foreach ($history as $id => $attributes) {
            $this->assertEquals($attributes, $order->events()->findOrFail($id)->getAttributes());
        }
        $log = $order->taskLogs()->sole();
        $this->assertSame(['label' => 'BRANCH', 'before' => 'BRANCH UJI', 'after' => 'Branch Tujuan'], $log->field_changes['branch']);
        $this->assertSame(['label' => 'STO', 'before' => 'TST', 'after' => 'TST2'], $log->field_changes['sto']);
        $this->assertSame($next->getKey(), $log->field_changes['id_branches']['after']);
        $event = $order->events()->first();
        $this->assertSame('HOLD', $event->old_progress_category);
        $this->assertSame('HOLD', $event->new_progress_category);
        $this->assertStringContainsString('Branch Uji → Branch Tujuan', $event->notes);
        $audit = AuditLog::where('action', 'work_order.routing_updated_from_bima')->sole();
        $this->assertSame($this->branch->getKey(), $audit->metadata['before']['id_branches']);
        $this->assertSame($next->getKey(), $audit->metadata['after']['id_branches']);
        $this->assertSame($job->getKey(), $audit->metadata['id_sync_jobs']);
        $this->assertSame($this->branch->getKey(), $sto->fresh()->id_branches);
        $this->assertDatabaseCount('work_orders', 1);

        $this->actingAs($oldActor)->get(route('work-orders.index'))->assertOk()->assertViewHas('total', 0);
        $this->get(route('work-orders.show', $order))->assertForbidden();
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 3, 'progress_category' => 'JT', 'notes' => 'Form Branch lama.'])->assertForbidden();
        $this->actingAs($both)->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 3, 'progress_category' => 'JT', 'notes' => 'Form sebelum perpindahan.'])->assertConflict();
        $this->actingAs($newActor)->get(route('work-orders.index'))->assertOk()->assertViewHas('total', 1);
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Branch asal menunggu akses gedung.')->assertSee('PIC sudah dihubungi oleh Branch asal.')->assertSee('Penugasan Branch mengikuti import BIMA terbaru');
        $this->get(route('branch.dashboard'))->assertOk()->assertViewHas('outstanding', 1);
        $this->assertSame(['area'], WorkOrderPriority::visibleTo($newActor)->pluck('scope_key')->all());
        $this->assertSame(0, WorkOrderPriority::visibleTo($oldActor)->count());
        $this->assertDatabaseCount('work_order_priorities', 2);
        $this->actingAs($this->area)->get(route('area.dashboard', ['region' => 2]))->assertViewHas('summary', fn ($summary) => $summary['total'] === 1);
        $this->get(route('area.dashboard', ['region' => 1]))->assertViewHas('summary', fn ($summary) => $summary['total'] === 0);

        $before = $order->fresh()->getAttributes();
        $again = $this->excelCommit($this->excelPreview([$row]));
        $this->assertSame(1, $again->stats['unchanged']);
        $this->assertEquals($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->assertDatabaseCount('wo_status_events', 3);
        $this->assertSame(1, AuditLog::where('action', 'work_order.routing_updated_from_bima')->count());
    }

    public function test_sto_only_and_branch_label_change_update_source_without_resetting_progress_or_reassigning_same_branch(): void
    {
        $this->excelCommit($this->excelPreview([$this->excelRow()]));
        $order = WorkOrder::sole();
        $order->update(['progress_category' => 'JT', 'operational_status' => 'IN_PROGRESS', 'revision' => 7]);
        $job = $this->excelCommit($this->excelPreview([$this->excelRow([15 => 'NEWSTO', 28 => 'branch uji'])]));
        $this->assertSame(1, $job->stats['updated']);
        $this->assertSame('NEWSTO', $order->fresh()->sto);
        $this->assertSame('branch uji', $order->fresh()->branch);
        $this->assertSame($this->branch->getKey(), $order->fresh()->id_branches);
        $this->assertSame('JT', $order->fresh()->progress_category);
        $this->assertSame(7, $order->fresh()->revision);
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->assertSame(1, AuditLog::where('action', 'work_order.routing_updated_from_bima')->count());
    }

    public function test_identical_excel_reconciles_changed_assignment_mapping_once_and_preserves_source_hash(): void
    {
        $this->excelCommit($this->excelPreview([$this->excelRow()]));
        $order = WorkOrder::sole();
        $hash = $order->source_snapshot_hash;
        $next = Branch::create(['code' => 'NEXT', 'name' => 'Next Branch']);
        BranchMapping::where('bima_branch', 'BRANCH UJI')->update(['id_branches' => $next->getKey()]);
        $job = $this->excelPreview([$this->excelRow()]);
        $this->assertSame(1, $job->stats['changed']);
        $this->excelCommit($job);
        $this->assertSame($next->getKey(), $order->fresh()->id_branches);
        $this->assertSame($hash, $order->fresh()->source_snapshot_hash);
        $this->assertSame(1, $order->fresh()->revision);
        $this->assertSame(['id_branches'], array_keys($order->taskLogs()->sole()->field_changes));
        $this->assertSame(1, $this->excelCommit($this->excelPreview([$this->excelRow()]))->stats['unchanged']);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->assertDatabaseCount('wo_status_events', 1);
    }

    public function test_mapping_or_source_or_assignment_changes_after_preview_require_a_fresh_preview(): void
    {
        $this->excelCommit($this->excelPreview([$this->excelRow()]));
        $order = WorkOrder::sole();
        $next = Branch::create(['code' => 'NEXT', 'name' => 'Next Branch']);
        $third = Branch::create(['code' => 'THIRD', 'name' => 'Third Branch']);
        $job = $this->excelPreview([$this->excelRow([28 => 'NEXT'])]);
        $next->update(['active' => false]);
        $this->assertSame(1, $this->excelCommit($job)->stats['conflict']);
        $this->assertSame($this->branch->getKey(), $order->fresh()->id_branches);
        $next->update(['active' => true]);
        $job = $this->excelPreview([$this->excelRow([28 => 'NEXT'])]);
        // A concurrent assignment-only change does not alter the source hash.
        DB::table('work_orders')->where('id_work_orders', $order->getKey())->update(['id_branches' => $third->getKey()]);
        $this->assertSame(1, $this->excelCommit($job)->stats['conflict']);
        $this->assertSame($third->getKey(), $order->fresh()->id_branches);
        $job = $this->excelPreview([$this->excelRow([28 => 'NEXT'])]);
        $order->refresh()->update(['source_snapshot_hash' => hash('sha256', 'concurrent-source')]);
        $this->assertSame(1, $this->excelCommit($job)->stats['conflict']);
        $this->assertSame($third->getKey(), $order->fresh()->id_branches);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->assertDatabaseCount('wo_status_events', 0);
    }

    public function test_unmapped_fields_are_preserved_and_unknown_or_inactive_destination_remains_a_conflict(): void
    {
        $this->excelCommit($this->excelPreview([$this->excelRow()]));
        $order = WorkOrder::sole();
        $mapping = (new ExcelWorkOrderMapper)->suggest(self::HEADERS);
        unset($mapping['sto']);
        $next = Branch::create(['code' => 'NEXT', 'name' => 'Next Branch']);
        $this->excelCommit($this->excelPreview([$this->excelRow([15 => 'DO-NOT-IMPORT', 28 => 'NEXT'])], $mapping));
        $this->assertSame('TST', $order->fresh()->sto);
        $this->assertSame($next->getKey(), $order->fresh()->id_branches);
        $this->assertSame('NEXT', $order->fresh()->branch);
        $before = $order->fresh()->getAttributes();
        $next->update(['active' => false]);
        $job = $this->excelPreview([$this->excelRow([28 => 'NEXT'])]);
        $this->assertSame(1, $job->stats['conflict']);
        $this->assertSame(1, $this->excelCommit($job)->stats['conflict']);
        $job = $this->excelPreview([$this->excelRow([28 => 'UNKNOWN', 15 => 'UNKNOWN'])]);
        $this->assertSame(1, $this->excelCommit($job)->stats['conflict']);
        $this->assertEquals($before, $order->fresh()->getAttributes());
    }

    public function test_legacy_pull_also_follows_latest_branch_without_creating_duplicates_or_losing_progress(): void
    {
        $this->pull([$this->row()]);
        $order = WorkOrder::sole();
        $order->update(['progress_category' => 'CLOSE', 'operational_status' => 'FIELD_DONE', 'revision' => 4, 'branch_completed_at' => now()]);
        $before = $order->fresh()->only(['progress_category', 'operational_status', 'branch_completed_at', 'last_progress_at']);
        $next = Branch::create(['code' => 'NEXT', 'name' => 'Next Branch']);
        $row = $this->row(changes: [5 => 'NEXT', 6 => 'NEWSTO']);
        $job = $this->pull([$row]);
        $this->assertSame(1, $job->stats['updated']);
        $this->assertSame($next->getKey(), $order->fresh()->id_branches);
        $this->assertSame('NEWSTO', $order->fresh()->sto);
        $this->assertSame(5, $order->fresh()->revision);
        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertSame(1, $this->pull([$row])->stats['unchanged']);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('wo_status_events', 1);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $stale = $this->preview([$this->row(changes: [5 => 'NEXT', 6 => 'OTHERSTO'])]);
        DB::table('work_orders')->where('id_work_orders', $order->getKey())->update(['id_branches' => $this->branch->getKey()]);
        app(BimaSheetPullService::class)->pull($stale);
        $this->assertSame(1, $stale->fresh()->stats['conflict']);
        $this->assertSame('NEWSTO', $order->fresh()->sto);
    }
}
