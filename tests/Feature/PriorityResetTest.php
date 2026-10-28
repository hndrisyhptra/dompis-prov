<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use App\Services\PriorityOrderService;
use App\Services\PriorityResetService;
use DOMDocument;
use DOMXPath;

class PriorityResetTest extends OloTestCase
{
    private function order(string $number, ?Branch $branch = null): WorkOrder
    {
        return WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $number, 'id_branches' => ($branch ?? $this->branch)->getKey(),
            'jenis_pekerjaan' => 'NEW INSTALL', 'progress_category' => 'SURVEY', 'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $number)]);
    }

    private function branchActor(?Branch $branch = null): User
    {
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($branch ?? $this->branch);

        return $actor;
    }

    public function test_area_resets_all_shared_pages_despite_filters_and_replay_preserves_new_selections(): void
    {
        $branch = $this->branchActor();
        $selector = $this->user(Role::SuperAdmin);
        for ($i = 0; $i < 60; $i++) {
            $order = $this->order('RESET-'.str_pad($i, 3, '0', STR_PAD_LEFT));
            app(PriorityOrderService::class)->set($selector, $order, true);
            if ($i === 0) {
                app(PriorityOrderService::class)->set($branch, $order, true);
            }
        }
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $page = $this->actingAs($this->area)->get(route('priorities.index', ['q' => 'RESET-000']))->assertOk()->assertSee('Reset Bookmark')
            ->assertSee('data-confirm-title="Reset Bookmark Area"', false)->assertSee('Ya, Lepas Semua')->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
        $preview = $page->viewData('resetPreview');
        $this->assertSame(60, $preview['count']);
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new DOMXPath($dom);
        $toolbar = '//div[@role="group" and @aria-label="Aksi daftar prioritas"]';
        $this->assertSame(1, $xpath->query($toolbar.'/*[a[@data-excel-download]]/following-sibling::form[@action="'.route('priorities.reset').'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('priorities.reset').'"]')->length);
        $this->assertSame(1, $xpath->query($toolbar.'//button[@aria-describedby="priority-reset-help"]')->length);
        $this->delete(route('priorities.reset'), ['reset_token' => $preview['token'], 'confirmed' => 1])->assertRedirect(route('priorities.index'));
        $this->assertDatabaseCount('work_order_priorities', 1);
        $this->assertSame('branch:'.$this->branch->getKey(), WorkOrderPriority::sole()->scope_key);
        $this->assertSame(60, AuditLog::where('action', 'priority.removed')->count());
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('wo_status_events', 0);
        app(PriorityOrderService::class)->set($this->area, $order, true);
        $this->delete(route('priorities.reset'), ['reset_token' => $preview['token'], 'confirmed' => 1])->assertRedirect();
        $this->assertSame(1, WorkOrderPriority::where('scope_key', 'area')->count());
        $this->assertSame(1, AuditLog::where('action', 'priority.reset')->count());
    }

    public function test_branch_reset_removes_only_local_active_assignments_and_retains_area_foreign_and_moved_bookmarks(): void
    {
        $other = Branch::create(['code' => 'RESET-OTHER', 'name' => 'Other Reset Branch']);
        $assigned = Branch::create(['code' => 'RESET-ASSIGNED', 'name' => 'Assigned Reset Branch']);
        $actor = $this->branchActor();
        $actor->branches()->attach($assigned);
        $foreign = $this->branchActor($other);
        $shared = $this->order('SHARED');
        $local = $this->order('LOCAL', $assigned);
        $moved = $this->order('MOVED');
        foreach ([$shared, $local] as $order) {
            app(PriorityOrderService::class)->set($this->area, $order, true);
            app(PriorityOrderService::class)->set($actor, $order, true);
        }
        app(PriorityOrderService::class)->set($actor, $moved, true);
        $moved->update(['id_branches' => $other->getKey()]);
        app(PriorityOrderService::class)->set($foreign, $this->order('FOREIGN', $other), true);
        $page = $this->actingAs($actor)->get(route('priorities.index', ['source' => 'area']))->assertOk();
        $this->assertSame(2, $page->viewData('resetPreview')['count']);
        $this->delete(route('priorities.reset'), ['confirmed' => 1, 'reset_token' => $page->viewData('resetPreview')['token']])->assertRedirect();
        $this->assertSame(2, WorkOrderPriority::where('scope_key', 'area')->count());
        $this->assertDatabaseHas('work_order_priorities', ['id_work_orders' => $moved->getKey(), 'scope_key' => 'branch:'.$this->branch->getKey()]);
        $this->assertSame(4, WorkOrderPriority::count());
        $empty = $this->get(route('priorities.index'))->assertViewHas('resetPreview', fn ($data) => $data['count'] === 0 && $data['token'] === null);
        $dom = new DOMDocument;
        @$dom->loadHTML($empty->getContent());
        $this->assertSame(1, (new DOMXPath($dom))->query('//form[@action="'.route('priorities.reset').'"]//button[@disabled]')->length);
    }

    public function test_stale_expired_actor_bound_and_unconfirmed_tokens_never_delete_bookmarks(): void
    {
        $service = app(PriorityResetService::class);
        $order = $this->order('TOKEN-RESET');
        app(PriorityOrderService::class)->set($this->area, $order, true);
        $token = $service->preview($this->area)['token'];
        $this->actingAs($this->area)->deleteJson(route('priorities.reset'), ['reset_token' => $token])->assertUnprocessable();
        foreach (['scope_key' => 'area', 'id_branches' => 999, 'id_users' => 999, 'id_work_orders' => $order->getKey()] as $key => $value) {
            $this->deleteJson(route('priorities.reset'), ['reset_token' => $token, 'confirmed' => 1, $key => $value])->assertUnprocessable();
        }
        $this->actingAs($this->user(Role::SuperAdmin))->deleteJson(route('priorities.reset'), ['reset_token' => $token, 'confirmed' => 1])->assertUnprocessable();
        app(PriorityOrderService::class)->set($this->area, $this->order('NEW-SINCE-PREVIEW'), true);
        $this->actingAs($this->area)->deleteJson(route('priorities.reset'), ['reset_token' => $token, 'confirmed' => 1])->assertUnprocessable();
        $token = $service->preview($this->area)['token'];
        $this->travel(16)->minutes();
        $this->deleteJson(route('priorities.reset'), ['reset_token' => $token, 'confirmed' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('work_order_priorities', 2);
        $this->assertSame(0, AuditLog::where('action', 'priority.removed')->count());
    }

    public function test_scope_revocation_role_checks_and_audit_failure_rollback_are_enforced(): void
    {
        $order = $this->order('RESET-ACCESS');
        $actor = $this->branchActor();
        app(PriorityOrderService::class)->set($actor, $order, true);
        $token = app(PriorityResetService::class)->preview($actor)['token'];
        $actor->branches()->detach();
        $this->actingAs($actor)->deleteJson(route('priorities.reset'), ['reset_token' => $token, 'confirmed' => 1])->assertUnprocessable();
        foreach ([Role::AreaCoordinator, Role::MaterialAdmin, Role::Bot] as $role) {
            $this->actingAs($this->user($role))->deleteJson(route('priorities.reset'), ['reset_token' => $token, 'confirmed' => 1])->assertForbidden();
        }
        $actor->branches()->attach($this->branch);
        $actor->forceFill(['active' => false])->save();
        $this->actingAs($actor)->deleteJson(route('priorities.reset'), ['reset_token' => $token, 'confirmed' => 1])->assertForbidden();
        $actor->forceFill(['active' => true])->save();
        $token = app(PriorityResetService::class)->preview($actor->fresh())['token'];
        AuditLog::creating(function ($audit) {
            if ($audit->action === 'priority.reset') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            try {
                app(PriorityResetService::class)->reset($actor->fresh(), $token);
                $this->fail('Audit failure must roll back reset.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Synthetic audit failure', $exception->getMessage());
            }
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('work_order_priorities', 1);
        $this->assertSame(0, AuditLog::where('action', 'priority.removed')->count());
    }
}
