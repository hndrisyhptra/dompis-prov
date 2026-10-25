<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use App\Services\MasterDataService;
use App\Services\PriorityOrderService;
use Illuminate\Auth\Access\AuthorizationException;

class PriorityOrdersTest extends OloTestCase
{
    private function order(string $id, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(), 'jenis_pekerjaan' => 'MODIFY',
            'tgl_order' => now()->subDays(10)->toDateString(), 'nama_olo' => 'Pelanggan Sintetis '.$id, 'source_bima_fields' => [], 'source_snapshot_hash' => str_repeat('a', 64)])->refresh();
    }

    private function branchActor(Role $role = Role::BranchAdmin, ?Branch $branch = null)
    {
        $actor = $this->user($role);
        $actor->branches()->attach($branch ?? $this->branch);

        return $actor;
    }

    private function select($actor, WorkOrder $order): WorkOrderPriority
    {
        $this->actingAs($actor)->post(route('priorities.store', $order))->assertSessionHasNoErrors()->assertRedirect();

        return WorkOrderPriority::where('id_work_orders', $order->getKey())->where('scope_key', $actor->role->areaScope() ? 'area' : 'branch:'.$order->id_branches)->firstOrFail();
    }

    public function test_area_and_branch_privacy_holds_for_rows_counts_eager_load_detail_source_filter_and_deduplication(): void
    {
        $other = Branch::create(['code' => 'PRIORITY-OTHER', 'name' => 'Other Branch']);
        $areaFirst = $this->order('AREA-OWN');
        $local = $this->order('BRANCH-PRIVATE');
        $areaOther = $this->order('AREA-OTHER', ['id_branches' => $other->getKey()]);
        $localOther = $this->order('OTHER-PRIVATE', ['id_branches' => $other->getKey()]);
        $branch = $this->branchActor();
        $branchOther = $this->branchActor(Role::BranchHd, $other);
        $this->select($this->area, $areaFirst);
        $this->select($this->area, $areaOther);
        $this->select($branch, $local);
        $this->select($branch, $areaFirst);
        $this->select($branchOther, $localOther);
        foreach ([Role::SuperAdmin, Role::AreaAdmin, Role::AreaHd] as $role) {
            $this->actingAs($this->user($role));
            $page = $this->get(route('priorities.index'))->assertOk()->assertDontSee('BRANCH-PRIVATE')->assertDontSee('OTHER-PRIVATE');
            $this->assertSame(2, $page->viewData('summary')['all']);
            $this->assertEqualsCanonicalizing([$areaFirst->getKey(), $areaOther->getKey()], $page->viewData('orders')->modelKeys());
            foreach ($page->viewData('orders') as $order) {
                $this->assertSame(['area'], $order->priorities->pluck('scope_key')->all());
            }
            $listing = $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all']))->assertOk();
            $this->assertCount(0, $listing->viewData('orders')->firstWhere('id_work_orders', $local->getKey())->priorities);
            $detail = $this->get(route('work-orders.show', $local))->assertOk()->assertDontSee('Prioritas Branch');
            $this->assertCount(0, $detail->viewData('order')->priorities);
            $this->getJson(route('priorities.index', ['source' => 'branch']))->assertUnprocessable();
        }
        $this->actingAs($branch);
        $page = $this->get(route('priorities.index'))->assertOk()->assertSee('AREA-OWN')->assertSee('BRANCH-PRIVATE')->assertDontSee('OTHER-PRIVATE')->assertDontSee('AREA-OTHER');
        $this->assertSame(2, $page->viewData('orders')->total());
        $this->assertSame(2, $page->viewData('summary')['all']);
        $this->assertCount(2, $page->viewData('orders')->firstWhere('id_work_orders', $areaFirst->getKey())->priorities);
        $this->get(route('priorities.index', ['source' => 'area']))->assertViewHas('orders', fn ($orders) => $orders->total() === 1)->assertSee('Lepas Prioritas Branch');
        $this->get(route('priorities.index', ['source' => 'branch']))->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
        $this->get(route('priorities.index', ['branch' => $other->getKey()]))->assertForbidden();
    }

    public function test_select_and_remove_are_idempotent_scoped_and_never_change_work_order_or_progress(): void
    {
        $order = $this->order('REPLAY', ['progress_category' => 'SURVEY', 'revision' => 8, 'odp' => 'ODP-LOCAL']);
        $before = $order->getRawOriginal();
        $branch = $this->branchActor();
        $area = $this->select($this->area, $order);
        $local = $this->select($branch, $order);
        $this->select($this->area, $order);
        $this->select($branch, $order);
        $this->assertDatabaseCount('work_order_priorities', 2);
        $this->assertSame(2, AuditLog::where('action', 'priority.selected')->count());
        $this->actingAs($this->area)->delete(route('priorities.destroy', $order), ['selection_token' => $area->selection_token])->assertRedirect();
        $this->delete(route('priorities.destroy', $order), ['selection_token' => $area->selection_token])->assertRedirect();
        $this->assertDatabaseCount('work_order_priorities', 1);
        $this->assertNotNull($local->fresh());
        $this->assertSame(1, AuditLog::where('action', 'priority.removed')->count());
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->assertDatabaseCount('wo_status_events', 0);
    }

    public function test_branch_cannot_remove_area_selection_or_submit_arbitrary_scope_and_other_branch_cannot_mutate(): void
    {
        $order = $this->order('BOUNDARY');
        $branch = $this->branchActor();
        $area = $this->select($this->area, $order);
        $this->actingAs($branch)->delete(route('priorities.destroy', $order), ['selection_token' => $area->selection_token])->assertRedirect();
        $this->assertNotNull($area->fresh());
        $local = $this->select($branch, $order);
        $this->actingAs($branch)->delete(route('priorities.destroy', $order), ['selection_token' => $area->selection_token])->assertConflict();
        foreach (['scope_key' => 'area', 'id_branches' => 999, 'id_users' => $this->area->getKey(), 'id_work_orders' => $order->getKey()] as $field => $value) {
            $this->post(route('priorities.store', $order), [$field => $value])->assertSessionHasErrors($field);
        }
        $other = $this->branchActor(Role::BranchHd, Branch::create(['code' => 'P-X', 'name' => 'Other']));
        $this->actingAs($other)->post(route('priorities.store', $order))->assertForbidden();
        $this->delete(route('priorities.destroy', $order), ['selection_token' => $local->selection_token])->assertForbidden();
        $this->assertDatabaseCount('work_order_priorities', 2);
    }

    public function test_stale_remove_token_does_not_remove_selection_added_again_by_a_team_member(): void
    {
        $order = $this->order('TOKEN');
        $first = $this->select($this->area, $order);
        $this->delete(route('priorities.destroy', $order), ['selection_token' => $first->selection_token])->assertRedirect();
        $second = $this->select($this->user(Role::SuperAdmin), $order);
        $this->delete(route('priorities.destroy', $order), ['selection_token' => $first->selection_token])->assertConflict();
        $this->assertNotSame($first->selection_token, $second->selection_token);
        $this->assertNotNull($second->fresh());
        $this->delete(route('priorities.destroy', $order), [])->assertSessionHasErrors('selection_token');
    }

    public function test_role_menu_and_endpoint_permissions_are_explicit_and_rechecked_after_revocation(): void
    {
        $order = $this->order('ROLES');
        foreach (Role::cases() as $role) {
            $actor = $this->branchActor($role);
            $this->actingAs($actor);
            $allowed = in_array($role, [Role::SuperAdmin, Role::AreaAdmin, Role::AreaHd, Role::BranchAdmin, Role::BranchHd, Role::TeamLeader], true);
            $page = $this->get(route('priorities.index'));
            $allowed ? $page->assertOk() : $page->assertForbidden();
            $response = $this->post(route('priorities.store', $order));
            $allowed ? $response->assertRedirect() : $response->assertForbidden();
            if ($role !== Role::Bot) {
                $list = $this->get(route('work-orders.index'))->assertOk();
                $allowed ? $list->assertSee(' Order Prioritas</a>', false) : $list->assertDontSee(' Order Prioritas</a>', false);
            }
        }
        $inactive = $this->branchActor();
        $inactive->forceFill(['active' => false])->save();
        $this->actingAs($inactive)->get(route('priorities.index'))->assertForbidden();
        $this->post(route('priorities.store', $order))->assertForbidden();
        $revoked = $this->branchActor();
        $revoked->branches()->detach();
        $this->expectException(AuthorizationException::class);
        app(PriorityOrderService::class)->set($revoked, $order, true);
    }

    public function test_priority_is_shared_inside_branch_and_follows_area_order_assignment_without_leaking_local_selection(): void
    {
        $order = $this->order('TRANSFER');
        $branch = $this->branchActor();
        $local = $this->select($branch, $order);
        $this->select($this->area, $order);
        $colleague = $this->branchActor(Role::TeamLeader);
        $this->actingAs($colleague)->get(route('priorities.index'))->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
        $other = Branch::create(['code' => 'NEW-P', 'name' => 'New Branch']);
        $order->update(['id_branches' => $other->getKey()]);
        $this->get(route('priorities.index'))->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->actingAs($this->branchActor(Role::BranchHd, $other));
        $page = $this->get(route('priorities.index'))->assertOk();
        $this->assertSame(['area'], $page->viewData('orders')->first()->priorities->pluck('scope_key')->all());
        $this->assertNotNull($local->fresh());
        $this->actingAs($this->area)->get(route('priorities.index'))->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
    }

    public function test_master_branch_deletion_protects_hidden_bookmarks_without_disclosing_their_counts(): void
    {
        $old = Branch::create(['code' => 'PRIVATE-OLD', 'name' => 'Old Branch']);
        $order = $this->order('HIDDEN-REFERENCE', ['id_branches' => $old->getKey()]);
        $actor = $this->branchActor(Role::BranchHd, $old);
        $this->select($actor, $order);
        $order->update(['id_branches' => $this->branch->getKey()]);
        $actor->branches()->detach($old);
        $this->actingAs($this->user(Role::AreaAdmin));
        $page = $this->get(route('master-data.confirm-delete', ['branches', $old->getKey()]))->assertOk()->assertDontSee('Order Prioritas Branch')->assertDontSee('HIDDEN-REFERENCE');
        $this->assertSame([], $page->viewData('usage'));
        $this->delete(route('master-data.destroy', ['branches', $old->getKey()]), ['version' => app(MasterDataService::class)->version($old->fresh()), 'confirmed' => 1])->assertSessionHasErrors('delete');
        $this->assertNull($old->fresh()->deleted_at);
    }

    public function test_filters_counts_pagination_and_closed_orders_keep_live_monitoring(): void
    {
        $open = $this->order('FILTER-OPEN', ['last_progress_at' => now()->subHour()]);
        $issue = $this->order('FILTER-ISSUE', ['progress_category' => 'HOLD']);
        $issue->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'OTHER', 'description' => 'Tertunda', 'pic' => 'PIC']);
        $closed = $this->order('FILTER-CLOSE', ['progress_category' => 'CLOSE']);
        foreach ([$open, $issue, $closed] as $order) {
            $this->select($this->area, $order);
        }
        $page = $this->get(route('priorities.index'))->assertOk();
        $this->assertSame(['all' => 3, 'outstanding' => 2, 'issues' => 1, 'waiting_bima' => 1], $page->viewData('summary'));
        $this->get(route('priorities.index', ['state' => 'issues']))->assertViewHas('orders', fn ($orders) => $orders->total() === 1)->assertSee('FILTER-ISSUE');
        $this->get(route('priorities.index', ['state' => 'closed']))->assertViewHas('orders', fn ($orders) => $orders->total() === 1)->assertSee('FILTER-CLOSE');
        $this->get(route('priorities.index', ['q' => 'FILTER-OPEN', 'sort' => 'updated']))->assertViewHas('summary', fn ($summary) => $summary['all'] === 1)->assertSee('FILTER-OPEN');
        $open->update(['progress_category' => 'INSTALASI', 'last_progress_at' => now()]);
        $this->get(route('priorities.index', ['q' => 'FILTER-OPEN']))->assertSee('INSTALASI');
        $this->get(route('priorities.index', ['q' => 'ABSENT']))->assertSee('Belum ada order prioritas sesuai filter');
        $this->getJson(route('priorities.index', ['sort' => 'forged']))->assertUnprocessable();
        for ($i = 0; $i < 26; $i++) {
            $this->select($this->area, $this->order('PAGE-'.$i));
        }
        $this->get(route('priorities.index', ['q' => 'PAGE-', 'sort' => 'age']))->assertViewHas('orders', fn ($orders) => $orders->total() === 26 && $orders->count() === 25)->assertSee('page=2', false);
    }
}
