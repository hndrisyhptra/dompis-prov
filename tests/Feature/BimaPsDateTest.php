<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\AuditLog;
use App\Models\MappingOrder;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\ExcelWorkOrderImportService;
use App\Services\ExcelWorkOrderMapper;
use App\Services\PriorityOrderService;
use App\Support\PsEligibility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;

class BimaPsDateTest extends OloTestCase
{
    private const HEADERS = ['Date Created', 'Date Modified', 'Workorder', 'ORDER', 'Service No.', 'Description', 'CRM Order Type',
        'Owner Group', 'Status', 'Product Name', 'Address', 'Customer Name', 'Workzone', 'District TIF', 'Regional TIF'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-26 04:00:00', 'UTC'));
        foreach (PsEligibility::STATUSES as $status) {
            MappingOrder::create(['status_order' => $status, 'mapping_order' => 'PS']);
        }
    }

    private function bimaRow(string $id = 'PS-DATE', mixed $modified = '2026-10-26 08:05:10.123', string $status = 'Approval E2E Testing Manual-COMPLETE'): array
    {
        $separator = strrpos($status, '-');

        return ['2026-07-14 09:00:00.000', $modified, '', $id, '000SID', substr($status, 0, $separator), 'NEW INSTALL', 'OWNER-UJI', substr($status, $separator + 1),
            'METRO', 'Alamat sintetis', 'Pelanggan sintetis', 'TST', 'BRANCH UJI', 'REGION UJI'];
    }

    private function previewRows(array $rows, bool $date1904 = false, array $headers = self::HEADERS, ?array $mapping = null): SyncJob
    {
        $this->travel(61)->seconds();
        $file = XlsxFile::make($headers, $rows, 'Work Orders', date1904: $date1904);
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::latest('id_sync_jobs')->firstOrFail();
        $suggested = (new ExcelWorkOrderMapper)->suggest($headers);
        $page = $this->get(route('imports.mapping', $job))->assertOk()->assertSee('Date Modified');
        $this->assertSame($suggested, $page->viewData('mapping'));
        $mapping ??= $suggested;
        $this->post(route('imports.preview', $job), ['mapping' => $mapping, 'confirmed_mapping' => 1])->assertSessionHasNoErrors()->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('preview_ready', $job->fresh()->state);

        return $job->fresh();
    }

