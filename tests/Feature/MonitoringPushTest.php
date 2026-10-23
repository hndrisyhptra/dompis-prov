<?php

namespace Tests\Feature;

use App\Jobs\ProcessSheetSync;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Services\BimaSheetPullService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class MonitoringPushTest extends OloTestCase
{
    private function pushJob(string $state = 'queued'): SyncJob
    {
        return SyncJob::create(['idempotency_key' => Str::uuid(), 'id_users' => $this->area->getKey(), 'direction' => 'push', 'stage' => 'commit',
            'state' => $state, 'connection_hash' => BimaSheetPullService::connectionHash()]);
    }

    public function test_navigation_and_both_inbound_modes_have_no_mapping_menu_or_outbound_form(): void
    {
        foreach (['excel', 'sheets'] as $mode) {
            config(['olo.inbound' => $mode]);
            $this->actingAs($this->area)->get(route('sync.index'))->assertOk()
                ->assertDontSee('Branch &amp; Mapping', false)->assertDontSee(route('mappings.index'))
                ->assertDontSee('Kirim progress monitoring')->assertDontSee('Kirim snapshot monitoring')->assertDontSee('Outbound')
                ->assertSee($mode === 'excel' ? 'Upload &amp; periksa kolom' : 'Buat preview BIMA_RAW', false);
        }
        $this->get(route('area.dashboard'))->assertOk()->assertSee('Operasional Area ke Branch')->assertDontSee('cabang');
        $this->get(route('work-orders.index'))->assertOk()->assertDontSee('Cabang')->assertDontSee('cabang');
    }

    public function test_removed_push_endpoint_cannot_create_jobs_or_dispatch_for_any_role(): void
    {
        Queue::fake();
        $this->assertFalse(Route::has('sync.push'));
        foreach ([$this->area, $this->user()] as $user) {
            $this->actingAs($user)->post('/sync/monitoring/push', ['idempotency_key' => Str::uuid(), 'confirmed' => 1])->assertNotFound();
        }
        $this->assertDatabaseCount('sync_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_old_queued_push_is_stopped_once_without_reading_or_overwriting_google_data(): void
    {
        $original = [['Existing header'], ['Existing output']];
        $this->sheets->monitoring = $original;
        foreach (['queued', 'running', 'retrying'] as $state) {
            $job = $this->pushJob($state);
            $process = new ProcessSheetSync($job->getKey());
            $process->handle(app(BimaSheetPullService::class));
            $this->assertSame('failed', $job->fresh()->state);
            $this->assertSame('Publikasi MySQL ke Google Sheets sudah dinonaktifkan.', $job->fresh()->error);
            $process->handle(app(BimaSheetPullService::class));
        }
        $this->assertSame($original, $this->sheets->monitoring);
        $this->assertSame(0, $this->sheets->reads);
        $this->assertSame(3, AuditLog::where('action', 'monitoring.disabled')->count());
        $this->assertSame(0, AuditLog::where('action', 'monitoring.pushed')->count());
        $successful = $this->pushJob('success');
        (new ProcessSheetSync($successful->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame('success', $successful->fresh()->state);
    }

    public function test_old_push_history_is_read_only_and_cannot_be_committed_or_retried(): void
    {
        Queue::fake();
        $job = $this->pushJob('failed');
        $this->actingAs($this->area)->get(route('sync.show', $job))->assertOk()->assertSee('Arsip publikasi')
            ->assertDontSee('Coba ulang job')->assertDontSee('data-sync-poll')->assertDontSee('name="confirmed"', false);
        $this->post(route('sync.retry', $job))->assertStatus(410);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertStatus(410);
        $this->assertSame('failed', $job->fresh()->state);
        $job->update(['state' => 'preview_ready', 'stats' => ['read' => 1], 'expires_at' => now()->addHour()]);
        $this->get(route('sync.show', $job))->assertOk()->assertDontSee('name="confirmed"', false);
        Queue::assertNothingPushed();
    }
}
