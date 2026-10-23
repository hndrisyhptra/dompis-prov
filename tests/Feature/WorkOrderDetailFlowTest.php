<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\WorkOrder;
use App\Services\BimaSheetPullService;
use App\Services\WorkOrderDeviceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WorkOrderDetailFlowTest extends OloTestCase
{
    private function branchActor()
    {
        $user = $this->user(Role::BranchHd);
        $user->branches()->attach($this->branch);

        return $user;
    }

    public function test_branch_labels_collapsed_devices_source_accordion_and_no_separate_issue_card(): void
    {
        $this->pull([$this->row()]);
        $order = WorkOrder::sole();
        $this->actingAs($this->branchActor())->get(route('work-orders.index'))->assertOk()->assertSee('<h1>Work Order</h1>', false)->assertDontSee('Order Cabang');
        $response = $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Isi / edit perangkat')->assertDontSee('Kendala &amp; tindak lanjut', false)->assertDontSee('Catat kendala baru');
        $this->assertMatchesRegularExpression('/<details id="order-devices"[^>]*>/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<details id="order-devices"[^>]*\bopen\b/', $response->getContent());
        $response->assertSee('<details id="order-source"', false);
        $this->assertDoesNotMatchRegularExpression('/<details id="order-source"[^>]*\bopen\b/', $response->getContent());
        $data = array_fill_keys(array_keys(WorkOrderDeviceService::FIELDS), null);
        $data['sn'] = str_repeat('X', 256);
        $this->from(route('work-orders.show', $order))->patch(route('work-orders.devices', $order), $data + ['revision' => 0])->assertSessionHasErrors('sn');
        $invalid = $this->withCookie(config('session.cookie'), session()->getId())->get(route('work-orders.show', $order))->assertOk();
        $this->assertTrue(view()->shared('errors')->has('sn'));
        $this->assertMatchesRegularExpression('/<details id="order-devices"[^>]*\bopen\b/', $invalid->getContent());

    }

    public function test_progress_alone_captures_issue_followups_and_resolves_with_explicit_confirmation_before_close(): void
    {
        $this->pull([$this->row()]);
        $order = WorkOrder::sole();
        $user = $this->branchActor();
        $url = route('work-orders.progress', $order);
        $this->actingAs($user)->patch($url, ['revision' => 0, 'progress_category' => 'KENDALA', 'notes' => ''])->assertSessionHasErrors('notes');
        $this->patch($url, ['revision' => 0, 'progress_category' => 'KENDALA', 'notes' => 'Akses gedung belum tersedia.'])->assertRedirect();
        $issue = $order->issues()->sole();
        $this->assertSame('Akses gedung belum tersedia.', $issue->description);
        $this->patch($url, ['revision' => 1, 'progress_category' => 'KENDALA', 'notes' => 'Menunggu PIC pukul 14.00.'])->assertRedirect();
        $this->assertSame('Menunggu PIC pukul 14.00.', $issue->notes()->sole()->note);
        $this->patch($url, ['revision' => 2, 'progress_category' => 'KENDALA', 'notes' => 'Menunggu PIC pukul 14.00.'])->assertRedirect();
        $this->assertDatabaseCount('wo_issue_notes', 1);
        $this->assertSame(2, $order->fresh()->revision);
        $this->patch($url, ['revision' => 2, 'progress_category' => 'CLOSE', 'notes' => 'Selesai.'])->assertSessionHasErrors('progress_category');
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Kendala aktif (1)')->assertSee('Akses gedung belum tersedia.')->assertSee('Menunggu PIC pukul 14.00.')->assertDontSee('name="progress_category"', false);
        $this->get(route('work-orders.action.edit', [$order, 'close']))->assertOk()->assertSee('Seluruh kendala aktif sudah selesai');
        $this->patch($url, ['revision' => 1, 'progress_category' => 'CLOSE', 'notes' => 'PIC memberikan akses, pengujian selesai.', 'resolve_issues' => 1])->assertConflict();
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->patch($url, ['revision' => 2, 'progress_category' => 'CLOSE', 'notes' => 'PIC memberikan akses, pengujian selesai.', 'resolve_issues' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->assertSame('PIC memberikan akses, pengujian selesai.', $issue->fresh()->resolution);
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertSame(3, $order->fresh()->revision);
        $this->assertDatabaseHas('audit_logs', ['action' => 'issue.resolved', 'id_entity' => $issue->getKey()]);
        $this->actingAs($this->user(Role::BranchHd))->patch($url, ['revision' => 3, 'progress_category' => 'CLOSE', 'notes' => 'Tidak berhak', 'resolve_issues' => 1])->assertForbidden();
    }

    public function test_bima_source_changes_are_encrypted_and_shown_with_full_wib_time_without_duplicate_replays(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 01:02:03', 'UTC'));
        $this->pull([$this->row('LOG', [14 => 'Survey'])]);
        $this->travel(10)->seconds();
        $job = $this->pull([$this->row('LOG', [14 => 'Survey', 9 => 'Alamat berubah sintetis', 18 => 'ODP-SUMBER-BARU'])]);
        $order = WorkOrder::sole();
        $this->assertDatabaseCount('bima_task_logs', 1);
        $log = $order->taskLogs()->sole();
        $this->assertSame('Alamat Uji', $log->field_changes['alamat']['before']);
        $this->assertSame('Alamat berubah sintetis', $log->field_changes['alamat']['after']);
        $this->assertSame('ODP-SUMBER-BARU', $log->field_changes['source:ODP']['after']);
        $this->assertStringNotContainsString('Alamat berubah sintetis', DB::table('bima_task_logs')->value('field_changes'));
        $this->assertArrayNotHasKey('field_changes', $log->toArray());
        app(BimaSheetPullService::class)->pull($job);
        $this->pull([$this->row('LOG', [14 => 'Survey', 9 => 'Alamat berubah sintetis', 18 => 'ODP-SUMBER-BARU'])]);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->actingAs($this->branchActor())->get(route('work-orders.show', $order))->assertOk()->assertSee('Log Task BIMA')->assertSee('20 Oct 2026 08:02:13')->assertSee('Alamat Uji')->assertSee('Alamat berubah sintetis')->assertSee('ODP-SUMBER-BARU');
        $this->actingAs($this->user())->get(route('work-orders.show', $order))->assertForbidden();
    }

    public function test_all_task_changes_can_be_viewed_across_log_pages(): void
    {
        $this->pull([$this->row('PAGES', [14 => 'Task 0'])]);
        for ($index = 1; $index <= 22; $index++) {
            $this->pull([$this->row('PAGES', [14 => 'Task '.$index])]);
        }
        $order = WorkOrder::sole();
        $this->assertDatabaseCount('bima_task_logs', 22);
        $first = $this->actingAs($this->branchActor())->get(route('work-orders.show', $order))->assertOk()->assertSee('22 pembaruan')->assertSee('Task 22-OPEN');
        $this->assertSame(20, $first->viewData('taskLogs')->count());
        $last = $this->get(route('work-orders.show', [$order, 'task_page' => 2]))->assertOk()->assertSee('Task 0-OPEN')->assertSee('Task 1-OPEN');
        $this->assertSame(2, $last->viewData('taskLogs')->count());
    }
}
