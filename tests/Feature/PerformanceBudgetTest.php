<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;
use App\Services\AreaAnalyticsService;
use App\Services\BranchAnalyticsService;
use App\Services\BranchOrderScope;
use App\Services\PriorityOrderService;
use App\Services\WorkOrderQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PerformanceBudgetTest extends OloTestCase
{
    public function test_scoped_list_permissions_do_not_query_once_per_rendered_order(): void
    {
        $results = [];
        foreach ([5, 30] as $size) {
            for ($i = WorkOrder::count(); $i < $size; $i++) {
                $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'LIST-PERF-'.$i, 'id_branches' => $this->branch->getKey(), 'jenis_pekerjaan' => 'NEW INSTALL',
                    'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', (string) $i)]);
                app(PriorityOrderService::class)->set($this->area, $order, true);
            }
            $this->actingAs($this->area);
            foreach (['work-orders.index', 'priorities.index'] as $route) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $page = $this->get(route($route));
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }
                $page->assertOk()->assertSee('Update Progress')->assertSee('Lepas Prioritas Area');
                $this->assertCount(min(25, $size), $page->viewData('orders'));
                $this->assertLessThanOrEqual(7, count($queries));
                $results[$size][$route] = count($queries);
            }
        }
        $this->assertSame($results[5], $results[30]);
        file_put_contents('/private/tmp/olo-list-query-profile-'.DB::getDriverName().'.json', json_encode($results, JSON_PRETTY_PRINT));
    }

    public function test_priority_pagination_reuses_counts_with_branch_filter_and_every_monitoring_state(): void
    {
        $branch = $this->user(Role::BranchHd);
        $branch->branches()->attach($this->branch);
        $service = app(PriorityOrderService::class);
        $expected = ['all' => [], 'outstanding' => [], 'issues' => [], 'waiting_bima' => [], 'closed' => []];
        for ($i = 0; $i < 34; $i++) {
            $category = match ($i) {
                30 => 'HOLD', 31, 32 => 'CLOSE', 33 => 'CANCEL', default => 'SURVEY'
            };
            $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'PAGING-'.$i, 'id_branches' => $this->branch->getKey(),
                'progress_category' => $category, 'tgl_order' => now()->subDays($i)->toDateString(), 'last_progress_at' => now()->subMinutes($i),
                'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK', 'status_order' => 'Site Survey-STARTWORK',
                'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', 'paging-'.$i)]);
            $service->set($this->area, $order, true);
            $service->set($branch, $order, true);
            $expected['all'][] = $order->getKey();
            if ($i <= 30) {
                $expected['outstanding'][] = $order->getKey();
            }
            if ($i === 30) {
                $order->issues()->create(['id_users' => $branch->getKey(), 'category' => 'OTHER', 'description' => 'Synthetic issue', 'pic' => 'PIC']);
                $expected['issues'][] = $order->getKey();
            }
            if (in_array($i, [31, 32], true)) {
                $expected['closed'][] = $order->getKey();
                $expected['waiting_bima'][] = $order->getKey();
            }
        }
        // Area must not count Branch-only selections, even when they match the search/Branch filter.
        $private = $order->replicate()->fill(['no_tq_sc_id' => 'PAGING-PRIVATE', 'progress_category' => 'SURVEY']);
        $private->save();
        $service->set($branch, $private, true);
        $other = Branch::create(['code' => 'PAGING-OTHER', 'name' => 'Other Paging Branch']);
        $foreign = $private->replicate()->fill(['no_tq_sc_id' => 'PAGING-FOREIGN', 'id_branches' => $other->getKey()]);
        $foreign->save();
        $service->set($this->area, $foreign, true);
        foreach ([$this->area, $branch] as $actor) {
            $this->actingAs($actor);
            $actorExpected = $expected;
            if ($actor->is($branch)) {
                $actorExpected['all'][] = $private->getKey();
                $actorExpected['outstanding'][] = $private->getKey();
            }
            foreach ($actorExpected as $state => $ids) {
                foreach ($state === 'all' ? [1, 2] : [1] as $number) {
                    $filters = ['q' => 'PAGING-', 'branch' => $this->branch->getKey(), 'state' => $state,
                        'sort' => match ($state) {
                            'all' => 'age', 'issues' => 'updated', default => 'selected'
                        }, 'page' => $number];
                    DB::flushQueryLog();
                    DB::enableQueryLog();
                    try {
                        $page = $this->get(route('priorities.index', $filters))->assertOk();
                        $queries = DB::getQueryLog();
                    } finally {
                        DB::disableQueryLog();
                    }
                    $orders = $page->viewData('orders');
                    $this->assertSame(count($ids), $orders->total(), $state);
                    $this->assertCount(min(25, count($ids) - ($number - 1) * 25), $orders);
                    $this->assertSame([], array_diff($orders->modelKeys(), $ids));
                    $this->assertSame(array_map('count', array_intersect_key($actorExpected, array_flip(['all', 'outstanding', 'issues', 'waiting_bima']))), $page->viewData('summary'));
                    $this->assertLessThanOrEqual(8, count($queries), $state);
                }
            }
            $this->get(route('priorities.index', ['q' => 'ABSENT-PAGING', 'state' => 'closed']))
                ->assertViewHas('orders', fn ($orders) => $orders->total() === 0 && $orders->isEmpty());
        }
    }

    public function test_dashboard_and_queue_query_budgets_do_not_grow_with_order_volume(): void
    {
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $results = [];
        foreach ([20, 1000] as $size) {
            $records = [];
            for ($i = WorkOrder::count(); $i < $size; $i++) {
                $records[] = ['source_system' => 'BIMA', 'no_tq_sc_id' => 'PERF-'.$i, 'id_branches' => $this->branch->getKey(), 'jenis_pekerjaan' => 'NEW INSTALL',
                    'tgl_order' => '2026-10-20', 'source_bima_fields' => '{}', 'source_snapshot_hash' => hash('sha256', (string) $i),
                    'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK', 'status_order' => 'Site Survey-STARTWORK', 'progress_category' => 'SURVEY'];
            }
            foreach (array_chunk($records, 100) as $chunk) {
                DB::table('work_orders')->insert($chunk);
            }
            foreach (['area' => fn () => app(AreaAnalyticsService::class)->summary($this->area, []),
                'area_owner' => fn () => app(AreaAnalyticsService::class)->summary($this->area, ['tab' => 'owner-group']),
                'area_ach' => fn () => app(AreaAnalyticsService::class)->summary($this->area, ['tab' => 'ach-ps']),
                'branch' => fn () => app(BranchAnalyticsService::class)->summary($actor, []),
                'queues' => fn () => app(WorkOrderQueueService::class)->counts(app(BranchOrderScope::class)->orders($actor, []), CarbonImmutable::now('Asia/Jakarta'))] as $name => $callback) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $start = hrtime(true);
                try {
                    $data = $callback();
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }
                $results[$size][$name] = ['queries' => count($queries), 'ms' => round((hrtime(true) - $start) / 1e6, 2)];
                $actual = match ($name) {
                    'area', 'area_owner', 'area_ach' => $data['summary']['total'], 'branch' => $data['assigned_total'], default => $data['all']
                };
                $this->assertSame($size, $actual);
                $this->assertLessThanOrEqual(['area' => 5, 'area_owner' => 5, 'area_ach' => 4, 'branch' => 11, 'queues' => 1][$name], count($queries), $name);
            }
        }
        foreach (array_keys($results[20]) as $name) {
            $this->assertSame($results[20][$name]['queries'], $results[1000][$name]['queries'], $name);
        }
        // Synthetic, no credentials/customer data; timings are observations, not flaky pass/fail thresholds.
        file_put_contents('/private/tmp/olo-performance-profile-'.DB::getDriverName().'.json', json_encode($results, JSON_PRETTY_PRINT));
    }

    public function test_area_tabs_only_query_their_reports_and_reflect_updates_without_cached_user_data(): void
    {
        $this->branch->update(['region_id' => 1]);
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'TAB-PERF', 'id_branches' => $this->branch->getKey(), 'jenis_pekerjaan' => 'MODIFY',
            'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'progress_category' => 'CLOSE', 'tgl_ps' => now('Asia/Jakarta')->toDateString(),
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', 'tab-performance')]);
        $summaries = [];
        foreach (['progress', 'owner-group', 'ach-ps'] as $tab) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $page = $this->actingAs($this->area)->get(route('area.dashboard', ['tab' => $tab]))->assertOk()->assertDontSee('Rekap jenis pekerjaan');
                $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));
            } finally {
                DB::disableQueryLog();
            }
            $summaries[$tab] = $page->viewData('summary');
            $page->assertViewMissing('recap');
            $this->assertSame($tab === 'progress', $page->viewData('mappingOrders') !== null);
            $this->assertSame($tab === 'progress', $page->viewData('orderMonths') !== null);
            $this->assertSame($tab === 'owner-group', $page->viewData('ownerGroups') !== null);
            $this->assertSame($tab === 'ach-ps', $page->viewData('psDailyTable') !== null);
            $this->assertSame($tab === 'owner-group', str_contains($sql, 'mapping_ownergrup'));
            $this->assertSame($tab === 'ach-ps', str_contains($sql, 'as ps_date'));
            $this->assertSame($tab === 'progress', str_contains($sql, 'as order_month'));
        }
        $this->assertSame($summaries['progress'], $summaries['owner-group']);
        $this->assertSame($summaries['progress'], $summaries['ach-ps']);
        $order->update(['progress_category' => 'SURVEY']);
        foreach (['progress', 'owner-group', 'ach-ps'] as $tab) {
            $this->get(route('area.dashboard', ['tab' => $tab]))->assertOk()
                ->assertViewHas('summary', fn ($data) => $data['ps_total'] === 0 && $data['total'] === 1);
            $this->actingAs($this->user(Role::BranchHd))->get(route('area.dashboard', ['tab' => $tab]))->assertForbidden();
            $this->actingAs($this->area);
        }
    }

    public function test_dashboard_modal_queries_stay_bounded_without_loading_unused_source_or_bookmarks(): void
    {
        $this->actingAs($this->area);
        $counts = [];
        foreach ([5, 30] as $size) {
            for ($i = WorkOrder::count(); $i < $size; $i++) {
                $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'MODAL-PERF-'.$i, 'id_branches' => $this->branch->getKey(),
                    'jenis_pekerjaan' => 'NEW INSTALL', 'source_bima_fields' => ['reference' => str_repeat('private-source', 100)], 'source_snapshot_hash' => hash('sha256', 'modal-'.$i)]);
                app(PriorityOrderService::class)->set($this->area, $order, true);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $page = $this->get(route('work-orders.index', ['modal' => 1]))->assertOk()->assertDontSee('private-source');
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $counts[$size] = count($queries);
            $this->assertLessThanOrEqual(3, $counts[$size]);
            $this->assertSame($size, $page->viewData('orders')->total());
            $this->assertStringNotContainsString('work_order_priorities', implode("\n", array_column($queries, 'query')));
            $this->assertStringNotContainsString('wo_issues', implode("\n", array_column($queries, 'query')));
            foreach ($page->viewData('orders') as $order) {
                $this->assertArrayNotHasKey('source_bima_fields', $order->getAttributes());
                $this->assertFalse($order->relationLoaded('priorities'));
                $page->assertSee(route('work-orders.show', $order));
            }
        }
        $this->assertSame($counts[5], $counts[30]);
        file_put_contents('/private/tmp/olo-modal-query-profile-'.DB::getDriverName().'.json', json_encode($counts));
    }
}
