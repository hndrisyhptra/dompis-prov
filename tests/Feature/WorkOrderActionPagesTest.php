<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;
use App\Support\ProgressSummary;
use App\Support\WorkOrderActions;
use DOMDocument;
use DOMXPath;

class WorkOrderActionPagesTest extends OloTestCase
{
    private function order(string $id = 'ACTION-ORDER'): WorkOrder
    {
        // Keep action/authorization fixtures outside the automatic progress mapping.
        $this->pull([$this->row($id, [14 => 'Review Order TSQ', 13 => 'STARTWORK'])]);

        return WorkOrder::where('no_tq_sc_id', $id)->firstOrFail();
    }

    private function branchActor(Role $role = Role::BranchAdmin)
    {
        $actor = $this->user($role);
        $actor->branches()->attach($this->branch);

        return $actor;
    }

    public function test_all_area_and_branch_operators_get_separate_forms_with_exact_options_and_detail_without_progress_form(): void
    {
        $order = $this->order();
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd, Role::BranchAdmin, Role::BranchHd, Role::TeamLeader] as $role) {
            $this->actingAs($this->branchActor($role));
            $detail = $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Detail Order')->assertSee('Referensi BIMA')->assertSee('Detail sumber order')->assertDontSee('name="progress_category"', false);
            $list = $this->get(route('work-orders.index'))->assertOk()->assertSee('title="Detail Order"', false)->assertSee(route('work-orders.show', $order));
            foreach (WorkOrderActions::MODES as $mode => $definition) {
                $url = route('work-orders.action.edit', [$order, $mode]);
                $list->assertSee($url);
                $page = $this->get($url)->assertOk()->assertSee($definition['title'])->assertDontSee('data-progress-mode=', false);
                $dom = new DOMDocument;
                @$dom->loadHTML($page->getContent());
                $xpath = new DOMXPath($dom);
                $values = [];
                foreach ($xpath->query('//select[@name="progress_category"]/option') as $option) {
                    if ($option->getAttribute('value') !== '') {
                        $values[] = $option->getAttribute('value');
                    }
                }
                $this->assertSame(array_keys($definition['categories']), $values, $role->value.':'.$mode);
                $this->assertSame(1, $xpath->query('//form[input[@name="_method" and @value="PATCH"] and select[@name="progress_category"]]')->length);
                $this->get(route('work-orders.show', [$order, 'action' => $mode]))->assertRedirect($url);
            }
        }
    }

    public function test_action_endpoints_reject_categories_from_other_pages_and_operational_bypass(): void
    {
        $order = $this->order();
        $this->actingAs($this->branchActor());
        foreach (['progress' => 'CLOSE', 'issue' => 'SURVEY', 'close' => 'HOLD'] as $mode => $invalid) {
            $this->patch(route('work-orders.action.update', [$order, $mode]), ['revision' => 0, 'progress_category' => $invalid, 'notes' => 'Tidak boleh'])->assertSessionHasErrors('progress_category');
        }
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 0, 'progress_category' => 'SURVEY', 'operational_status' => 'COMPLETED', 'notes' => 'Bypass'])->assertSessionHasErrors('operational_status');
        $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 0, 'progress_category' => 'HOLD', 'resolve_issues' => 1, 'notes' => 'Tidak boleh'])->assertSessionHasErrors('resolve_issues');
        $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 0, 'progress_category' => 'HOLD', 'notes' => '  '])->assertSessionHasErrors('notes');
        $this->assertSame(0, $order->fresh()->revision);
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertDatabaseCount('wo_issues', 0);
    }

    public function test_each_new_issue_category_records_detail_and_analytics_and_preserves_bima_source(): void
    {
        $this->actingAs($this->branchActor(Role::BranchHd));
        foreach (['CANCEL', 'HOLD', 'BUTUH QE', 'PERIZINAN'] as $category) {
            $order = $this->order('ISSUE-'.$category);
            $before = $order->only(['status_order', 'task_bima', 'status_bima', 'source_snapshot_hash', 'tgl_ps']);
            $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 0, 'progress_category' => $category, 'notes' => 'Detail '.$category])->assertSessionHasNoErrors()->assertRedirect(route('work-orders.show', $order));
            $this->assertSame($category, $order->fresh()->progress_category);
            $this->assertSame($category === 'CANCEL' ? 'CANCELLED' : 'BLOCKED', $order->fresh()->operational_status);
            $issue = $order->issues()->sole();
            $this->assertSame('Detail '.$category, $issue->description);
            $this->assertSame($before, $order->fresh()->only(array_keys($before)));
            $this->assertSame($category === 'CANCEL' ? 'cancel' : 'kendala', ProgressSummary::bucket($order->fresh()));
            $this->assertFalse($order->fresh()->isPsEligible());
            if ($category !== 'CANCEL') {
                $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 1, 'progress_category' => $category, 'notes' => 'Tindak lanjut '.$category])->assertSessionHasNoErrors();
                $this->assertSame('Tindak lanjut '.$category, $issue->notes()->sole()->note);
            }
        }
        $this->get(route('branch.dashboard'))->assertOk()->assertViewHas('outstanding', 3);
        $this->get(route('work-orders.index', ['queue' => 'issues']))->assertViewHas('total', 3);
        $this->actingAs($this->area)->get(route('work-orders.index', ['bucket' => 'kendala']))->assertViewHas('total', 3);
        $this->assertDatabaseCount('bima_task_logs', 0);
    }

    public function test_issue_resolution_close_and_retries_are_transactional_and_close_is_not_ps(): void
    {
        $order = $this->order();
        $this->actingAs($this->branchActor());
        $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 0, 'progress_category' => 'PERIZINAN', 'notes' => 'Izin belum keluar'])->assertSessionHasNoErrors();
        $issue = $order->issues()->sole();
        $this->assertSame('PERMIT', $issue->category);
        $this->patch(route('work-orders.action.update', [$order, 'close']), ['revision' => 1, 'progress_category' => 'CLOSE', 'notes' => 'Selesai'])->assertSessionHasErrors('progress_category');
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 1, 'progress_category' => 'INSTALASI', 'notes' => 'Izin selesai, lanjut instalasi', 'resolve_issues' => 1])->assertSessionHasNoErrors();
        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->patch(route('work-orders.action.update', [$order, 'close']), ['revision' => 1, 'progress_category' => 'CLOSE', 'notes' => 'Stale close'])->assertConflict();
        $this->patch(route('work-orders.action.update', [$order, 'close']), ['revision' => 2, 'progress_category' => 'CLOSE', 'notes' => 'Instalasi dan uji selesai'])->assertSessionHasNoErrors();
        $completed = $order->fresh()->branch_completed_at->toDateTimeString();
        $this->assertFalse($order->fresh()->isPsEligible());
        $this->get(route('work-orders.action.edit', [$order, 'progress']))->assertForbidden();
        $this->get(route('work-orders.action.edit', [$order, 'close']))->assertOk()->assertSee('Tambahkan catatan');
        $this->patch(route('work-orders.action.update', [$order, 'close']), ['revision' => 3, 'progress_category' => 'CLOSE', 'notes' => 'Dokumentasi diserahkan'])->assertSessionHasNoErrors();
        $this->assertSame($completed, $order->fresh()->branch_completed_at->toDateTimeString());
        $this->assertNull($order->fresh()->tgl_ps);
        $this->get(route('branch.dashboard'))->assertViewHas('ps_total', 0);
        $this->actingAs($this->area)->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 4, 'progress_category' => 'SURVEY', 'notes' => 'Koreksi Area'])->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->branch_completed_at);
    }

    public function test_other_branches_viewers_and_inactive_operators_cannot_open_or_update_action_pages(): void
    {
        $order = $this->order();
        $other = Branch::create(['code' => 'ACTION-OTHER', 'name' => 'Other Branch']);
        foreach ([$this->user(Role::BranchHd), $this->branchActor(Role::MaterialAdmin), $this->user(Role::BranchAdmin, false)] as $actor) {
            $actor->branches()->attach($other);
            $this->actingAs($actor);
            foreach (array_keys(WorkOrderActions::MODES) as $mode) {
                $this->get(route('work-orders.action.edit', [$order, $mode]))->assertForbidden();
                $category = array_key_first(WorkOrderActions::MODES[$mode]['categories']);
                $this->patch(route('work-orders.action.update', [$order, $mode]), ['revision' => 0, 'progress_category' => $category, 'notes' => 'Tidak berhak'])->assertForbidden();
            }
        }
        $this->actingAs($this->branchActor())->get('/work-orders/'.$order->getKey().'/actions/unknown')->assertNotFound();
        $this->assertSame(0, $order->fresh()->revision);
    }
}
