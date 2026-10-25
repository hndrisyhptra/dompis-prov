<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\WorkOrder;
use App\Services\BimaAutoCloseService;
use App\Support\PsEligibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BimaAutoCloseTest extends OloTestCase
{
    private function historical(string $id, string $task = 'Approval E2E Testing Manual', string $status = 'COMPLETE', string $date = '2026-07-15'): WorkOrder
    {
        return WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'task_bima' => $task, 'status_bima' => $status,
            'tgl_order' => $date, 'jenis_pekerjaan' => 'NEW INSTALL', 'id_branches' => $this->branch->getKey(), 'source_snapshot_hash' => hash('sha256', $id),
            'source_bima_fields' => ['Description' => $task, 'Status' => $status], 'odp' => 'ODP-LOCAL', 'device_overrides' => ['odp'],
            'progress_category' => 'SURVEY', 'operational_status' => 'SURVEY', 'revision' => 3]);
    }

    public function test_all_approved_source_statuses_reconcile_once_preserving_sources_dates_devices_and_open_issues(): void
    {
        $orders = collect(PsEligibility::STATUSES)->map(function ($status, $i) {
            $parts = explode('-', $status);
            $raw = array_pop($parts);

            return $this->historical('HIST-'.$i, implode('-', $parts), $raw, $i % 2 ? '2026-08-20' : '2026-07-15');
        });
        $blocked = $orders->first();
        $blocked->update(['progress_category' => 'KENDALA', 'operational_status' => 'BLOCKED']);
        $issue = $blocked->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'OTHER', 'description' => 'Perlu review', 'pic' => 'PIC Uji']);
        $before = $orders->mapWithKeys(fn ($order) => [$order->getKey() => $order->fresh()->only(['task_bima', 'status_bima', 'status_order', 'source_snapshot_hash', 'source_bima_fields', 'tgl_order', 'tgl_ps', 'branch_completed_at', 'field_done_at', 'odp', 'device_overrides'])]);
        $this->historical('NON-PS', 'Test', 'COMPLETE');
        $this->historical('PREFIX-PS', 'X Approval E2E Testing Manual', 'COMPLETE');
        $service = app(BimaAutoCloseService::class);
        $preview = $service->reconcile($this->area, true);
        $this->assertSame(14, $preview['stats']['to_close']);
        $this->assertSame(1, $preview['stats']['with_open_issues']);
        $this->assertSame(7, $preview['months']['2026-07']['to_close']);
        $this->assertSame(7, $preview['months']['2026-08']['to_close']);
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(14, $service->reconcile($this->area)['stats']['updated']);
        foreach ($orders as $order) {
            $order->refresh();
            $this->assertSame('CLOSE', $order->progress_category);
            $this->assertSame('FIELD_DONE', $order->operational_status);
            $this->assertSame('UNVERIFIED', $order->verification_status);
            $this->assertSame(4, $order->revision);
            $this->assertEquals($before[$order->getKey()], $order->only(array_keys($before[$order->getKey()])));
            $this->assertTrue($order->isPsEligible());
            $this->assertStringContainsString('Penyesuaian otomatis', $order->events()->sole()->notes);
        }
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->assertSame('SURVEY', WorkOrder::where('no_tq_sc_id', 'NON-PS')->sole()->progress_category);
        $this->assertSame(0, $service->reconcile($this->area)['stats']['updated']);
        $this->assertDatabaseCount('wo_status_events', 14);
        $this->assertSame(14, AuditLog::where('action', 'progress.auto_closed_from_bima')->count());
        $this->assertSame(14, WorkOrder::psEligible()->count());
        $this->assertSame(0, WorkOrder::psOnDate(now()->toDateString())->count());
    }

    public function test_pull_handles_new_changed_and_unchanged_source_without_auto_reopening_or_duplicate_logs(): void
    {
        $row = $this->row('AUTO', [1 => '2026-07-15', 14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE']);
        $this->pull([$row]);
        $order = WorkOrder::sole();
        $this->assertSame('CLOSE', $order->progress_category);
        $this->assertSame(1, $order->revision);
        $hash = $order->source_snapshot_hash;
        $again = $this->pull([$row]);
        $this->assertSame(1, $again->stats['unchanged']);
        $this->assertDatabaseCount('wo_status_events', 1);
        // Old rows can need reconciliation even when the next upload's source hash is identical.
        DB::table('work_orders')->where('id_work_orders', $order->getKey())->update(['progress_category' => 'SURVEY', 'operational_status' => 'SURVEY', 'revision' => 2]);
        $this->pull([$row]);
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertSame(3, $order->fresh()->revision);
        $this->assertSame($hash, $order->fresh()->source_snapshot_hash);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->pull([$this->row('AUTO', [14 => 'Review Order TSQ', 13 => 'STARTWORK'])]);
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertFalse($order->fresh()->isPsEligible());
        $this->assertSame(1, $order->fresh()->taskLogs()->count());
        $this->assertDatabaseCount('wo_status_events', 2);
    }

    public function test_backfill_requires_active_area_actor_and_exclusive_inbound_lock(): void
    {
        $this->historical('LOCKED');
        $this->area->forceFill(['username' => 'auto-close-area'])->save();
        $this->artisan('olo:reconcile-bima-close')->assertFailed();
        $branchUser = $this->user();
        $branchUser->forceFill(['username' => 'auto-close-branch'])->save();
        $this->artisan('olo:reconcile-bima-close', ['--actor' => $branchUser->username])->assertFailed();
        $inactive = $this->user(Role::AreaHd, false);
        $inactive->forceFill(['username' => 'auto-close-inactive'])->save();
        $this->artisan('olo:reconcile-bima-close', ['--actor' => $inactive->username])->assertFailed();
        $lock = Cache::lock('olo:work-order-inbound', 300);
        $lock->get();
        try {
            $this->artisan('olo:reconcile-bima-close', ['--actor' => $this->area->username])->expectsOutput('Import work order sedang berjalan. Ulangi setelah import selesai.')->assertFailed();
        } finally {
            $lock->release();
        }
        $this->artisan('olo:reconcile-bima-close', ['--actor' => $this->area->username, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('SURVEY', WorkOrder::sole()->progress_category);
        $this->artisan('olo:reconcile-bima-close', ['--actor' => $this->area->username])->assertSuccessful();
        $this->assertSame('CLOSE', WorkOrder::sole()->progress_category);
    }

    public function test_month_cohorts_and_ps_drilldowns_are_scoped_and_use_order_month_not_ps_date(): void
    {
        $july = $this->historical('JULY');
        $july->update(['tgl_ps' => '2026-08-01']);
        $this->historical('AUGUST', 'Testing dan Approval', 'COMPLETE', '2026-08-20');
        $this->historical('JULY-OPEN', 'Review Order TSQ', 'STARTWORK');
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Cabang lain']);
        $this->historical('OTHER-BRANCH')->update(['id_branches' => $other->getKey()]);
        app(BimaAutoCloseService::class)->reconcile($this->area);
        $branchUser = $this->user();
        $branchUser->branches()->attach($this->branch);
        $this->actingAs($branchUser)->get(route('branch.dashboard'))->assertOk()->assertSee('Penyelesaian berdasarkan bulan order')->assertSee('data-order-month-chart', false)->assertSee('cohort-line cohort-ps', false)->assertViewHas('orderMonths', function ($data) {
            $rows = collect($data['rows'])->keyBy('month');

            return $rows['2026-07']['total'] === 2 && $rows['2026-07']['ps'] === 1 && $rows['2026-07']['closed'] === 1 && $rows['2026-07']['outstanding'] === 1 && $rows['2026-08']['ps'] === 1;
        });
        $filters = ['order_month' => '2026-07', 'queue' => 'complete', 'jenis_pekerjaan' => 'NEW INSTALL'];
        $this->get(route('work-orders.index', $filters))->assertOk()->assertSee('JULY</a>', false)->assertDontSee('OTHER-BRANCH')->assertDontSee('JULY-OPEN')->assertDontSee('AUGUST</a>', false)->assertSee('Bulan order: Juli 2026');
        $this->get(route('work-orders.index', $filters + ['branch' => $other->getKey()]))->assertForbidden();
        $this->get(route('work-orders.index', ['order_month' => 'bad']))->assertSessionHasErrors('order_month');
        $this->actingAs($this->area)->get(route('area.dashboard', ['jenis_pekerjaan' => 'all']))->assertOk()->assertViewHas('orderMonths', fn ($data) => collect($data['rows'])->sum('ps') === 3);
    }
}
