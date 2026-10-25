<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Region;
use App\Models\WorkOrder;
use App\Services\RevenueOverviewService;

class RevenueOverviewTest extends OloTestCase
{
    private function order(string $key, ?Branch $branch, string $progress, ?string $month, ?array $values = null, int $package = 2): WorkOrder
    {
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $key, 'id_branches' => $branch?->getKey(), 'source_bima_fields' => [],
            'source_snapshot_hash' => str_repeat('a', 64), 'progress_category' => $progress, 'tgl_order' => $month ? $month.'-10' : null,
            'task_bima' => $key === 'REV-A-PS' ? 'Approval E2E Testing Manual' : 'Activate Access Manual',
            'status_bima' => $key === 'REV-A-PS' ? 'COMPLETE' : 'STARTWORK']);
        if ($values) {
            $order->boq()->create(['package_id' => $package, 'material_total' => $values[0], 'labor_total' => $values[1], 'grand_total' => $values[2],
                'file_hash' => str_repeat('a', 64), 'content_hash' => str_repeat('b', 64), 'file_path' => 'boq/test.xlsx', 'original_name' => 'test.xlsx', 'item_count' => 2]);
        }

        return $order;
    }

    private function scenario(): array
    {
        $region = Region::create(['code' => 'REV-A', 'name' => 'Region Revenue A', 'is_active' => true]);
        $otherRegion = Region::create(['code' => 'REV-B', 'name' => 'Region Revenue B', 'is_active' => true]);
        $this->branch->update(['region_id' => $region->getKey()]);
        $other = Branch::create(['code' => 'REV-PRIVATE', 'name' => 'Branch Private Revenue', 'active' => true, 'region_id' => $otherRegion->getKey()]);
        $ps = $this->order('REV-A-PS', $this->branch, 'CLOSE', '2026-08', ['100.00', '40.00', '140.00']);
        $waiting = $this->order('REV-A-WAITING', $this->branch, 'CLOSE', '2026-08', ['200.00', '60.00', '260.00']);
        $cancel = $this->order('REV-A-CANCEL', $this->branch, 'CANCEL', '2026-09', ['300.00', '70.00', '370.00']);
        $private = $this->order('REV-B-PRIVATE-WO', $other, 'JT', '2026-08', ['400.00', '80.00', '480.00'], 3);
        $missing = $this->order('REV-A-NO-BOQ', $this->branch, 'JT', '2026-08');
        $unassigned = $this->order('REV-UNASSIGNED', null, 'JT', null);

        return compact('region', 'otherRegion', 'other', 'ps', 'waiting', 'cancel', 'private', 'missing', 'unassigned');
    }

    public function test_all_area_roles_see_exact_material_labor_totals_stage_breakdown_coverage_and_links(): void
    {
        $data = $this->scenario();
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $response = $this->actingAs($this->user($role))->get(route('revenue.index'))->assertOk();
            $response->assertViewHas('summary', fn ($s) => $s['grand_total'] === '1250.00' && $s['material_total'] === '1000.00' && $s['labor_total'] === '250.00'
                && $s['ps'] === '140.00' && $s['ongoing'] === '740.00' && $s['cancel'] === '370.00' && $s['coverage'] === '66.7' && $s['order_count'] === 6 && $s['boq_count'] === 4);
            $response->assertSee('Revenue Overview')->assertSee('1.250,00')->assertSee('REV-B-PRIVATE-WO')
                ->assertSee(route('boqs.show', $data['ps']), false)->assertSee('data-master-tooltip="Preview BOQ"', false);
            $response->assertViewHas('monthSummary', fn ($s) => $s['2026-08']['grand_total'] === '880.00' && $s['2026-09']['grand_total'] === '370.00');
        }
    }

    public function test_all_branch_roles_only_see_assigned_branch_in_totals_rows_options_and_filters(): void
    {
        $data = $this->scenario();
        foreach ([Role::BranchAdmin, Role::BranchHd, Role::TeamLeader, Role::MaterialAdmin] as $role) {
            $actor = $this->user($role);
            $actor->branches()->attach($this->branch);
            $this->actingAs($actor)->get(route('revenue.index'))->assertOk()->assertDontSee('REV-B-PRIVATE-WO')->assertDontSee('Branch Private Revenue')
                ->assertDontSee('Region Revenue B')->assertDontSee('REV-UNASSIGNED')->assertViewHas('summary', fn ($s) => $s['grand_total'] === '770.00' && $s['order_count'] === 4 && $s['coverage'] === '75.0');
            $this->get(route('revenue.index', ['branch' => $data['other']->getKey()]))->assertForbidden();
            $this->get(route('revenue.index', ['region' => $data['otherRegion']->getKey()]))->assertForbidden();
            $this->get(route('boqs.show', $data['private']))->assertForbidden();
        }
    }

    public function test_filters_and_stage_drilldowns_match_summary_counts_and_preserve_query_on_pagination(): void
    {
        $data = $this->scenario();
        $this->actingAs($this->area);
        foreach ([['state' => 'ps', 'total' => '140.00', 'count' => 1], ['state' => 'ongoing', 'total' => '740.00', 'count' => 2], ['state' => 'cancel', 'total' => '370.00', 'count' => 1],
            ['state' => 'without_boq', 'total' => '0.00', 'count' => 2], ['order_month' => '2026-08', 'total' => '880.00', 'count' => 4],
            ['branch' => $this->branch->getKey(), 'package_id' => 3, 'total' => '0.00', 'count' => 0],
            ['region' => $data['otherRegion']->getKey(), 'total' => '480.00', 'count' => 1], ['q' => 'REV-A-WAITING', 'total' => '260.00', 'count' => 1]] as $case) {
            $filters = array_diff_key($case, array_flip(['total', 'count']));
            $this->get(route('revenue.index', $filters))->assertOk()->assertViewHas('summary', fn ($s) => $s['grand_total'] === $case['total'])
                ->assertViewHas('orders', fn ($orders) => $orders->total() === $case['count']);
        }
        foreach (range(1, 21) as $i) {
            $this->order('REV-PAGE-'.$i, $this->branch, 'JT', '2026-08');
        }
        $response = $this->get(route('revenue.index', ['branch' => $this->branch->getKey(), 'order_month' => '2026-08']));
        $response->assertOk()->assertViewHas('orders', fn ($p) => $p->total() === 24 && $p->count() === 20 && str_contains($p->url(2), 'order_month=2026-08'));
    }

    public function test_inactive_bot_guest_empty_and_unassigned_accounts_and_validation_states(): void
    {
        $this->get(route('revenue.index'))->assertRedirect(route('login'));
        foreach ([$this->user(Role::Bot), $this->user(Role::BranchHd, false)] as $actor) {
            $this->actingAs($actor)->get(route('revenue.index'))->assertForbidden();
        }
        $this->actingAs($this->user(Role::BranchAdmin))->get(route('revenue.index'))->assertOk()->assertSee('Belum ada nilai BOQ')->assertViewHas('summary', fn ($s) => $s['grand_total'] === '0.00' && $s['order_count'] === 0);
        $this->get(route('revenue.index', ['state' => 'paid', 'order_month' => '2026-99']))->assertSessionHasErrors(['state', 'order_month']);
    }

    public function test_only_active_boq_revision_counts_and_assignment_changes_restrict_old_branch_immediately(): void
    {
        $data = $this->scenario();
        $boq = $data['ps']->boq;
        $boq->histories()->create(['event_type' => 'updated', 'after_data' => ['grand_total' => '999999.00'], 'note' => 'Previous revision']);
        $boq->items()->create(['designator_id' => 531, 'designator_code' => 'M-RJ45-CAT6', 'item_name' => 'Historical item', 'unit' => 'pcs', 'type' => 'MATERIAL', 'qty' => 1, 'unit_price' => 999999, 'total_price' => 999999])->delete();
        $this->actingAs($this->area)->get(route('revenue.index'))->assertOk()->assertViewHas('summary', fn ($s) => $s['grand_total'] === '1250.00');
        $boq->update(['material_total' => '100.00', 'labor_total' => '50.00', 'grand_total' => '150.00', 'revision' => 2]);
        $this->get(route('revenue.index'))->assertOk()->assertViewHas('summary', fn ($s) => $s['grand_total'] === '1260.00');
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $data['ps']->update(['id_branches' => $data['other']->getKey()]);
        $this->actingAs($actor)->get(route('revenue.index'))->assertOk()->assertDontSee('REV-A-PS')->assertViewHas('summary', fn ($s) => $s['grand_total'] === '630.00');
        $this->branch->update(['active' => false]);
        $this->get(route('revenue.index'))->assertOk()->assertViewHas('summary', fn ($s) => $s['order_count'] === 0);
        $data['private']->boq->delete();
        $this->actingAs($this->area)->get(route('revenue.index'))->assertOk()->assertViewHas('summary', fn ($s) => $s['grand_total'] === '780.00');
    }

    public function test_large_values_use_decimal_arithmetic_without_float_loss_and_reconcile_stage_totals(): void
    {
        $this->order('LARGE-A', $this->branch, 'JT', null, ['9000000000000000.00', '0.00', '9000000000000000.00']);
        $this->order('LARGE-B', $this->branch, 'CANCEL', null, ['1.00', '0.01', '1.01']);
        $service = app(RevenueOverviewService::class);
        $summary = $service->summary($service->orders($this->area, []))['summary'];
        $this->assertSame('9000000000000001.01', $summary['grand_total']);
        $this->assertSame('0.01', $summary['labor_total']);
        $this->actingAs($this->area)->get(route('revenue.index'))->assertOk()->assertSee('9.000.000.000.000.001,01');
    }
}
