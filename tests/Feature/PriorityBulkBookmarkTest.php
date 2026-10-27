<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPriority;
use App\Services\PriorityBulkBookmarkService;
use App\Services\PriorityOrderService;
use Illuminate\Support\Facades\DB;

class PriorityBulkBookmarkTest extends OloTestCase
{
    private function order(string $number, ?Branch $branch = null, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $number, 'id_branches' => ($branch ?? $this->branch)->getKey(),
            'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK', 'progress_category' => 'SURVEY',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $number)]);
    }

    private function branchUser(?Branch $branch = null): User
    {
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($branch ?? $this->branch);

        return $actor;
    }

    private function bulkPreview(string $input): array
    {
        return $this->post(route('priorities.bulk.preview'), ['numbers' => $input])->assertOk()->viewData('preview');
    }

    public function test_mixed_paste_is_previewed_without_mutation_and_committed_once_with_audit(): void
    {
        $ready = $this->order('000123');
        $selected = $this->order('ALREADY');
        app(PriorityOrderService::class)->set($this->area, $selected, true);
        // A local Branch bookmark must not make Area's preview say already selected.
        app(PriorityOrderService::class)->set($this->branchUser(), $ready, true);
        $ps = $this->order('PS', extra: ['progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE']);
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $this->actingAs($this->area)->get(route('priorities.bulk.create'))->assertOk()->assertSee('Maksimal 50');
        $preview = $this->bulkPreview("000123\nALREADY,PS;MISSING\t000123");
        $this->assertSame(['ready' => 1, 'selected' => 1, 'ps' => 1, 'missing' => 1, 'duplicate' => 1, 'ambiguous' => 0], $preview['counts']);
        $this->assertDatabaseCount('work_order_priorities', 2);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'priority.bulk_selected']);
        $this->post(route('priorities.bulk.store'), ['token' => $preview['token']])->assertRedirect(route('priorities.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('work_order_priorities', 3);
        $this->assertSame(1, WorkOrderPriority::where('id_work_orders', $ready->getKey())->where('scope_key', 'area')->count());
        $this->assertSame(3, AuditLog::where('action', 'priority.selected')->count());
        $this->assertSame(1, AuditLog::where('action', 'priority.bulk_selected')->count());
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->assertDatabaseMissing('work_order_priorities', ['id_work_orders' => $ps->getKey()]);
        $bookmark = $ready->priorities()->where('scope_key', 'area')->sole();
        app(PriorityOrderService::class)->set($this->area, $ready, false, $bookmark->selection_token);
        // A retry cannot recreate a bookmark explicitly removed after this batch succeeded.
        $this->post(route('priorities.bulk.store'), ['token' => $preview['token']])->assertRedirect(route('priorities.index'));
        $this->assertDatabaseCount('work_order_priorities', 2);
        $this->assertSame(1, AuditLog::where('action', 'priority.bulk_selected')->count());
    }

    public function test_branch_preview_does_not_disclose_other_branches_and_creates_only_local_bookmarks(): void
    {
        $actor = $this->branchUser();
        $own = $this->order('OWN');
        $otherBranch = Branch::create(['code' => 'BULK-FOREIGN', 'name' => 'SECRET-BRANCH']);
        $foreign = $this->order('FOREIGN', $otherBranch);
        app(PriorityOrderService::class)->set($this->area, $own, true);
        $this->actingAs($actor);
        $page = $this->post(route('priorities.bulk.preview'), ['numbers' => "OWN\nFOREIGN\nMISSING"])->assertOk()->assertDontSee('SECRET-BRANCH');
        $rows = $page->viewData('preview')['rows'];
        $this->assertSame(['ready', 'missing', 'missing'], array_column($rows, 'state'));
        $this->assertNull($rows[1]['branch']);
        $this->post(route('priorities.bulk.store'), ['token' => $page->viewData('preview')['token']])->assertRedirect(route('priorities.index'));
        $this->assertDatabaseCount('work_order_priorities', 2);
        $this->assertDatabaseHas('work_order_priorities', ['id_work_orders' => $own->getKey(), 'scope_key' => 'branch:'.$this->branch->getKey(), 'id_branches' => $this->branch->getKey()]);
        $this->assertDatabaseMissing('work_order_priorities', ['id_work_orders' => $foreign->getKey()]);
        $this->actingAs($this->area)->get(route('priorities.index'))->assertViewHas('orders', fn ($rows) => $rows->first()->priorities->pluck('scope_key')->all() === ['area']);
    }

    public function test_input_limit_is_enforced_before_deduplication_and_scope_fields_are_rejected(): void
    {
        $this->actingAs($this->area);
        foreach (['', implode("\n", array_fill(0, 51, 'REPEATED')), str_repeat('a', 192), str_repeat('x', 10001)] as $input) {
            $this->post(route('priorities.bulk.preview'), ['numbers' => $input])->assertRedirect(route('priorities.bulk.create'))->assertSessionHasErrors('numbers');
        }
        foreach (['scope_key' => 'area', 'id_branches' => $this->branch->getKey(), 'ids' => [1]] as $key => $value) {
            $this->postJson(route('priorities.bulk.preview'), ['numbers' => 'ANY', $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('work_order_priorities', 0);
    }

    public function test_fifty_order_batch_uses_bounded_queries_and_rechecks_existing_selections(): void
    {
        $orders = collect();
        for ($i = 0; $i < 50; $i++) {
            $orders->push($this->order('BULK-'.str_pad($i, 3, '0', STR_PAD_LEFT)));
        }
        $this->actingAs($this->area);
        DB::enableQueryLog();
        try {
            $preview = app(PriorityBulkBookmarkService::class)->preview($this->area, $orders->pluck('no_tq_sc_id')->implode("\n"));
            $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
            $this->assertSame(50, $preview['counts']['ready']);
            app(PriorityOrderService::class)->set($this->area, $orders->first(), true);
            DB::flushQueryLog();
            $result = app(PriorityBulkBookmarkService::class)->commit($this->area, $preview['token']);
            $this->assertLessThanOrEqual(14, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(['added' => 49, 'skipped' => 1], $result);
        $this->assertDatabaseCount('work_order_priorities', 50);
        $this->assertSame(50, AuditLog::where('action', 'priority.selected')->count());
    }

    public function test_exact_numbers_preserve_case_leading_zero_and_ambiguous_sources_are_not_guessed(): void
    {
        $this->order('000001');
        $this->order('CaseSensitive');
        $this->order('AMBIGUOUS');
        $this->order('AMBIGUOUS', extra: ['source_system' => 'OTHER']);
        $this->actingAs($this->area);
        $preview = $this->bulkPreview("000001\n1\ncasesensitive\nCaseSensitive\nAMBIGUOUS\n<script>alert(1)</script>");
        $this->assertSame(['ready', 'missing', 'missing', 'ready', 'ambiguous', 'missing'], array_column($preview['rows'], 'state'));
        $this->post(route('priorities.bulk.preview'), ['numbers' => '<script>alert(1)</script>'])->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_assignment_ps_or_access_changes_abort_entire_batch_and_preserve_input_for_new_preview(): void
    {
        $actor = $this->branchUser();
        $other = Branch::create(['code' => 'BULK-MOVED', 'name' => 'Moved Branch']);
        $actor->branches()->attach($other);
        $this->actingAs($actor);
        foreach (['assignment', 'ps', 'access'] as $change) {
            $first = $this->order('SAFE-'.$change);
            $second = $this->order('CHANGED-'.$change);
            $input = $first->no_tq_sc_id."\n".$second->no_tq_sc_id;
            $preview = $this->bulkPreview($input);
            match ($change) {
                'assignment' => $second->update(['id_branches' => $other->getKey()]),
                'ps' => $second->update(['progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE']),
                'access' => $actor->branches()->detach(),
            };
            $this->post(route('priorities.bulk.store'), ['token' => $preview['token'], 'original_numbers' => $input])
                ->assertRedirect(route('priorities.bulk.create'))->assertSessionHasErrors('token')->assertSessionHasInput('numbers', $input);
            $this->assertDatabaseCount('work_order_priorities', 0);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'priority.bulk_selected']);
        }
    }

    public function test_tampered_expired_foreign_or_role_changed_preview_cannot_write(): void
    {
        $this->order('TOKEN');
        $this->actingAs($this->area);
        $token = $this->bulkPreview('TOKEN')['token'];
        $this->post(route('priorities.bulk.store'), ['token' => $token.'broken'])->assertSessionHasErrors('token');
        $otherActor = $this->user(Role::AreaAdmin);
        $this->actingAs($otherActor)->post(route('priorities.bulk.store'), ['token' => $token])->assertSessionHasErrors('token');
        $this->area->forceFill(['role' => Role::AreaAdmin])->save();
        $this->actingAs($this->area)->post(route('priorities.bulk.store'), ['token' => $token])->assertSessionHasErrors('token');
        $this->area->forceFill(['role' => Role::AreaHd])->save();
        $this->travel(16)->minutes();
        $this->post(route('priorities.bulk.store'), ['token' => $token])->assertSessionHasErrors('token');
        $this->assertDatabaseCount('work_order_priorities', 0);
    }

    public function test_audit_failure_rolls_back_all_bookmarks_and_per_order_audits(): void
    {
        $this->order('ROLLBACK-A');
        $this->order('ROLLBACK-B');
        $this->actingAs($this->area);
        $token = $this->bulkPreview("ROLLBACK-A\nROLLBACK-B")['token'];
        AuditLog::creating(function ($audit) {
            if ($audit->action === 'priority.bulk_selected') {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        try {
            app(PriorityBulkBookmarkService::class)->commit($this->area, $token);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('work_order_priorities', 0);
        $this->assertSame(0, AuditLog::whereIn('action', ['priority.selected', 'priority.bulk_selected'])->count());
    }

    public function test_bulk_endpoints_follow_existing_active_role_permissions(): void
    {
        $this->get(route('priorities.bulk.create'))->assertRedirect(route('login'));
        $this->post(route('priorities.bulk.store'))->assertRedirect(route('login'));
        foreach (Role::cases() as $role) {
            $actor = $this->user($role);
            $this->actingAs($actor);
            $allowed = in_array($role, [Role::SuperAdmin, Role::AreaAdmin, Role::AreaHd, Role::BranchAdmin, Role::BranchHd, Role::TeamLeader], true);
            $page = $this->get(route('priorities.bulk.create'));
            $allowed ? $page->assertOk() : $page->assertForbidden();
            $preview = $this->postJson(route('priorities.bulk.preview'), ['numbers' => 'ANY']);
            $allowed ? $preview->assertOk() : $preview->assertForbidden();
            $commit = $this->postJson(route('priorities.bulk.store'), ['token' => 'invalid']);
            $allowed ? $commit->assertUnprocessable()->assertJsonValidationErrors('token') : $commit->assertForbidden();
        }
        $this->actingAs($this->user(Role::AreaHd, false))->get(route('priorities.bulk.create'))->assertForbidden();
        $this->post(route('priorities.bulk.preview'), ['numbers' => 'ANY'])->assertForbidden();
        $this->post(route('priorities.bulk.store'), ['token' => 'invalid'])->assertForbidden();
    }
}
