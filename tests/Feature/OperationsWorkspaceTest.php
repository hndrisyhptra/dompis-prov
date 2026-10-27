<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;

class OperationsWorkspaceTest extends OloTestCase
{
    public function test_queue_counts_filters_and_close_waiting_bima_are_consistent_and_branch_scoped(): void
    {
        $this->pull([$this->row('NEW'), $this->row('ISSUE'), $this->row('WAIT')]);
        $issue = WorkOrder::where('no_tq_sc_id', 'ISSUE')->firstOrFail();
        $issue->update(['progress_category' => 'KENDALA', 'operational_status' => 'BLOCKED', 'last_progress_at' => now()]);
        $issue->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'OTHER', 'description' => 'Akses tertunda', 'pic' => 'PIC']);
        WorkOrder::where('no_tq_sc_id', 'WAIT')->firstOrFail()->update(['progress_category' => 'CLOSE', 'branch_completed_at' => now()]);
        $other = Branch::create(['code' => 'FOREIGN', 'name' => 'Cabang lain']);
        WorkOrder::create(['no_tq_sc_id' => 'FOREIGN', 'id_branches' => $other->getKey(), 'jenis_pekerjaan' => 'NEW INSTALL', 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('b', 64)]);
        $user = $this->user(Role::TeamLeader);
        $user->branches()->attach($this->branch);
        $response = $this->actingAs($user)->get(route('work-orders.index'))->assertOk()->assertDontSee('FOREIGN')->assertSee('POV Branch')->assertSee('wo-mobile-list', false);
        $this->assertSame(['all' => 3, 'unupdated' => 1, 'outstanding' => 2, 'issues' => 1, 'closed' => 1, 'waiting_bima' => 1, 'complete' => 0, 'potential_ps' => 0, 'new_order' => 0], $response->viewData('queueCounts'));
        $this->get(route('work-orders.index', ['queue' => 'waiting_bima']))->assertViewHas('total', 1)->assertSee('WAIT')->assertDontSee('Akses tertunda');
        $this->get(route('work-orders.index', ['queue' => 'closed']))->assertViewHas('total', 1);
        $this->get(route('work-orders.index', ['status' => 'KENDALA']))->assertViewHas('total', 1)->assertViewHas('queueCounts', fn ($counts) => $counts['issues'] === 1 && $counts['unupdated'] === 0 && $counts['closed'] === 0);
        $this->get(route('work-orders.index', ['q' => 'NEW']))->assertViewHas('total', 1)->assertViewHas('queueCounts', fn ($counts) => $counts['all'] === 1 && $counts['unupdated'] === 1);
        $this->get(route('work-orders.index', ['q' => 'NOT-FOUND']))->assertViewHas('total', 0)->assertViewHas('queueCounts', fn ($counts) => array_sum($counts) === 0);
        $this->get(route('branch.dashboard'))->assertOk()->assertViewHas('waiting_bima', 1)->assertViewHas('closed_by_branch', 1)->assertViewHas('ps_total', 0);
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertDontSee('Operasional Area ke Branch')->assertSee('Sync Center');
    }

    public function test_actions_prefill_the_right_form_and_keep_terminal_and_device_permissions(): void
    {
        $this->pull([$this->row()]);
        $order = WorkOrder::sole();
        foreach ([Role::TeamLeader, Role::BranchHd, Role::BranchAdmin] as $role) {
            $user = $this->user($role);
            $user->branches()->attach($this->branch);
            $this->actingAs($user)->get(route('work-orders.action.edit', [$order, 'issue']))->assertOk()->assertSee('value="HOLD"', false)->assertDontSee('value="KENDALA"', false)->assertSee('Detail kendala')->assertSee('Simpan kendala');
            $close = $this->get(route('work-orders.action.edit', [$order, 'close']))->assertOk()->assertSee('value="CLOSE" selected', false)->assertSee('Ringkasan penyelesaian')->assertSee('Konfirmasi CLOSE')->assertSee('0/6 terisi');
            if ($role === Role::TeamLeader) {
                $close->assertDontSee('data-open-devices', false);
            }
        }
        $this->getJson(route('work-orders.show', [$order, 'action' => 'invalid']))->assertUnprocessable();
        $order->update(['progress_category' => 'CLOSE', 'branch_completed_at' => now()]);
        $this->get(route('work-orders.action.edit', [$order, 'issue']))->assertForbidden();
        $this->get(route('work-orders.action.edit', [$order, 'close']))->assertOk()->assertSee('Tambahkan catatan');
        $this->actingAs($this->user(Role::BranchHd))->get(route('work-orders.action.edit', [$order, 'close']))->assertForbidden();
        $this->actingAs($this->user(Role::MaterialAdmin))->get(route('work-orders.index'))->assertOk()->assertDontSee('/actions/close');
    }
}
