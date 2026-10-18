<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\WorkOrder;
use Carbon\Carbon;

class BranchAnalyticsTest extends OloTestCase
{
    public function test_metrics_and_daily_chart_use_branch_scope_jakarta_boundaries_job_type_and_current_terminal_states(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 12:00:00', 'UTC'));
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Branch Other']);
        BranchMapping::create(['bima_branch' => 'OTHER', 'id_branches' => $other->getKey()]);
        $this->pull([
            $this->row('TODAY-CLOSE', [1 => '2026-10-18']), $this->row('PREVIOUS-CLOSE', [1 => '2026-10-17']),
            $this->row('OPEN', [1 => '2026-10-18']), $this->row('CANCEL', [1 => '2026-10-18']),
            $this->row('DESK', [1 => '2026-10-18']), $this->row('MODIFY', [1 => '2026-10-18', 11 => 'MODIFY']),
            $this->row('OTHER-BRANCH', [1 => '2026-10-18', 5 => 'OTHER']),
        ]);
        foreach (['TODAY-CLOSE' => '2026-10-17 17:00:00', 'PREVIOUS-CLOSE' => '2026-10-17 16:59:59', 'MODIFY' => '2026-10-18 03:00:00', 'OTHER-BRANCH' => '2026-10-18 03:00:00'] as $id => $date) {
            WorkOrder::where('external_order_id', $id)->update(['progress_category' => 'CLOSE', 'operational_status' => 'FIELD_DONE', 'branch_completed_at' => $date, 'last_progress_at' => $date, 'bima_status' => 'COMPLETE', 'bima_ps_date' => Carbon::parse($date, 'UTC')->timezone('Asia/Jakarta')->toDateString()]);
        }
        WorkOrder::where('external_order_id', 'CANCEL')->update(['progress_category' => 'CANCEL', 'operational_status' => 'CANCELLED']);
        WorkOrder::where('external_order_id', 'DESK')->update(['progress_category' => 'CLOSE ON DESK', 'operational_status' => 'PENDING_VALIDATION']);
        WorkOrder::where('external_order_id', 'OPEN')->firstOrFail()->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'ACCESS', 'description' => 'Uji', 'pic' => 'PIC']);
        $branchUser = $this->user();
        $branchUser->branches()->attach($this->branch);
        $response = $this->actingAs($branchUser)->get(route('branch.dashboard'))->assertOk()->assertViewHas('incoming', 4)->assertViewHas('complete', 1)->assertViewHas('outstanding', 1)->assertViewHas('issues', 1)->assertViewHas('period_complete', 2)->assertDontSee('OTHER-BRANCH');
        $series = collect($response->viewData('series'))->keyBy('date');
        $this->assertSame(14, $series->count());
        $this->assertSame(1, $series['2026-10-18']['count']);
        $this->assertSame(1, $series['2026-10-17']['count']);
        $this->get(route('branch.dashboard', ['job_type' => 'all', 'days' => 7]))->assertViewHas('incoming', 5)->assertViewHas('complete', 2)->assertViewHas('period_complete', 3);
        $this->get(route('work-orders.index', ['queue' => 'ps_today']))->assertOk()->assertSee('TODAY-CLOSE')->assertDontSee('PREVIOUS-CLOSE')->assertDontSee('OTHER-BRANCH');
        $this->get(route('work-orders.index', ['queue' => 'today']))->assertOk()->assertSee('TODAY-CLOSE')->assertSee('OPEN')->assertDontSee('PREVIOUS-CLOSE')->assertDontSee('OTHER-BRANCH');
        $this->get(route('work-orders.index', ['queue' => 'outstanding']))->assertOk()->assertSee('OPEN')->assertDontSee('TODAY-CLOSE')->assertDontSee('PREVIOUS-CLOSE');
        $this->get(route('branch.dashboard', ['days' => 500]))->assertSessionHasErrors('days');
    }
}
