<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use App\Services\BimaAutoCloseService;
use App\Services\BimaSheetPullService;
use App\Services\BranchProgressService;
use App\Services\PriorityOrderService;
use App\Support\PsEligibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PriorityPsReleaseTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->area->forceFill(['username' => 'priority-area'])->save();
    }

    private function order(string $id, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
            'jenis_pekerjaan' => 'MODIFY', 'progress_category' => 'SURVEY', 'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
    }

    private function localActor()
    {
        $actor = $this->user(Role::BranchHd);
        $actor->forceFill(['username' => 'branch-'.$actor->getKey()])->save();
        $actor->branches()->attach($this->branch);

        return $actor;
    }

    private function legacyBookmark(WorkOrder $order, string $key = 'area'): void
    {
        WorkOrderPriority::create(['id_work_orders' => $order->getKey(), 'scope_key' => $key, 'id_branches' => $key === 'area' ? null : $this->branch->getKey(),
            'id_users' => $this->area->getKey(), 'selection_token' => (string) Str::uuid()]);
    }

    public function test_all_approved_ps_statuses_release_area_and_branch_on_close_and_prevent_stale_reselection(): void
    {
        $branch = $this->localActor();
        foreach (PsEligibility::STATUSES as $i => $status) {
            $separator = strrpos($status, '-');
            $order = $this->order('RELEASE-'.$i, ['task_bima' => substr($status, 0, $separator), 'status_bima' => substr($status, $separator + 1), 'odp' => 'ODP-LOCAL']);
            app(PriorityOrderService::class)->set($this->area, $order, true);
            app(PriorityOrderService::class)->set($branch, $order, true);
            $this->actingAs($branch)->patch(route('work-orders.action.update', [$order, 'close']), ['revision' => 0, 'progress_category' => 'CLOSE', 'notes' => 'Instalasi selesai dan diuji.'])
                ->assertSessionHasNoErrors()->assertRedirect();
            $order->refresh();
            $this->assertTrue($order->isPsEligible());
            $this->assertSame(0, $order->priorities()->count());
            $this->assertSame('ODP-LOCAL', $order->odp);
            $this->assertNull($order->tgl_ps);
            $this->post(route('priorities.store', $order))->assertSessionHasErrors('priority');
            $this->get(route('work-orders.show', $order))->assertOk()->assertDontSee('Pilih Prioritas Branch');
            $this->patch(route('work-orders.action.update', [$order, 'close']), ['revision' => $order->revision, 'progress_category' => 'CLOSE', 'notes' => 'Instalasi selesai dan diuji.'])->assertRedirect();
        }
        $this->assertSame(28, AuditLog::where('action', 'priority.auto_released_ps')->count());
        $this->assertSame(14, DB::table('wo_status_events')->count());
        $this->assertDatabaseCount('work_order_priorities', 0);
        $order = WorkOrder::firstOrFail();
        $this->actingAs($this->area)->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => $order->revision, 'progress_category' => 'INSTALASI', 'notes' => 'Koreksi hasil review Area.'])->assertRedirect();
        $this->get(route('priorities.index'))->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
        $this->post(route('priorities.store', $order))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('work_order_priorities', 1);
    }

    public function test_import_preview_keeps_bookmarks_and_commit_releases_them_with_issues_and_history_preserved(): void
    {
        $this->pull([$this->row('IMPORT-PS')]);
        $order = WorkOrder::sole();
        app(PriorityOrderService::class)->set($this->area, $order, true);
        app(PriorityOrderService::class)->set($this->localActor(), $order, true);
        $issue = $order->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'OTHER', 'description' => 'Catatan tetap tersimpan', 'pic' => 'PIC']);
        $job = $this->preview([$this->row('IMPORT-PS', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE'])]);
        $this->assertSame(2, $order->priorities()->count());
        $job->update(['stage' => 'commit']);
        app(BimaSheetPullService::class)->pull($job);
        $this->assertTrue($order->fresh()->isPsEligible());
        $this->assertDatabaseCount('work_order_priorities', 0);
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $events = $order->events()->count();
        $this->legacyBookmark($order);
        app(BimaAutoCloseService::class)->apply($order, $this->area);
        $this->assertSame($events, $order->events()->count());
        $this->assertDatabaseCount('work_order_priorities', 0);
        $this->assertSame(3, AuditLog::where('action', 'priority.auto_released_ps')->count());
    }

    public function test_legacy_ps_is_excluded_from_all_priority_views_but_close_waiting_bima_and_other_complete_tasks_remain(): void
    {
        $cases = [
            'PS' => ['progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE'],
            'LEGACY-PS' => ['progress_category' => ' close ', 'task_bima' => '', 'status_bima' => 'COMPWORK'],
            'FIELD-CLOSE' => ['progress_category' => 'CLOSE'],
            'TASK-COMPLETE' => ['progress_category' => 'CLOSE', 'task_bima' => 'Site Survey', 'status_bima' => 'COMPLETE'],
            'NOT-CLOSE' => ['progress_category' => 'INSTALASI', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE'],
            'NULL-PROGRESS' => ['progress_category' => null],
            'CANCEL' => ['progress_category' => 'CANCEL'],
            'ON-DESK' => ['progress_category' => 'CLOSE ON DESK', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE'],
            'ACCENT-PROGRESS' => ['progress_category' => 'CLOSÉ', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE'],
        ];
        foreach ($cases as $id => $extra) {
            $this->legacyBookmark($this->order($id, $extra));
        }
        $this->assertSame(2, WorkOrder::psEligible()->count());
        $this->assertSame(7, WorkOrder::notPsEligible()->count());
        $this->actingAs($this->area);
        $list = $this->get(route('priorities.index'))->assertOk();
        $this->assertSame(7, $list->viewData('summary')['all']);
        $this->assertEqualsCanonicalizing(array_slice(array_keys($cases), 2), $list->viewData('orders')->pluck('no_tq_sc_id')->all());
        $this->get(route('priorities.monitoring'))->assertOk()->assertViewHas('report', fn ($r) => $r['total'] === 7);
        foreach (['PS', 'LEGACY-PS'] as $id) {
            $this->get(route('priorities.index', ['q' => $id]))->assertViewHas('orders', fn ($orders) => $orders->total() === 0);
        }
        $this->assertDatabaseCount('work_order_priorities', 9);
        $before = WorkOrder::all()->map->getRawOriginal()->all();
        $this->artisan('olo:release-ps-priorities', ['--actor' => $this->area->username, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('work_order_priorities', 9);
        $this->artisan('olo:release-ps-priorities', ['--actor' => $this->area->username])->assertSuccessful();
        $this->assertDatabaseCount('work_order_priorities', 7);
        $this->assertSame(2, AuditLog::where('action', 'priority.auto_released_ps')->count());
        $this->artisan('olo:release-ps-priorities', ['--actor' => $this->area->username])->assertSuccessful();
        $this->assertSame(2, AuditLog::where('action', 'priority.auto_released_ps')->count());
        $this->assertSame($before, WorkOrder::all()->map->getRawOriginal()->all());
    }

    public function test_release_is_atomic_and_rechecks_locked_current_status(): void
    {
        $order = $this->order('ATOMIC', ['task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE']);
        app(PriorityOrderService::class)->set($this->area, $order, true);
        try {
            DB::transaction(function () use ($order) {
                app(BranchProgressService::class)->update($this->area, $order, ['revision' => 0, 'progress_category' => 'CLOSE', 'notes' => 'Selesai diuji']);
                $this->assertDatabaseCount('work_order_priorities', 0);
                throw new RuntimeException('Simulasi rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulasi rollback', $exception->getMessage());
        }
        $this->assertSame('SURVEY', $order->fresh()->progress_category);
        $this->assertDatabaseCount('work_order_priorities', 1);
        $this->assertSame(0, AuditLog::where('action', 'priority.auto_released_ps')->count());
        $order->progress_category = 'CLOSE'; // Stale in-memory PS must never release a current non-PS order.
        $this->assertSame(0, app(PriorityOrderService::class)->releaseForPs($order, $this->area));
        $this->assertDatabaseCount('work_order_priorities', 1);
        $this->artisan('olo:release-ps-priorities', ['--actor' => $this->localActor()->username])->assertFailed();
        $lock = Cache::lock('olo:work-order-inbound', 300);
        $lock->get();
        try {
            $this->artisan('olo:release-ps-priorities', ['--actor' => $this->area->username])->assertFailed();
        } finally {
            $lock->release();
        }
    }
}
