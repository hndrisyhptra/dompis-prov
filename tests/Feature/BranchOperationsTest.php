<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\WoIssue;
use App\Models\WorkOrder;

class BranchOperationsTest extends OloTestCase
{
    private function order(): WorkOrder
    {
        $this->pull([$this->row()]);

        return WorkOrder::firstOrFail();
    }

    public function test_progress_uses_revision_timeline_and_pull_does_not_touch_it(): void
    {
        $wo = $this->order();
        $branchUser = $this->user();
        $branchUser->branches()->attach($this->branch);
        $this->actingAs($branchUser)->patch(route('work-orders.progress', $wo), ['revision' => 0, 'operational_status' => 'IN_PROGRESS', 'notes' => 'Mulai pekerjaan.'])->assertRedirect();
        $this->assertSame('IN_PROGRESS', $wo->fresh()->operational_status);
        $this->assertSame(1, $wo->fresh()->revision);
        $this->assertDatabaseCount('wo_status_events', 1);
        $this->patch(route('work-orders.progress', $wo), ['revision' => 0, 'operational_status' => 'SURVEY', 'notes' => 'Stale browser'])->assertConflict();
        $this->pull([$this->row(changes: [13 => 'CLOSE'])]);
        $this->assertSame('IN_PROGRESS', $wo->fresh()->operational_status);
        $this->assertDatabaseCount('wo_status_events', 1);
        $this->get(route('work-orders.show', $wo))->assertOk()->assertSee('Mulai pekerjaan.');
    }

    public function test_branch_cannot_complete_or_reopen_verified_work_and_other_branch_cannot_patch(): void
    {
        $wo = $this->order();
        $outsider = $this->user();
        $payload = ['revision' => 0, 'operational_status' => 'COMPLETED', 'notes' => 'Selesai'];
        $this->actingAs($outsider)->patch(route('work-orders.progress', $wo), $payload)->assertForbidden();
        $outsider->branches()->attach($this->branch);
        $this->patch(route('work-orders.progress', $wo), $payload)->assertForbidden();
        $this->actingAs($this->area)->patch(route('work-orders.progress', $wo), $payload)->assertUnprocessable();
        $this->patch(route('work-orders.progress', $wo), array_replace($payload, ['operational_status' => 'FIELD_DONE']))->assertRedirect();
        $this->patch(route('work-orders.progress', $wo), array_replace($payload, ['revision' => 1]))->assertRedirect();
        $this->assertNotNull($wo->fresh()->verified_at);
        $this->actingAs($outsider)->patch(route('work-orders.progress', $wo), ['revision' => 2, 'operational_status' => 'NEW', 'notes' => 'Buka'])->assertForbidden();
        $this->actingAs($this->area)->patch(route('work-orders.progress', $wo), ['revision' => 2, 'operational_status' => 'IN_PROGRESS', 'notes' => 'Koreksi Area'])->assertRedirect();
        $this->assertNull($wo->fresh()->verified_at);
        $this->assertDatabaseCount('wo_status_events', 3);
    }

    public function test_issue_creation_and_resolution_are_scoped_audited_and_revision_locked(): void
    {
        $wo = $this->order();
        $payload = ['revision' => 0, 'category' => 'ACCESS', 'description' => 'Lokasi terkunci', 'pic' => 'PIC Uji', 'eta' => '2026-10-20'];
        $this->actingAs($this->user())->post(route('work-orders.issues', $wo), $payload)->assertForbidden();
        $this->actingAs($this->area)->post(route('work-orders.issues', $wo), $payload)->assertRedirect();
        $this->post(route('work-orders.issues', $wo), $payload)->assertConflict();
        $issue = WoIssue::firstOrFail();
        $this->assertSame(1, $wo->fresh()->revision);
        $this->actingAs($this->user())->patch(route('issues.resolve', $issue), ['revision' => 1, 'resolution' => 'Selesai'])->assertForbidden();
        $this->actingAs($this->area)->patch(route('issues.resolve', $issue), ['revision' => 1, 'resolution' => 'Akses diberikan'])->assertRedirect();
        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->resolved_at);
        $this->assertSame(1, AuditLog::where('action', 'issue.resolved')->count());
    }

    public function test_active_issue_blocks_complete_and_material_admin_cannot_change_progress(): void
    {
        $wo = $this->order();
        $material = $this->user(Role::MaterialAdmin);
        $material->branches()->attach($this->branch);
        $this->actingAs($material)->patch(route('work-orders.progress', $wo), ['revision' => 0, 'operational_status' => 'IN_PROGRESS', 'notes' => 'Test'])->assertForbidden();
        $wo->update(['operational_status' => 'FIELD_DONE']);
        $wo->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'ACCESS', 'description' => 'Uji', 'pic' => 'PIC']);
        $this->actingAs($this->area)->patch(route('work-orders.progress', $wo), ['revision' => 0, 'operational_status' => 'COMPLETED', 'notes' => 'Test'])->assertUnprocessable();
    }
}