    private function commit(SyncJob $job): SyncJob
    {
        $this->actingAs($this->area)->post(route('sync.commit', $job), ['confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));

        return $job->fresh();
    }

    private function import(array $rows, bool $date1904 = false): SyncJob
    {
        return $this->commit($this->previewRows($rows, $date1904));
    }

    public function test_fourteen_ps_statuses_use_modified_date_and_feed_area_branch_and_daily_lists(): void
    {
        $rows = [];
        foreach (PsEligibility::STATUSES as $index => $status) {
            $rows[] = $this->bimaRow('PS-'.$index, status: $status);
        }
        MappingOrder::create(['status_order' => 'Site Survey-COMPLETE', 'mapping_order' => 'OPEN']);
        MappingOrder::create(['status_order' => 'Activate Global-COMPLETE', 'mapping_order' => 'PS']);
        $rows[] = $this->bimaRow('NOT-PS-COMPLETE', status: 'Site Survey-COMPLETE');
        $rows[] = $this->bimaRow('NOT-IN-APPROVED-PS-LIST', status: 'Activate Global-COMPLETE');
        $taskOnly = $this->bimaRow('TASK-ONLY');
        $taskOnly[5] = 'COMPWORK';
        $taskOnly[8] = '';
        $rows[] = $taskOnly;
        $job = $this->import($rows);
        $this->assertSame(17, $job->stats['inserted']);
        foreach (WorkOrder::where('no_tq_sc_id', 'like', 'PS-%')->get() as $order) {
            $this->assertSame('2026-10-26', $order->tgl_ps->toDateString());
            $this->assertSame('2026-07-14', $order->tgl_order->toDateString());
            $this->assertSame('CLOSE', $order->progress_category);
        }
        foreach (['NOT-PS-COMPLETE', 'NOT-IN-APPROVED-PS-LIST', 'TASK-ONLY'] as $id) {
            $this->assertNull(WorkOrder::where('no_tq_sc_id', $id)->sole()->tgl_ps);
        }
        $area = $this->get(route('area.dashboard'))->assertOk();
        $this->assertSame(14, $area->viewData('summary')['ps_today']);
        $this->assertSame(0, $area->viewData('summary')['missing_ps_date']);
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor)->get(route('branch.dashboard'))->assertOk()->assertViewHas('complete', 14)->assertViewHas('period_complete', 14);
        $this->get(route('work-orders.index', ['queue' => 'ps_today']))->assertViewHas('orders', fn ($orders) => $orders->total() === 14);
    }

    public function test_excel_ps_releases_priority_only_after_commit_including_unchanged_source_retries(): void
    {
        $this->import([$this->bimaRow(status: 'Site Survey-STARTWORK')]);
        $order = WorkOrder::sole();
        $branch = $this->user(Role::BranchHd);
        $branch->branches()->attach($this->branch);
        app(PriorityOrderService::class)->set($this->area, $order, true);
        app(PriorityOrderService::class)->set($branch, $order, true);
        $job = $this->previewRows([$this->bimaRow()]);
        $this->assertSame(2, $order->priorities()->count());
        $this->commit($job);
        $this->assertSame(0, $order->priorities()->count());
        $this->assertSame('2026-10-26', $order->fresh()->tgl_ps->toDateString());
        $this->assertSame(2, AuditLog::where('action', 'priority.auto_released_ps')->count());
        // Legacy bookmark created before this feature: identical reimports must clean it up too.
        $order->priorities()->create(['scope_key' => 'area', 'id_users' => $this->area->getKey(), 'selection_token' => (string) Str::uuid()]);
        $same = $this->import([$this->bimaRow()]);
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertSame(0, $order->priorities()->count());
        $this->assertSame(3, AuditLog::where('action', 'priority.auto_released_ps')->count());
        $this->import([$this->bimaRow()]);
        $this->assertSame(3, AuditLog::where('action', 'priority.auto_released_ps')->count());
    }

    public function test_retry_date_correction_blank_and_non_ps_updates_preserve_history_progress_and_previous_date(): void
    {
        $this->import([$this->bimaRow()]);
        $order = WorkOrder::sole();
        $order->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'OTHER', 'description' => 'Catatan Branch tetap ada', 'pic' => 'PIC']);
        $revision = $order->revision;
        $events = $order->events()->count();
        $same = $this->import([$this->bimaRow()]);
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $corrected = $this->import([$this->bimaRow(modified: '2026-10-27 00:00:01.100')]);
        $this->assertSame(1, $corrected->stats['updated']);
        $this->assertSame('2026-10-27', $order->fresh()->tgl_ps->toDateString());
        $change = $order->taskLogs()->sole()->field_changes['tgl_ps'];
        $this->assertSame('2026-10-26', $change['before']);
        $this->assertSame('2026-10-27', $change['after']);
        $blank = $this->import([$this->bimaRow(modified: '')]);
        $this->assertSame(1, $blank->stats['unchanged']);
        $this->assertSame('2026-10-27', $order->fresh()->tgl_ps->toDateString());
        $this->import([$this->bimaRow(modified: '2026-10-29', status: 'Site Survey-STARTWORK')]);
        $sameNonPs = $this->import([$this->bimaRow(modified: '2026-10-30', status: 'Site Survey-STARTWORK')]);
        $this->assertSame(1, $sameNonPs->stats['unchanged']);
        $this->assertSame('2026-10-27', $order->fresh()->tgl_ps->toDateString());
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertSame($revision, $order->fresh()->revision);
        $this->assertSame($events, $order->events()->count());
        $this->assertSame('OPEN', $order->issues()->sole()->status);
    }

    public function test_mapping_inactive_missing_or_non_ps_never_supplies_a_modified_date(): void
    {
        $rule = MappingOrder::where('status_order', 'Approval E2E Testing Manual-COMPLETE')->sole();
        foreach (['OPEN', 'STUCK', 'CANCLWORK', 'inactive', 'missing'] as $state) {
            $status = 'Approval E2E Testing Manual-COMPLETE';
            if ($state === 'missing') {
                $status = 'Testing and Approval-COMPLETE';
                MappingOrder::where('status_order', $status)->delete();
            } else {
                $rule->update(['mapping_order' => $state === 'inactive' ? 'PS' : $state, 'active' => $state !== 'inactive']);
            }
            $job = $this->import([$this->bimaRow('RULE-'.$state, status: $status)]);
            $this->assertSame(1, $job->stats['inserted']);
            $this->assertNull(WorkOrder::where('no_tq_sc_id', 'RULE-'.$state)->sole()->tgl_ps);
        }
    }

    public function test_invalid_formula_and_error_modified_dates_are_rejected_for_ps_while_unused_dates_are_ignored(): void
    {
        foreach (['2026-02-30', ['value' => '46321', 'type' => 'n', 'formula' => 'TODAY()'], ['value' => '#VALUE!', 'type' => 'e']] as $index => $value) {
            $job = $this->previewRows([$this->bimaRow('INVALID-'.$index, $value)]);
            $this->assertSame(1, $job->stats['rejected']);
            $this->assertStringContainsString('Date Modified', $job->rows()->sole()->error);
        }
        $valid = $this->import([$this->bimaRow('IGNORED', ['value' => '#VALUE!', 'type' => 'e'], 'Site Survey-STARTWORK'), $this->bimaRow('BLANK', '')]);
        $this->assertSame(2, $valid->stats['inserted']);
        $this->assertSame(0, WorkOrder::whereNotNull('tgl_ps')->count());
    }

    public function test_excel_epochs_shifted_columns_and_changed_master_after_preview_are_safe(): void
    {
        foreach ([false, true] as $date1904) {
            $serial = $date1904 ? '44859.75' : '46321.75';
            $this->import([$this->bimaRow('EPOCH-'.(int) $date1904, ['value' => $serial, 'type' => 'n'])], $date1904);
            $this->assertSame('2026-10-26', WorkOrder::where('no_tq_sc_id', 'EPOCH-'.(int) $date1904)->sole()->tgl_ps->toDateString());
        }
        $headers = self::HEADERS;
        $row = $this->bimaRow('SHIFTED');
        [$headers[1], $headers[12]] = [$headers[12], $headers[1]];
        [$row[1], $row[12]] = [$row[12], $row[1]];
        $shifted = $this->commit($this->previewRows([$row], headers: $headers));
        $this->assertSame(1, $shifted->stats['inserted']);
        $this->assertSame('2026-10-26', WorkOrder::where('no_tq_sc_id', 'SHIFTED')->sole()->tgl_ps->toDateString());
        $job = $this->previewRows([$this->bimaRow('CONFLICT')]);
        MappingOrder::where('status_order', 'Approval E2E Testing Manual-COMPLETE')->update(['mapping_order' => 'OPEN']);
        $committed = $this->commit($job);
        $this->assertSame(1, $committed->stats['conflict']);
        $this->assertFalse(WorkOrder::where('no_tq_sc_id', 'CONFLICT')->exists());
    }

    public function test_old_preview_contract_is_not_committed_under_the_new_date_rule(): void
    {
        $job = $this->previewRows([$this->bimaRow()]);
        $job->update(['connection_hash' => hash('sha256', 'olo-work-order-xlsx-v4-task-status')]);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertConflict();
        $this->get(route('imports.mapping', $job))->assertConflict();
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_partial_manual_mapping_uses_current_source_status_and_bima_preset_rejects_a_different_date_header(): void
    {
        $this->import([$this->bimaRow()]);
        $headers = ['NO TQ/SC/ID', 'BRANCH', 'Date Modified'];
        $job = $this->previewRows([['PS-DATE', 'BRANCH UJI', '2026-10-25 23:59:59']], headers: $headers,
            mapping: ['no_tq_sc_id' => 1, 'branch' => 2, 'tgl_ps' => 3]);
        $this->commit($job);
        $this->assertSame('2026-10-25', WorkOrder::sole()->tgl_ps->toDateString());
        $presetJob = $this->previewRows([$this->bimaRow('MANUAL')]);
        $bad = (new ExcelWorkOrderMapper)->suggest(self::HEADERS);
        unset($bad['tgl_order']);
        $bad['tgl_ps'] = 1;
        $this->travel(61)->seconds();
        $this->post(route('imports.preview', $presetJob), ['mapping' => $bad, 'confirmed_mapping' => 1])->assertSessionHasErrors('mapping.tgl_ps');
        $this->assertDatabaseCount('work_orders', 1);
    }
}
