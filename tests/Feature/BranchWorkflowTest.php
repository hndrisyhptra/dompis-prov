<?php

namespace Tests\Feature;

use App\Enums\ProgressCategory;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\WoIssue;
use App\Models\WorkOrder;
use Carbon\Carbon;

class BranchWorkflowTest extends OloTestCase
{
    private function branchUser()
    {
        $user = $this->user();
        $user->branches()->attach($this->branch);

        return $user;
    }

    private function order(): WorkOrder
    {
        $this->pull([$this->row()]);

        return WorkOrder::firstOrFail();
    }

    public function test_pull_distributes_orders_to_every_assigned_admin_and_hides_other_branches(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 03:00:00', 'UTC'));
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Branch Other']);
        BranchMapping::create(['bima_branch' => 'OTHER', 'id_branches' => $other->getKey()]);
        $this->pull([$this->row('OWN-ORDER', [1 => '2026-10-18']), $this->row('OTHER-ORDER', [1 => '2026-10-18', 5 => 'OTHER'])]);
        $own = WorkOrder::where('external_order_id', 'OWN-ORDER')->firstOrFail();
        $foreign = WorkOrder::where('external_order_id', 'OTHER-ORDER')->firstOrFail();
        foreach ([$this->branchUser(), $this->branchUser()] as $admin) {
            $this->actingAs($admin)->get(route('branch.dashboard'))->assertOk()->assertViewHas('incoming', 1)->assertSee('OWN-ORDER')->assertDontSee('OTHER-ORDER')->assertDontSee('Sync Center');
            $this->get(route('work-orders.index'))->assertOk()->assertSee('OWN-ORDER')->assertDontSee('OTHER-ORDER');
            $this->get(route('work-orders.show', $own))->assertOk();
            $this->get(route('work-orders.show', $foreign))->assertForbidden();
            $this->get(route('branch.dashboard', ['branch' => $other->getKey()]))->assertForbidden();
            $this->get(route('work-orders.index', ['branch' => $other->getKey()]))->assertForbidden();
        }
        $this->assertDatabaseCount('work_orders', 2);
        $this->actingAs($this->user(Role::SuperAdmin))->get(route('users.index'))->assertOk()->assertSee($this->branch->name)->assertDontSee('if($user->branches');
    }

