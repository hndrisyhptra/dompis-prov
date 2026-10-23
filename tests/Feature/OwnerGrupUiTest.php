<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\WorkOrder;

class OwnerGrupUiTest extends OloTestCase
{
    public function test_confirmed_ps_widgets_are_removed_while_daily_ps_and_branch_scope_remain(): void
    {
        $this->pull([$this->row()]);
        $order = WorkOrder::sole();
        $order->update(['owner_grup' => 'OWNER UJI', 'progress_category' => 'CLOSE', 'status_bima' => 'COMPLETE']);
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertDontSee('PS terkonfirmasi')->assertSee('PS hari ini')->assertSee('Potensi PS')->assertSee('New Order');
        foreach ([Role::BranchAdmin, Role::BranchHd, Role::TeamLeader] as $role) {
            $user = $this->user($role);
            $user->branches()->attach($this->branch);
            $this->actingAs($user)->get(route('branch.dashboard'))->assertOk()->assertDontSee('PS terkonfirmasi')->assertSee('PS / complete hari ini');
            $this->get(route('work-orders.index'))->assertOk()->assertDontSee('PS terkonfirmasi');
            $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Owner Group')->assertSee('OWNER UJI')->assertDontSee('Konfirmasi PS')->assertDontSee('PS terkonfirmasi');
        }
        $this->actingAs($this->user())->get(route('work-orders.show', $order))->assertForbidden();
    }
}
