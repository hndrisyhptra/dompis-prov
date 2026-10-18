<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Carbon\Carbon;

class PsEligibilityTest extends OloTestCase
{
    public function test_ps_requires_both_exact_statuses_and_never_uses_final_status_or_branch_timestamp_as_a_substitute(): void
    {
        $cases = [
            'ELIGIBLE-CLOSE' => ['CLOSE', 'CLOSE', true],
            'ELIGIBLE-COMPLETE' => ['CLOSE', 'COMPLETE', true],
            'ELIGIBLE-NORMALIZED' => [' CLOSE ', ' complete ', true],
            'REJECT-CONTROL-WHITESPACE' => ['CLOSE', "COMPLETE\t", false],
            'WAIT-OPEN' => ['CLOSE', 'OPEN', false],
            'WAIT-NULL' => ['CLOSE', null, false],
            'REJECT-SUBSTRING' => ['CLOSE', 'INCOMPLETE', false],
            'REJECT-OTHER-CODE' => ['CLOSE', 'COMPLETED', false],
            'REJECT-DESK' => ['CLOSE ON DESK', 'CLOSE', false],
            'REJECT-CANCEL' => ['CANCEL', 'COMPLETE', false],
            'REJECT-SURVEY' => ['SURVEY', 'COMPLETE', false],
            'REJECT-MISSING-PROGRESS' => [null, 'COMPLETE', false],
        ];
        $this->pull(array_map(fn ($id) => $this->row($id, [15 => '2026-10-18', 26 => 'CLOSE']), array_keys($cases)));
        $expected = [];
        foreach ($cases as $id => [$progress, $bima, $eligible]) {
            $order = WorkOrder::where('external_order_id', $id)->firstOrFail();
            $order->update(['progress_category' => $progress, 'bima_status' => $bima, 'branch_completed_at' => '2026-10-18 01:00:00']);
            $this->assertSame($eligible, $order->isPsEligible(), $id);
            if ($eligible) {
                $expected[] = $id;
            }
        }
        $this->assertEqualsCanonicalizing($expected, WorkOrder::psEligible()->pluck('external_order_id')->all());
        $this->assertEqualsCanonicalizing($expected, WorkOrder::psOnDate('2026-10-18')->pluck('external_order_id')->all());
        $this->assertSame(0, WorkOrder::psOnDate('2026-10-19')->count());
    }

    public function test_late_bima_confirmation_updates_ps_without_touching_branch_progress_and_uses_the_original_ps_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 03:00:00', 'UTC'));
        $this->pull([$this->row('LATE-BIMA', [15 => '2026-10-17'])]);
        $user = $this->user();
        $user->branches()->attach($this->branch);
        $order = WorkOrder::firstOrFail();
        $this->actingAs($user)->patch(route('work-orders.progress', $order), ['revision' => 0, 'progress_category' => 'CLOSE', 'notes' => 'Sudah diuji, menunggu konfirmasi BIMA.'])->assertRedirect();
        $before = $order->fresh()->only(['progress_category', 'branch_completed_at', 'operational_status', 'revision', 'last_progress_at', 'verified_at']);
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0)->assertViewHas('period_complete', 0);
        $this->pull([$this->row('LATE-BIMA', [13 => 'COMPLETE', 15 => '2026-10-17'])]);
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0)->assertViewHas('period_complete', 1);
        $area = $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk();
        $this->assertSame(1, $area->viewData('summary')['ps_total']);
        $this->assertSame(0, $area->viewData('summary')['ps_today']);
        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('wo_status_events', 1);
        $this->pull([$this->row('LATE-BIMA', [13 => 'COMPLETE', 15 => '2026-10-17'])]);
        $this->assertSame(1, WorkOrder::psEligible()->count());
        $this->assertDatabaseCount('work_orders', 1);
    }
}
