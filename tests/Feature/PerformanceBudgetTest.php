<?php

namespace Tests\Feature;

use App\Enums\Role;
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
                    'area' => $data['summary']['total'], 'branch' => $data['assigned_total'], default => $data['all']
                };
                $this->assertSame($size, $actual);
                $this->assertLessThanOrEqual(['area' => 10, 'branch' => 11, 'queues' => 1][$name], count($queries), $name);
            }
        }
        foreach (array_keys($results[20]) as $name) {
            $this->assertSame($results[20][$name]['queries'], $results[1000][$name]['queries'], $name);
        }
        // Synthetic, no credentials/customer data; timings are observations, not flaky pass/fail thresholds.
        file_put_contents('/private/tmp/olo-performance-profile-'.DB::getDriverName().'.json', json_encode($results, JSON_PRETTY_PRINT));
    }
}
