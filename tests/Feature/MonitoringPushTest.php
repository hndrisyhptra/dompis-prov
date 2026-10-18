<?php

namespace Tests\Feature;

use App\Jobs\ProcessSheetSync;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\BimaSheetPullService;
use App\Services\MonitoringSheetPushService;
use App\Services\Sheets\SheetException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class MonitoringPushTest extends OloTestCase
{
    private function pushJob(): SyncJob
    {
        return SyncJob::create(['idempotency_key' => Str::uuid(), 'id_users' => $this->area->getKey(), 'direction' => 'push', 'stage' => 'commit', 'connection_hash' => BimaSheetPullService::connectionHash()]);
    }

    public function test_snapshot_is_sorted_key_safe_repeated_and_never_reads_bima(): void
    {
        $this->pull([$this->row('Z-01'), $this->row('A-01')]);
        $this->sheets->monitoring = [['Old header'], ['Z-01', 'wrong'], ['A-01', 'wrong'], ['STALE']];
        $this->sheets->reads = 0;
        $job = $this->pushJob();
        (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame('success', $job->fresh()->state);
        $this->assertSame(MonitoringSheetPushService::HEADERS, $this->sheets->monitoring[0]);
        $this->assertCount(3, $this->sheets->monitoring);
        $this->assertSame('A-01', $this->sheets->monitoring[1][0]);
        $this->assertSame('Z-01', $this->sheets->monitoring[2][0]);
        $this->assertSame(0, $this->sheets->reads);
        (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame(1, AuditLog::where('action', 'monitoring.pushed')->count());
    }

    public function test_outage_preserves_snapshot_for_retry_and_success_is_not_reported_early(): void
    {
        $this->pull([$this->row()]);
        $this->sheets->fail = true;
        $job = $this->pushJob();
        try {
            (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
            $this->fail('Expected simulated outage');
        } catch (SheetException) {
            $this->assertSame('retrying', $job->fresh()->state);
        }
        $snapshot = $job->fresh()->snapshot;
        WorkOrder::first()->update(['operational_status' => 'IN_PROGRESS']);
        $this->sheets->fail = false;
        (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame($snapshot, $this->sheets->monitoring);
        $this->assertSame('success', $job->fresh()->state);
        $this->assertSame('IN_PROGRESS', WorkOrder::first()->operational_status);
    }

    public function test_empty_push_fails_and_branch_cannot_request_push(): void
    {
        $job = $this->pushJob();
        (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame('failed', $job->fresh()->state);
        $this->assertEmpty($this->sheets->monitoring);
        $this->actingAs($this->user())->post(route('sync.push'), ['idempotency_key' => Str::uuid(), 'confirmed' => 1])->assertForbidden();
    }

    public function test_push_confirmation_and_idempotency_are_enforced(): void
    {
        Queue::fake();
        $key = (string) Str::uuid();
        $this->actingAs($this->area)->post(route('sync.push'), ['idempotency_key' => $key])->assertSessionHasErrors('confirmed');
        $this->post(route('sync.push'), ['idempotency_key' => $key, 'confirmed' => 1])->assertRedirect();
        $this->post(route('sync.push'), ['idempotency_key' => $key, 'confirmed' => 1])->assertRedirect();
        $this->assertSame(1, SyncJob::where('idempotency_key', $key)->count());
    }
}
