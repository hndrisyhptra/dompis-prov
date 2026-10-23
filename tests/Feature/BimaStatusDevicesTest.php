<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\MappingOrder;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\ExcelWorkOrderImportService;
use App\Services\OrderMappingReferenceService;
use App\Services\WorkOrderDeviceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;

class BimaStatusDevicesTest extends OloTestCase
{
    public function test_excel_upload_uses_owner_mapping_including_open_ps_and_canclwork_exception(): void
    {
        $service = app(OrderMappingReferenceService::class);
        $service->apply($service->reference(), $this->area);
        $this->excel('Approval E2E Testing Manual', 'STARTWORK');
        $order = WorkOrder::sole();
        $this->assertSame('OPEN', $order->mapping_order);
        $this->excel('Approval E2E Testing Manual', 'COMPLETE');
        $this->assertSame('PS', $order->fresh()->mapping_order);
        $this->assertTrue($order->fresh()->isPsEligible());
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->excel('Activate Cross Connect', 'CANCLWORK');
        $this->assertSame('STUCK', $order->fresh()->mapping_order);
        $this->excel('Unknown', 'COMPLETE');
        $this->assertNull($order->fresh()->mapping_order);
        $this->assertDatabaseCount('bima_task_logs', 3);
        $this->assertDatabaseCount('work_orders', 1);
    }

