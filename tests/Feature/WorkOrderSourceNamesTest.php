<?php

namespace Tests\Feature;

use App\Models\WorkOrder;

class WorkOrderSourceNamesTest extends OloTestCase
{
    public function test_import_uses_physical_indonesian_columns_branch_relation_and_existing_filter_urls(): void
    {
        $this->pull([$this->row('000123'), $this->row('000456', [11 => 'MODIFY'])]);
        $this->assertDatabaseHas('work_orders', ['no_tq_sc_id' => '000123', 'branch' => 'BRANCH UJI', 'sto' => 'TST', 'nama_olo' => 'Pelanggan Uji', 'jenis_pekerjaan' => 'NEW INSTALL', 'sid' => 'SID-0099']);
        $order = WorkOrder::where('no_tq_sc_id', '000123')->firstOrFail();
        $this->assertSame('2026-10-17', $order->tgl_order->toDateString());
        $this->assertSame('BRANCH UJI', $order->branch);
        $this->assertSame($this->branch->getKey(), $order->assignedBranch->getKey());
        $this->actingAs($this->area)->get(route('work-orders.show', $order))->assertOk()->assertSee('Branch Uji')->assertSee('000123');
        $this->get(route('work-orders.index', ['job_type' => 'MODIFY']))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->pluck('no_tq_sc_id')->all() === ['000456']);
        $this->get(route('area.dashboard', ['job_type' => 'MODIFY']))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['total'] === 1);
        $this->get(route('branch.dashboard', ['job_type' => 'MODIFY']))->assertOk()->assertViewHas('assigned_total', 1);
    }
}