    public function test_branch_home_login_categories_and_material_status_are_clear_without_configuration_claims(): void
    {
        $user = $this->branchUser();
        $order = $this->order();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('branch.dashboard'));
        $this->get('/')->assertRedirect(route('branch.dashboard'));
        $response = $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Update progress')->assertDontSee('name="operational_status"', false);
        foreach (ProgressCategory::cases() as $category) {
            $response->assertSee('value="'.$category->value.'"', false);
        }
        $this->get(route('branch.material'))->assertOk()->assertSee('Menunggu integrasi')->assertSee('belum diaktifkan');
        $this->actingAs($this->user())->get(route('branch.dashboard'))->assertOk()->assertViewHas('incoming', 0)->assertViewHas('outstanding', 0)->assertSee('belum memiliki penugasan branch');
    }

    public function test_close_records_ps_once_notes_preserve_date_and_only_area_can_reopen(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 17:30:00', 'UTC'));
        $order = $this->order();
        $user = $this->branchUser();
        $this->actingAs($user)->patch(route('work-orders.progress', $order), ['revision' => 0, 'progress_category' => 'CLOSE', 'notes' => 'Pekerjaan selesai dan diuji.'])->assertRedirect();
        $order->refresh();
        $this->assertSame('CLOSE', $order->progress_category);
        $this->assertSame('FIELD_DONE', $order->operational_status);
        $this->assertSame('UNVERIFIED', $order->verification_status);
        $this->assertNull($order->verified_at);
        $completed = $order->branch_completed_at->toDateTimeString();
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0)->assertViewHas('outstanding', 0);
        $this->pull([$this->row(changes: [13 => 'COMPLETE', 15 => '2026-10-18'])]);
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 1);
        $this->travelTo(Carbon::parse('2026-10-18 18:00:00', 'UTC'));
        $this->patch(route('work-orders.progress', $order), ['revision' => 1, 'progress_category' => 'CLOSE', 'notes' => 'Dokumentasi sudah dikirim.'])->assertRedirect();
        $this->assertSame($completed, $order->fresh()->branch_completed_at->toDateTimeString());
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0)->assertViewHas('period_complete', 1);
        $this->patch(route('work-orders.progress', $order), ['revision' => 2, 'progress_category' => 'INSTALASI', 'notes' => 'Buka ulang'])->assertForbidden();
        $this->patch(route('work-orders.progress', $order), ['revision' => 2, 'operational_status' => 'IN_PROGRESS', 'notes' => 'Bypass lama'])->assertForbidden();
        $this->actingAs($this->area)->patch(route('work-orders.progress', $order), ['revision' => 2, 'progress_category' => 'INSTALASI', 'notes' => 'Koreksi setelah review Area.'])->assertRedirect();
        $this->assertNull($order->fresh()->branch_completed_at);
        $this->actingAs($user)->get(route('branch.dashboard'))->assertViewHas('outstanding', 1)->assertViewHas('period_complete', 0);
    }

    public function test_kendala_creates_detail_once_supports_append_notes_and_revision_protection(): void
    {
        $order = $this->order();
        $this->actingAs($this->branchUser())->patch(route('work-orders.progress', $order), ['revision' => 0, 'progress_category' => 'KENDALA', 'notes' => 'Akses gedung belum diizinkan.'])->assertRedirect();
        $issue = $order->issues()->sole();
        $this->assertSame('Akses gedung belum diizinkan.', $issue->description);
        $this->assertSame('BLOCKED', $order->fresh()->operational_status);
        $this->patch(route('work-orders.progress', $order), ['revision' => 1, 'progress_category' => 'KENDALA', 'notes' => 'Akses gedung belum diizinkan.'])->assertRedirect();
        $this->assertDatabaseCount('wo_issues', 1);
        $this->assertDatabaseCount('wo_status_events', 1);
        $this->post(route('issues.notes', $issue), ['revision' => 1, 'note' => 'PIC sudah dihubungi, menunggu jadwal.'])->assertRedirect();
        $this->assertSame(2, $order->fresh()->revision);
        $this->assertSame('Akses gedung belum diizinkan.', $issue->fresh()->description);
        $this->assertSame('PIC sudah dihubungi, menunggu jadwal.', $issue->notes()->sole()->note);
        $this->post(route('issues.notes', $issue), ['revision' => 1, 'note' => 'Stale note'])->assertConflict()->assertSee('Muat ulang order');
        $this->actingAs($this->user())->post(route('issues.notes', $issue), ['revision' => 2, 'note' => 'Out of scope'])->assertForbidden();
        $this->assertDatabaseCount('wo_issue_notes', 1);
        $actor = $this->branchUser();
        $this->actingAs($actor)->patch(route('issues.resolve', $issue), ['revision' => 2, 'resolution' => 'Akses sudah diberikan.'])->assertRedirect();
        $this->patch(route('work-orders.progress', $order), ['revision' => 3, 'progress_category' => 'KENDALA', 'notes' => 'Akses gedung belum diizinkan.'])->assertRedirect();
        $this->assertDatabaseCount('wo_issues', 2);
        $this->assertSame(1, $order->issues()->where('status', 'OPEN')->count());
    }

    public function test_close_requires_issue_resolution_and_repull_keeps_category_completion_and_history(): void
    {
        $order = $this->order();
        $user = $this->branchUser();
        $this->actingAs($user)->post(route('work-orders.issues', $order), ['revision' => 0, 'category' => 'MATERIAL', 'description' => 'Material menunggu pengiriman', 'pic' => 'PIC Uji'])->assertRedirect();
        $this->patch(route('work-orders.progress', $order), ['revision' => 1, 'progress_category' => 'CLOSE', 'notes' => 'Selesai'])->assertSessionHasErrors('progress_category');
        $this->assertNull($order->fresh()->branch_completed_at);
        $issue = WoIssue::firstOrFail();
        $this->patch(route('issues.resolve', $issue), ['revision' => 1, 'resolution' => 'Material diterima.'])->assertRedirect();
        $this->patch(route('work-orders.progress', $order), ['revision' => 2, 'progress_category' => 'CLOSE', 'notes' => 'Instalasi dan pengujian selesai.'])->assertRedirect();
        $before = $order->fresh()->only(['progress_category', 'branch_completed_at', 'operational_status', 'revision']);
        $this->pull([$this->row(changes: [13 => 'CLOSE'])]);
        $this->pull([$this->row(changes: [13 => 'CLOSE'])]);
        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('wo_status_events', 1);
    }

    public function test_close_on_desk_is_never_ps_even_if_the_retired_configuration_is_present(): void
    {
        $order = $this->order();
        $this->actingAs($this->branchUser())->patch(route('work-orders.progress', $order), ['revision' => 0, 'progress_category' => 'CLOSE ON DESK', 'notes' => 'Penutupan administrasi.'])->assertRedirect();
        $this->assertNull($order->fresh()->branch_completed_at);
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0)->assertViewHas('outstanding', 0);
        config(['olo.branch.close_on_desk_counts_as_complete' => true]);
        $this->actingAs($this->area)->patch(route('work-orders.progress', $order), ['revision' => 1, 'progress_category' => 'CLOSE ON DESK', 'notes' => 'Diakui sebagai complete setelah kebijakan disetujui.'])->assertRedirect();
        $this->assertNull($order->fresh()->branch_completed_at);
        $this->pull([$this->row(changes: [13 => 'COMPLETE', 15 => now()->timezone('Asia/Jakarta')->toDateString()])]);
        $this->get(route('branch.dashboard'))->assertViewHas('complete', 0);
    }
}
