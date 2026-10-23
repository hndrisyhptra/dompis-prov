<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\MappingOwnergrup;
use App\Models\WorkOrder;

class OwnerGroupAnalyticsTest extends OloTestCase
{
    private function order(string $id, ?string $owner, ?int $branch, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'owner_grup' => $owner,
            'id_branches' => $branch, 'jenis_pekerjaan' => 'NEW INSTALL', 'layanan' => 'METRO',
            'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64)]);
    }

    public function test_matrix_reconciles_all_groups_unmapped_and_unassigned_with_clickable_lists(): void
    {
        $this->branch->update(['region_id' => 1]);
        foreach (['DISTRICT', 'PMD', 'ED REGIONAL', 'SDI REGIONAL', 'FF SO 3', 'FF SO 4'] as $i => $group) {
            MappingOwnergrup::create(['owner_grup' => 'RAW-'.$i, 'kelompok_ownergrup' => $group]);
            $this->order('MATCH-'.$i, 'RAW-'.$i, $this->branch->getKey());
        }
        MappingOwnergrup::create(['owner_grup' => 'INACTIVE', 'kelompok_ownergrup' => 'PMD', 'active' => false]);
        $this->order('UNKNOWN', 'NOT-KNOWN', $this->branch->getKey());
        $this->order('EMPTY', null, $this->branch->getKey());
        $this->order('INACTIVE', 'INACTIVE', $this->branch->getKey());
        $this->order('CASE-MISMATCH', 'raw-0', $this->branch->getKey());
        $this->order('NO-BRANCH', 'RAW-0', null);
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $this->actingAs($this->area);
        $dashboard = $this->get(route('area.dashboard'))->assertOk()->assertSee('Distribusi Owner Group')->assertSee('Grand Total');
        $matrix = $dashboard->viewData('ownerGroups');
        $this->assertSame(11, $matrix['total']);
        $this->assertSame(['DISTRICT' => 2, 'PMD' => 1, 'ED REGIONAL' => 1, 'SDI REGIONAL' => 1, 'FF SO 3' => 1, 'FF SO 4' => 1, 'DCS' => 0, 'unmapped' => 4], $matrix['totals']);
        $this->assertSame(11, array_sum(array_column($matrix['rows'], 'total')));
        foreach ($matrix['rows'] as $row) {
            $this->assertSame($row['total'], array_sum($row['counts']));
            foreach ($row['counts'] as $group => $count) {
                $filters = ['jenis_pekerjaan' => 'all', 'owner_group' => $group] + ($row['branch_id'] ? ['branch' => $row['branch_id']] : ['queue' => 'unmapped']);
                $response = $this->get(route('work-orders.index', $filters))->assertOk();
                $this->assertSame($count, $response->viewData('total'), json_encode(['filters' => $filters, 'ids' => $response->viewData('orders')->pluck('no_tq_sc_id')->all()]));
            }
        }
        foreach ($matrix['totals'] as $group => $count) {
            $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'owner_group' => $group]))->assertViewHas('total', $count);
        }
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
    }

    public function test_scope_filters_and_reference_changes_are_consistent_without_mutating_order_source(): void
    {
        $this->branch->update(['region_id' => 1]);
        $other = Branch::create(['code' => 'OTHER-OWNER', 'name' => 'Cabang Lain', 'region_id' => 2]);
        $rule = MappingOwnergrup::create(['owner_grup' => 'OWNER-UJI', 'kelompok_ownergrup' => 'DISTRICT']);
        $this->order('OWN-NEW', 'OWNER-UJI', $this->branch->getKey());
        $this->order('OWN-MODIFY', 'OWNER-UJI', $this->branch->getKey(), ['jenis_pekerjaan' => 'MODIFY']);
        $this->order('OWN-INET', 'OWNER-UJI', $this->branch->getKey(), ['layanan' => 'INTERNET']);
        $this->order('FOREIGN', 'OWNER-UJI', $other->getKey());
        $filters = ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY'];
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $this->actingAs($this->user($role))->get(route('area.dashboard', $filters))->assertOk()
                ->assertViewHas('ownerGroups', fn ($data) => $data['total'] === 1 && $data['totals']['DISTRICT'] === 1)
                ->assertSee(route('work-orders.index', $filters + ['owner_group' => 'DISTRICT']));
            $this->get(route('work-orders.index', $filters + ['owner_group' => 'DISTRICT']))->assertOk()->assertViewHas('total', 1)->assertSee('OWN-MODIFY')->assertDontSee('FOREIGN');
        }
        foreach ([Role::BranchAdmin, Role::BranchHd, Role::TeamLeader] as $role) {
            $user = $this->user($role);
            $user->branches()->attach($this->branch);
            $this->actingAs($user)->get(route('branch.dashboard'))->assertOk()->assertDontSee('Cabang Lain')
                ->assertViewHas('ownerGroups', fn ($data) => $data['total'] === 2 && $data['totals']['DISTRICT'] === 2);
            $this->get(route('work-orders.index', ['owner_group' => 'DISTRICT']))->assertOk()->assertViewHas('total', 2)->assertDontSee('FOREIGN')->assertSee('OWNER-UJI');
            $this->get(route('branch.dashboard', ['jenis_pekerjaan' => 'all']))->assertViewHas('ownerGroups', fn ($data) => $data['total'] === 3);
            $this->get(route('work-orders.index', ['owner_group' => 'DISTRICT', 'branch' => $other->getKey()]))->assertForbidden();
            $this->get(route('work-orders.index', ['owner_group' => 'DISTRICT', 'q' => 'OWN-NEW']))->assertViewHas('total', 1);
        }
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $rule->update(['kelompok_ownergrup' => 'PMD']);
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertViewHas('ownerGroups', fn ($data) => $data['totals']['DISTRICT'] === 0 && $data['totals']['PMD'] === 4);
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'owner_group' => 'DISTRICT']))->assertViewHas('total', 0);
        $rule->update(['active' => false]);
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'owner_group' => 'unmapped']))->assertViewHas('total', 4);
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
    }

    public function test_empty_reference_and_empty_scope_are_explicit_and_filters_remain_in_forms_and_queue_links(): void
    {
        $this->order('WAITING', 'WAIT-FOR-REFERENCE', $this->branch->getKey());
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertSee('Menunggu file Excel mapping')
            ->assertViewHas('ownerGroups', fn ($data) => ! $data['has_mapping'] && $data['total'] === 1 && $data['totals']['unmapped'] === 1);
        $this->get(route('work-orders.index', ['owner_group' => 'unmapped']))->assertOk()->assertSee('Owner Group: Belum dipetakan')
            ->assertSee('name="owner_group" value="unmapped"', false)->assertSee('WAIT-FOR-REFERENCE')->assertSee('owner_group=unmapped', false);
        $this->getJson(route('work-orders.index', ['owner_group' => 'UNAPPROVED']))->assertUnprocessable();
        $this->actingAs($this->user())->get(route('branch.dashboard'))->assertOk()->assertSee('Belum ada order dalam cakupan ini')
            ->assertViewHas('ownerGroups', fn ($data) => $data['total'] === 0 && $data['rows'] === []);
        $this->get(route('work-orders.index', ['owner_group' => 'unmapped']))->assertOk()->assertViewHas('total', 0);
    }
}