    private function excel(string $task, string $status, string $odp = 'ODP-SUMBER', bool $commit = true): SyncJob
    {
        $this->travel(1)->minutes(); // Separate real rate-limit windows for multi-upload scenarios.
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $file = XlsxFile::make(['NO TQ/SC/ID', 'BRANCH', 'TGL ORDER', 'TASK BIMA', 'STATUS BIMA', 'ODP', 'SN'], [['000BIMA', 'BRANCH UJI', '2026-10-01', $task, $status, $odp, '000SN']]);
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::latest('id_sync_jobs')->firstOrFail();
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => ['no_tq_sc_id' => 1, 'branch' => 2, 'tgl_order' => 3, 'task_bima' => 4, 'status_bima' => 5, 'odp' => 6, 'sn' => 7]])->assertSessionHasNoErrors()->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        if ($commit) {
            $this->commit($job);
        }

        return $job->fresh();
    }

    private function commit(SyncJob $job): void
    {
        $this->actingAs($this->area)->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
    }

    private function devices(array $changes = []): array
    {
        return array_replace(array_fill_keys(array_keys(WorkOrderDeviceService::FIELDS), null), ['revision' => 0], $changes);
    }

    public function test_excel_task_history_is_atomic_and_approved_status_auto_closes_with_progress_history(): void
    {
        $this->excel('Survey', 'OPEN');
        $order = WorkOrder::sole();
        $this->assertSame('Survey-OPEN', $order->status_order);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $order->update(['progress_category' => 'SURVEY', 'revision' => 5]);
        $job = $this->excel('Approval E2E Testing Manual', 'COMPLETE');
        $order->refresh();
        $this->assertSame('COMPLETE', $order->status_bima);
        $this->assertSame('Approval E2E Testing Manual-COMPLETE', $order->status_order);
        $this->assertSame('CLOSE', $order->progress_category);
        $this->assertSame(6, $order->revision);
        $this->assertSame('SURVEY', $order->events()->sole()->old_progress_category);
        $this->assertSame('CLOSE', $order->events()->sole()->new_progress_category);
        $this->assertNull($order->mapping_order);
        $this->assertDatabaseHas('bima_task_logs', ['id_work_orders' => $order->getKey(), 'old_task' => 'Survey', 'new_task' => 'Approval E2E Testing Manual', 'old_status_bima' => 'OPEN', 'new_status_bima' => 'COMPLETE', 'id_sync_jobs' => $job->getKey()]);
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $same = $this->excel('Approval E2E Testing Manual', 'COMPLETE');
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->excel('Approval E2E Testing Manual', 'OPEN');
        $this->assertDatabaseCount('bima_task_logs', 2);
        $this->actingAs($this->area)->get(route('work-orders.show', $order))->assertOk()->assertSee('Log Task BIMA')->assertSee('Survey-OPEN')->assertSee('Approval E2E Testing Manual-COMPLETE');
    }

    public function test_excel_approved_preview_is_read_only_and_unchanged_commit_reconciles_progress_once(): void
    {
        $job = $this->excel('Approval E2E Testing Manual', 'COMPLETE', commit: false);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->commit($job);
        $order = WorkOrder::sole();
        $hash = $order->source_snapshot_hash;
        $this->assertSame('CLOSE', $order->progress_category);
        DB::table('work_orders')->where('id_work_orders', $order->getKey())->update(['progress_category' => 'SURVEY', 'operational_status' => 'SURVEY', 'revision' => 2]);
        $preview = $this->excel('Approval E2E Testing Manual', 'COMPLETE', commit: false);
        $this->assertSame('SURVEY', $order->fresh()->progress_category);
        $this->commit($preview);
        $this->assertSame(1, $preview->fresh()->stats['unchanged']);
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertSame(3, $order->fresh()->revision);
        $this->assertSame($hash, $order->fresh()->source_snapshot_hash);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->excel('Approval E2E Testing Manual', 'COMPLETE');
        $this->assertDatabaseCount('wo_status_events', 2);
    }

    public function test_each_branch_device_role_can_edit_only_its_branch_with_validation_revision_and_audit(): void
    {
        $this->pull([$this->row()]);
        $order = WorkOrder::sole();
        foreach ([Role::BranchAdmin, Role::BranchHd] as $index => $role) {
            $user = $this->user($role);
            $user->branches()->attach($this->branch);
            $data = $this->devices(['revision' => $index, 'odp' => 'ODP-'.$index, 'sn' => '000SN', 'olt' => 'OLT', 'port_olt' => '0/1', 'metro' => 'METRO', 'port_metro' => '0/2']);
            $this->actingAs($user)->get(route('work-orders.show', $order))->assertOk()->assertSee('Simpan perangkat');
            $this->patch(route('work-orders.devices', $order), $data + ['progress_category' => 'CLOSE', 'status_bima' => 'COMPLETE'])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('ODP-'.$index, $order->fresh()->odp);
            $this->assertNull($order->fresh()->progress_category);
            $this->assertSame('OPEN', $order->fresh()->status_bima);
            $this->assertSame('000SN', $order->fresh()->sn);
            $this->patch(route('work-orders.devices', $order), $data)->assertConflict();
            $this->patch(route('work-orders.devices', $order), $this->devices(['revision' => $index + 1, 'sn' => str_repeat('A', 256)]))->assertSessionHasErrors('sn');
        }
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'devices.changed')->count());
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->assertNull($order->fresh()->last_progress_at);
        foreach ([Role::BranchAdmin, Role::BranchHd, Role::MaterialAdmin, Role::TeamLeader, Role::AreaCoordinator] as $role) {
            $outsider = $this->user($role);
            if (! in_array($role, [Role::BranchAdmin, Role::BranchHd], true)) {
                $outsider->branches()->attach($this->branch);
            }
            $this->actingAs($outsider)->patch(route('work-orders.devices', $order), $this->devices(['revision' => 2]))->assertForbidden();
        }
        $inactive = $this->user(Role::BranchHd, false);
        $inactive->branches()->attach($this->branch);
        $this->actingAs($inactive)->patch(route('work-orders.devices', $order), $this->devices(['revision' => 2]))->assertForbidden();
    }

    public function test_local_devices_including_cleared_values_survive_commit_after_preview_and_source_hash_stays_idempotent(): void
    {
        $this->excel('Survey', 'OPEN');
        $order = WorkOrder::sole();
        $job = $this->excel('Activation', 'COMPLETE', 'ODP-NEXT', false);
        $branch = $this->user(Role::BranchHd);
        $branch->branches()->attach($this->branch);
        $this->actingAs($branch)->patch(route('work-orders.devices', $order), $this->devices(['odp' => 'ODP-LOCAL', 'sn' => null]))->assertRedirect();
        $hash = $order->fresh()->source_snapshot_hash;
        $this->commit($job);
        $order->refresh();
        $this->assertSame('ODP-LOCAL', $order->odp);
        $this->assertNull($order->sn);
        $this->assertNotSame($hash, $order->source_snapshot_hash);
        $this->assertSame('ODP-NEXT', $order->source_bima_fields['ODP']);
        $this->assertSame(1, $order->revision);
        $same = $this->excel('Activation', 'COMPLETE', 'ODP-NEXT');
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertSame('ODP-LOCAL', $order->fresh()->odp);
        $order->update(['progress_category' => 'CLOSE', 'operational_status' => 'FIELD_DONE']);
        $this->actingAs($branch)->patch(route('work-orders.devices', $order), $this->devices(['revision' => 1, 'odp' => 'CORRECTION']))->assertRedirect();
        $this->assertSame('CORRECTION', $order->fresh()->odp);
    }

    public function test_daily_age_changes_do_not_change_source_hash_progress_revision_or_create_logs(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'Asia/Jakarta'));
        $this->excel('Survey', 'OPEN');
        $order = WorkOrder::sole();
        $stamp = $order->updated_at;
        $hash = $order->source_snapshot_hash;
        $this->assertSame(19, $order->umur_hari);
        $this->travel(10)->days();
        $this->assertSame(29, $order->fresh()->umur_hari);
        $this->assertSame('> 28 HARI', $order->fresh()->kategori_umur);
        $this->artisan('olo:refresh-order-aging')->assertSuccessful();
        $this->assertSame('29', (string) DB::table('work_orders')->value('umur_hari'));
        $this->assertEquals($stamp, $order->fresh()->updated_at);
        $this->assertSame($hash, $order->fresh()->source_snapshot_hash);
        $this->assertSame(0, $order->fresh()->revision);
        $same = $this->excel('Survey', 'OPEN');
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertDatabaseCount('bima_task_logs', 0);
    }

    public function test_mapping_table_matches_only_active_exact_rules_and_does_not_replace_the_ps_rule(): void
    {
        // Synthetic rules for verification only; production master remains empty until owner supplies rules.
        MappingOrder::create(['status_order' => 'Test-COMPLETE', 'mapping_order' => 'PS']);
        MappingOrder::create(['status_order' => 'Test-OPEN', 'mapping_order' => 'STUCK', 'active' => false]);
        $this->excel('Test', 'COMPLETE');
        $order = WorkOrder::sole();
        $this->assertSame('PS', $order->mapping_order);
        $this->assertNotNull($order->id_mapping_order);
        $this->assertFalse($order->isPsEligible());
        $order->update(['progress_category' => 'CLOSE']);
        $this->assertFalse($order->isPsEligible()); // Mapping PS cannot substitute the approved task/status list.
        $this->excel('Test', 'OPEN');
        $this->assertNull($order->fresh()->mapping_order);
        $this->assertNull($order->fresh()->id_mapping_order);
    }
}
