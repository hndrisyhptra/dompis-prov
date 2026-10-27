<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessSheetSync;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class AccessAndSyncTest extends OloTestCase
{
    public function test_login_session_logout_and_inactive_access(): void
    {
        $this->get('/sync')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Username atau email')->assertSee('autocomplete="current-password"', false);
        $this->post('/login', ['email' => $this->area->email, 'password' => 'password'])->assertRedirect(route('sync.index'));
        $this->assertAuthenticatedAs($this->area);
        $this->get(route('logout.confirm'))->assertOk()->assertSee('Apakah Anda yakin ingin Logout?')->assertSee('Ya, Logout')->assertSee('Tidak');
        $this->assertAuthenticatedAs($this->area);
        $this->get('/work-orders')->assertOk();
        $this->assertAuthenticatedAs($this->area);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->area->forceFill(['active' => false])->save();
        $this->post('/login', ['email' => $this->area->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_branch_scope_for_list_detail_and_every_sync_action(): void
    {
        $this->pull([$this->row()]);
        $wo = WorkOrder::first();
        $branch = $this->user();
        $this->actingAs($branch)->get('/work-orders')->assertOk()->assertDontSee('000123');
        $this->get('/work-orders/'.$wo->getKey())->assertForbidden();
        $this->get('/sync')->assertForbidden();
        $job = $this->preview([]);
        $this->get('/sync/jobs/'.$job->getKey())->assertForbidden();
        $this->getJson('/sync/jobs/'.$job->getKey())->assertForbidden();
        $this->post('/sync/bima/preview', ['idempotency_key' => Str::uuid()])->assertForbidden();
        $this->post('/sync/jobs/'.$job->getKey().'/commit', ['confirmed' => 1])->assertForbidden();
        $this->get('/sync/jobs/'.$job->getKey().'/exceptions')->assertForbidden();
        $this->post('/sync/jobs/'.$job->getKey().'/retry')->assertForbidden();
        $this->get('/branch-mappings')->assertForbidden();
        $branch->branches()->attach($this->branch);
        $this->get('/work-orders')->assertOk()->assertSee('000123');
        $this->get('/work-orders/'.$wo->getKey())->assertOk()->assertSee('SID-0099');
    }

    public function test_area_pages_render_and_loading_state_reads_no_google_on_page_load(): void
    {
        $this->actingAs($this->area)->get('/sync')->assertOk()->assertSee('Buat preview BIMA_RAW');
        $this->get('/branch-mappings')->assertOk()->assertSee('BRANCH UJI');
        $this->get('/work-orders')->assertOk();
        $this->assertSame(0, $this->sheets->reads);
        $job = $this->preview([$this->row()]);
        $this->get('/sync/jobs/'.$job->getKey())->assertOk()->assertSee('Konfirmasi & tarik');
        $this->getJson('/sync/jobs/'.$job->getKey())->assertOk()->assertJsonMissingPath('snapshot');
    }

    public function test_preview_request_is_idempotent_and_commit_expires_and_is_actor_bound(): void
    {
        Queue::fake();
        $key = (string) Str::uuid();
        $this->actingAs($this->area)->post('/sync/bima/preview', ['idempotency_key' => $key])->assertRedirect();
        $this->post('/sync/bima/preview', ['idempotency_key' => $key])->assertRedirect();
        $this->assertSame(1, SyncJob::where('idempotency_key', $key)->count());
        Queue::assertPushed(ProcessSheetSync::class, 1);
        $job = $this->preview([$this->row()]);
        $this->actingAs($this->user(Role::AreaAdmin))->post('/sync/jobs/'.$job->getKey().'/commit', ['confirmed' => 1])->assertForbidden();
        $job->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->area)->post('/sync/jobs/'.$job->getKey().'/commit', ['confirmed' => 1])->assertConflict();
        $job->update(['expires_at' => now()->addMinute()]);
        $this->post('/sync/jobs/'.$job->getKey().'/commit', ['confirmed' => 1])->assertRedirect();
        $this->post('/sync/jobs/'.$job->getKey().'/commit', ['confirmed' => 1])->assertRedirect();
        $this->assertSame('commit', $job->fresh()->stage);
    }

    public function test_area_coordinator_is_read_only_for_sync_and_inactive_session_is_forbidden(): void
    {
        $this->actingAs($this->user(Role::AreaCoordinator))->get('/sync')->assertForbidden();
        $this->get('/work-orders')->assertOk();
        $this->actingAs($this->user(Role::BranchAdmin, false))->get('/work-orders')->assertForbidden();
    }
}
