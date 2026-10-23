<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Carbon\Carbon;

class PsEligibilityTest extends OloTestCase
{
    public function test_all_fourteen_full_bima_statuses_require_close_and_sql_matches_instance_and_dashboards(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 05:00:00', 'UTC'));
        $statuses = [
            'Approval E2E Testing Bitstream NNI-COMPLETE', 'Approval E2E Testing Bitstream Transport-COMPLETE',
            'Approval E2E Testing Manual-COMPLETE', 'Approval E2E Testing VPN-COMPLETE', 'Approval E2E Testing-COMPLETE',
            'Approval End to End Test-COMPLETE', 'Approval Project Management-COMPLETE', '-COMPWORK',
            'Modify Access VPN-COMPLETE', 'Suspend METRO Manual-COMPLETE', 'Testing and Approval METRO Manual-COMPLETE',
            'Testing and Approval-COMPLETE', 'Testing dan Approval WDM-COMPLETE', 'Testing dan Approval-COMPLETE',
        ];
        $expected = [];
        foreach ($statuses as $i => $status) {
            $split = strrpos($status, '-');
            $task = substr($status, 0, $split);
            $raw = substr($status, $split + 1);
            $id = 'APPROVED-'.$i;
            $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
                'task_bima' => $task, 'status_bima' => $raw, 'progress_category' => 'CLOSE', 'jenis_pekerjaan' => 'NEW INSTALL',
                'tgl_ps' => '2026-10-23', 'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
            $this->assertTrue($order->isPsEligible(), $status);
            $expected[] = $id;
        }
        $cases = [
            'GENERIC-COMPLETE' => ['CLOSE', 'Unknown task', 'COMPLETE'],
            'GENERIC-CLOSE' => ['CLOSE', 'Approval E2E Testing Manual', 'CLOSE'],
            'WAIT-OPEN' => ['CLOSE', 'Approval E2E Testing Manual', 'STARTWORK'],
            'WAIT-NULL' => ['CLOSE', null, null],
            'REJECT-SUBSTRING' => ['CLOSE', 'Approval E2E Testing Manual', 'INCOMPLETE'],
            'REJECT-OTHER-CODE' => ['CLOSE', 'Approval E2E Testing Manual', 'COMPLETED'],
            'REJECT-DESK' => ['CLOSE ON DESK', 'Approval E2E Testing Manual', 'COMPLETE'],
            'REJECT-CANCEL' => ['CANCEL', 'Approval E2E Testing Manual', 'COMPLETE'],
            'REJECT-SURVEY' => ['SURVEY', 'Approval E2E Testing Manual', 'COMPLETE'],
            'REJECT-MISSING-PROGRESS' => [null, 'Approval E2E Testing Manual', 'COMPLETE'],
            'REJECT-TASK-PREFIX' => ['CLOSE', 'Other Approval E2E Testing Manual', 'COMPLETE'],
            'REJECT-TASK-ACCENT' => ['CLOSE', 'Appróval E2E Testing Manual', 'COMPLETE'],
            'REJECT-STATUS-SUFFIX' => ['CLOSE', 'Approval E2E Testing Manual', 'COMPLETE-OTHER'],
            'REJECT-PROGRESS-ACCENT' => ['CLÓSE', 'Approval E2E Testing Manual', 'COMPLETE'],
            'REJECT-COMPWORK-TASK' => ['CLOSE', 'Other task', 'COMPWORK'],
            'REJECT-COMPWORK-STATE' => ['CLOSE', null, 'COMPLETE'],
            'NORMALIZED' => [' close ', 'approval e2e testing manual', 'complete'],
        ];
        foreach ($cases as $id => [$progress, $task, $raw]) {
            $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
                'task_bima' => $task, 'status_bima' => $raw, 'progress_category' => $progress, 'jenis_pekerjaan' => 'NEW INSTALL',
                'branch_completed_at' => '2026-10-23 01:00:00', 'status_final' => 'CLOSE', 'tgl_ps' => '2026-10-23',
                'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
            $this->assertSame($id === 'NORMALIZED', $order->isPsEligible(), $id);
            if ($id === 'NORMALIZED') {
                $expected[] = $id;
            }
        }
        $this->assertEqualsCanonicalizing($expected, WorkOrder::psEligible()->pluck('no_tq_sc_id')->all());
        $this->assertEqualsCanonicalizing($expected, WorkOrder::psOnDate('2026-10-23')->pluck('no_tq_sc_id')->all());
        $this->assertSame(0, WorkOrder::psOnDate('2026-10-22')->count());
        $this->assertSame(11, WorkOrder::waitingBima()->count());
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertViewHas('summary', fn ($data) => $data['ps_total'] === 15 && $data['ps_today'] === 15 && $data['waiting_bima'] === 11);
        $user = $this->user();
        $user->branches()->attach($this->branch);
        $this->actingAs($user)->get(route('branch.dashboard'))->assertOk()->assertViewHas('ps_total', 15)->assertViewHas('complete', 15)->assertViewHas('waiting_bima', 11);
        $this->get(route('work-orders.index', ['queue' => 'ps_today']))->assertOk()->assertViewHas('total', 15)->assertDontSee('GENERIC-COMPLETE');
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
    }

    public function test_late_bima_confirmation_updates_ps_without_touching_branch_progress_and_uses_the_original_ps_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 03:00:00', 'UTC'));
        $this->pull([$this->row('LATE-BIMA', [14 => 'Approval E2E Testing Manual', 15 => '2026-10-17'])]);
        $user = $this->user();
        $user->branches()->attach($this->branch);
        $order = WorkOrder::firstOrFail();
        $this->actingAs($user)->patch(route('work-orders.progress', $order), ['revision' => 0, 'progress_category' => 'CLOSE', 'notes' => 'Sudah diuji, menunggu konfirmasi BIMA.'])->assertRedirect();
        $before = $order->fresh()->only(['progress_category', 'branch_completed_at', 'operational_status', 'revision', 'last_progress_at', 'verified_at']);
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0)->assertViewHas('period_complete', 0);
        $this->pull([$this->row('LATE-BIMA', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE', 15 => '2026-10-17'])]);
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0)->assertViewHas('period_complete', 1);
        $area = $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk();
        $this->assertSame(1, $area->viewData('summary')['ps_total']);
        $this->assertSame(0, $area->viewData('summary')['ps_today']);
        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('wo_status_events', 1);
        $this->pull([$this->row('LATE-BIMA', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE', 15 => '2026-10-17'])]);
        $this->assertSame(1, WorkOrder::psEligible()->count());
        $this->assertDatabaseCount('work_orders', 1);
    }
}
