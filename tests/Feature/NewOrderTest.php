<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;

class NewOrderTest extends OloTestCase
{
    public function test_seven_review_statuses_enter_new_order_and_near_matches_are_excluded(): void
    {
        $statuses = [
            ['Review Order TSQ Metro Manual', 'COMPLETE'],
            ['Review Order TSQ Metro Manual', 'STARTWORK'],
            ['Review Order TSQ Modify Metro Manual', 'COMPLETE'],
            ['Review Order TSQ Modify Metro Manual', 'STARTWORK'],
            ['Review Order TSQ WDM', 'COMPLETE'],
            ['Review Order TSQ WDM', 'STARTWORK'],
            ['Review Order TSQ', 'STARTWORK'],
        ];
        $rows = [];
        foreach ($statuses as $i => [$task, $status]) {
            $rows[] = $this->row('REVIEW-'.$i, [1 => '2025-01-01', 14 => $task, 13 => $status]);
        }
        foreach ([['Review Order TSQ', 'COMPLETE'], ['Review Order TSQ Metro Manual', 'CLOSE'], ['Review Order TSQ WDM', ''], ['Other Review Order TSQ', 'STARTWORK'], ['Review Order TSQ', 'STARTWORK-OTHER'], ['', 'STARTWORK'], ['Activate Service', 'STARTWORK']] as $i => [$task, $status]) {
            $rows[] = $this->row('EXCLUDED-'.$i, [14 => $task, 13 => $status]);
        }
        $this->pull($rows);
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $this->actingAs($this->area);
        $dashboard = $this->get(route('area.dashboard'))->assertOk()->assertSee('New Order');
        $this->assertSame(7, $dashboard->viewData('summary')['new_order']);
        $this->assertSame(1, $dashboard->viewData('summary')['potential_ps']);
        $this->assertSame(0, $dashboard->viewData('summary')['ps_total']);
        $response = $this->get(route('work-orders.index', ['queue' => 'new_order', 'jenis_pekerjaan' => 'all']))
            ->assertOk()->assertViewHas('total', 7)->assertSee('List New Order')->assertSee('Status Bima')->assertDontSee('EXCLUDED-');
        $this->assertSame(7, $response->viewData('queueCounts')['new_order']);
        foreach ($statuses as $i => [$task, $status]) {
            $response->assertSee('REVIEW-'.$i)->assertSee($task.'-'.$status);
        }
        $this->get(route('work-orders.index', ['queue' => 'new_order', 'q' => 'REVIEW-0']))->assertViewHas('total', 1)->assertViewHas('queueCounts', fn ($counts) => $counts['new_order'] === 1);
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
    }

    public function test_area_and_branch_cards_and_lists_share_the_same_filters_and_permissions(): void
    {
        $this->branch->update(['region_id' => 1]);
        $other = Branch::create(['code' => 'REVIEW-OTHER', 'name' => 'Cabang Lain', 'region_id' => 2]);
        $this->pull([
            $this->row('OWN-NEW', [14 => 'Review Order TSQ', 13 => 'STARTWORK']),
            $this->row('OWN-MODIFY', [14 => 'Review Order TSQ Modify Metro Manual', 13 => 'COMPLETE', 11 => 'MODIFY']),
            $this->row('OWN-INET', [14 => 'Review Order TSQ WDM', 13 => 'STARTWORK', 10 => 'INTERNET']),
            $this->row('FOREIGN', [14 => 'Review Order TSQ Metro Manual', 13 => 'COMPLETE']),
        ]);
        WorkOrder::where('no_tq_sc_id', 'FOREIGN')->update(['id_branches' => $other->getKey()]);
        $filters = ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY'];
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $this->actingAs($this->user($role))->get(route('area.dashboard', $filters))->assertOk()
                ->assertViewHas('summary', fn ($summary) => $summary['new_order'] === 1)
                ->assertSee(route('work-orders.index', $filters + ['queue' => 'new_order']));
            $this->get(route('work-orders.index', $filters + ['queue' => 'new_order']))->assertOk()->assertViewHas('total', 1)->assertSee('OWN-MODIFY')->assertDontSee('OWN-NEW')->assertDontSee('OWN-INET')->assertDontSee('FOREIGN');
        }
        foreach ([Role::BranchAdmin, Role::BranchHd, Role::TeamLeader] as $role) {
            $user = $this->user($role);
            $user->branches()->attach($this->branch);
            $this->actingAs($user)->get(route('branch.dashboard'))->assertOk()->assertViewHas('new_order', 2)
                ->assertSee(route('work-orders.index', ['jenis_pekerjaan' => 'NEW INSTALL', 'queue' => 'new_order']));
            $this->get(route('work-orders.index', ['queue' => 'new_order']))->assertOk()->assertViewHas('total', 2)->assertDontSee('FOREIGN')->assertDontSee('OWN-MODIFY');
            $this->get(route('branch.dashboard', ['jenis_pekerjaan' => 'all']))->assertViewHas('new_order', 3);
            $this->get(route('work-orders.index', ['queue' => 'new_order', 'jenis_pekerjaan' => 'all']))->assertViewHas('total', 3);
            $this->get(route('work-orders.index', ['queue' => 'new_order', 'branch' => $other->getKey()]))->assertForbidden();
            $this->get(route('branch.dashboard', ['branch' => $other->getKey()]))->assertForbidden();
        }
    }

    public function test_reimport_moves_order_between_new_order_and_potential_ps_without_losing_progress(): void
    {
        $this->pull([$this->row('MOVING', [14 => 'Review Order TSQ', 13 => 'STARTWORK'])]);
        $order = WorkOrder::firstOrFail();
        $order->update(['progress_category' => 'SURVEY', 'last_progress_at' => now()]);
        $this->pull([$this->row('MOVING', [14 => 'Review Order TSQ', 13 => 'STARTWORK'])]);
        $this->assertSame(1, WorkOrder::newOrder()->count());
        $this->pull([$this->row('MOVING', [14 => 'Activate Service', 13 => 'STARTWORK'])]);
        $this->assertSame(0, WorkOrder::newOrder()->count());
        $this->assertSame(1, WorkOrder::potentialPs()->count());
        $this->assertSame(1, WorkOrder::count());
        $this->assertSame('SURVEY', $order->fresh()->progress_category);
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertViewHas('summary', fn ($summary) => $summary['new_order'] === 0 && $summary['potential_ps'] === 1);
        $this->get(route('work-orders.index', ['queue' => 'new_order']))->assertOk()->assertViewHas('total', 0)->assertSee('Belum ada WO untuk filter ini');
        $this->actingAs($this->user())->get(route('branch.dashboard'))->assertOk()->assertViewHas('new_order', 0)->assertViewHas('potential_ps', 0);
        $this->get(route('work-orders.index', ['queue' => 'new_order']))->assertOk()->assertViewHas('total', 0);
    }
}
