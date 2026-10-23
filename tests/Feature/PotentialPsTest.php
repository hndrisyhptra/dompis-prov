<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;

class PotentialPsTest extends OloTestCase
{
    public function test_all_eight_combinations_match_and_near_matches_do_not_become_confirmed_ps(): void
    {
        $statuses = [
            ['Activate Access Manual', 'STARTWORK'],
            ['Activate Cross Connect', 'STARTWORK'],
            ['Activate Global', 'COMPLETE'],
            ['Activate Metro Manual', 'COMPLETE'],
            ['Activate Metro Manual', 'STARTWORK'],
            ['Activate NCIX', 'STARTWORK'],
            ['Activate Service', 'STARTWORK'],
            ['Activation Power', 'STARTWORK'],
        ];
        $rows = [];
        foreach ($statuses as $i => [$task, $status]) {
            $rows[] = $this->row('POTENTIAL-'.$i, [14 => $task, 13 => $status]);
        }
        foreach ([['Activate Access Manual', 'COMPLETE'], ['Activate Global', 'STARTWORK'], ['Activation Power', ''], ['Other Activate Service', 'STARTWORK'], ['Activate Service', 'STARTWORK-OTHER'], ['', 'COMPLETE']] as $i => [$task, $status]) {
            $rows[] = $this->row('EXCLUDED-'.$i, [14 => $task, 13 => $status]);
        }
        $this->pull($rows);
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $this->actingAs($this->area);
        $dashboard = $this->get(route('area.dashboard'))->assertOk()->assertSee('Potensi PS');
        $this->assertSame(8, $dashboard->viewData('summary')['potential_ps']);
        $this->assertSame(0, $dashboard->viewData('summary')['ps_total']);
        $list = $this->get(route('work-orders.index', ['queue' => 'potential_ps', 'jenis_pekerjaan' => 'all']))->assertOk()->assertViewHas('total', 8)->assertDontSee('EXCLUDED-');
        $this->assertSame(8, $list->viewData('queueCounts')['potential_ps']);
        foreach ($statuses as $i => [$task, $status]) {
            $list->assertSee('POTENTIAL-'.$i)->assertSee($task.'-'.$status);
        }
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
    }

    public function test_dashboard_links_and_list_preserve_scope_type_region_and_service_filters(): void
    {
        $this->branch->update(['region_id' => 1]);
        $other = Branch::create(['code' => 'POT-OTHER', 'name' => 'Cabang lain', 'region_id' => 2]);
        $this->pull([
            $this->row('OWN-NEW', [14 => 'Activate Service', 13 => 'STARTWORK']),
            $this->row('OWN-MODIFY', [14 => 'Activate Metro Manual', 13 => 'COMPLETE', 11 => 'MODIFY']),
            $this->row('OWN-INET', [14 => 'Activate NCIX', 13 => 'STARTWORK', 10 => 'INTERNET']),
            $this->row('FOREIGN', [14 => 'Activation Power', 13 => 'STARTWORK']),
        ]);
        WorkOrder::where('no_tq_sc_id', 'FOREIGN')->update(['id_branches' => $other->getKey()]);
        $filters = ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY'];
        $this->actingAs($this->area)->get(route('area.dashboard', $filters))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['potential_ps'] === 1)
            ->assertSee(route('work-orders.index', $filters + ['queue' => 'potential_ps']));
        $this->get(route('work-orders.index', $filters + ['queue' => 'potential_ps']))->assertOk()->assertViewHas('total', 1)->assertSee('OWN-MODIFY')->assertDontSee('OWN-NEW')->assertDontSee('FOREIGN')->assertDontSee('OWN-INET');

        foreach ([Role::BranchAdmin, Role::BranchHd, Role::TeamLeader] as $role) {
            $user = $this->user($role);
            $user->branches()->attach($this->branch);
            $this->actingAs($user)->get(route('branch.dashboard'))->assertOk()->assertViewHas('potential_ps', 2)
                ->assertSee(route('work-orders.index', ['jenis_pekerjaan' => 'NEW INSTALL', 'queue' => 'potential_ps']));
            $this->get(route('work-orders.index', ['queue' => 'potential_ps']))->assertOk()->assertViewHas('total', 2)->assertDontSee('FOREIGN')->assertDontSee('OWN-MODIFY');
            $this->get(route('branch.dashboard', ['jenis_pekerjaan' => 'all']))->assertViewHas('potential_ps', 3);
            $this->get(route('work-orders.index', ['queue' => 'potential_ps', 'jenis_pekerjaan' => 'all']))->assertViewHas('total', 3);
            $this->get(route('work-orders.index', ['queue' => 'potential_ps', 'branch' => $other->getKey()]))->assertForbidden();
            $this->get(route('branch.dashboard', ['branch' => $other->getKey()]))->assertForbidden();
        }
    }

    public function test_reimport_changes_potential_count_without_overwriting_branch_progress_and_empty_queue_works(): void
    {
        $this->pull([$this->row('CHANGING', [14 => 'Activate Global', 13 => 'COMPLETE'])]);
        $order = WorkOrder::firstOrFail();
        $order->update(['progress_category' => 'CLOSE', 'branch_completed_at' => now()]);
        $this->assertSame(1, WorkOrder::potentialPs()->count());
        $this->pull([$this->row('CHANGING', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE'])]);
        $this->assertSame(0, WorkOrder::potentialPs()->count());
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['potential_ps'] === 0 && $summary['ps_total'] === 1);
        $this->get(route('work-orders.index', ['queue' => 'potential_ps']))->assertOk()->assertViewHas('total', 0)->assertSee('Belum ada WO untuk filter ini');
        $unassigned = $this->user();
        $this->actingAs($unassigned)->get(route('branch.dashboard'))->assertOk()->assertViewHas('potential_ps', 0);
        $this->get(route('work-orders.index', ['queue' => 'potential_ps']))->assertOk()->assertViewHas('total', 0);
    }
}
