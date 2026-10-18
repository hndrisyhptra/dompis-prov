<?php

namespace Tests\Feature;

use App\Jobs\ProcessSheetSync;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchMapping;
use App\Models\WorkOrder;
use App\Services\BimaSheetPullService;
use Illuminate\Support\Facades\Cache;

class BimaPullTest extends OloTestCase
{
    public function test_historical_size_batch_is_idempotent_without_importing_customer_templates(): void
    {
        $rows = [];
        for ($index = 1; $index <= 3298; $index++) {
            $rows[] = $this->row('SYNTHETIC-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT));
        }
        $first = $this->pull($rows);
        $this->assertSame(3298, $first->stats['inserted']);
        $second = $this->pull($rows);
        $this->assertSame(3298, $second->stats['unchanged']);
        $this->assertSame(0, $second->stats['inserted']);
        $this->assertDatabaseCount('work_orders', 3298);
    }

    public function test_preview_never_writes_wo_and_encrypts_snapshot(): void
    {
        $job = $this->preview([$this->row()]);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertSame('preview_ready', $job->state);
        $this->assertSame(1, $job->stats['new']);
        $this->assertStringNotContainsString('Pelanggan Uji', $job->getRawOriginal('snapshot'));
        $this->assertArrayNotHasKey('snapshot', $job->toArray());
    }

    public function test_pull_is_idempotent_preserves_missing_wo_and_operational_progress(): void
    {
        $job = $this->pull([$this->row(), $this->row('000124')]);
        $this->assertSame(2, $job->stats['inserted']);
        $wo = WorkOrder::where('external_order_id', '000123')->firstOrFail();
        $wo->update(['operational_status' => 'IN_PROGRESS', 'revision' => 4, 'verification_status' => 'REVIEW', 'last_progress_at' => now()]);
        $lastProgress = $wo->fresh()->last_progress_at;
        $job = $this->pull([$this->row(changes: [13 => 'CLOSE', 17 => 'Catatan sumber'])]);
        $this->assertSame(1, $job->stats['updated']);
        $this->assertSame('IN_PROGRESS', $wo->fresh()->operational_status);
        $this->assertSame(4, $wo->fresh()->revision);
        $this->assertSame('REVIEW', $wo->fresh()->verification_status);
        $this->assertTrue($lastProgress->equalTo($wo->fresh()->last_progress_at));
        $this->assertSame('CLOSE', $wo->fresh()->bima_status);
        $job = $this->pull([$this->row(changes: [13 => 'CLOSE', 17 => 'Catatan sumber'])]);
        $this->assertSame(1, $job->stats['unchanged']);
        $this->assertDatabaseCount('work_orders', 2);
        $this->assertSame('NEW', WorkOrder::where('external_order_id', '000124')->value('operational_status'));
    }

    public function test_duplicate_blank_and_invalid_date_rows_are_rejected_others_continue(): void
    {
        $job = $this->pull([$this->row('DUP'), $this->row('DUP'), $this->row(''), $this->row('BAD-DATE', [1 => '31/02/2026']), $this->row('VALID')]);
        $this->assertSame('partial_success', $job->state);
        $this->assertSame(4, $job->stats['rejected']);
        $this->assertSame(1, $job->stats['inserted']);
        $this->assertDatabaseCount('work_orders', 1);
    }

    public function test_branch_change_unknown_mapping_and_stale_source_become_conflicts(): void
    {
        $this->pull([$this->row()]);
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Other']);
        BranchMapping::create(['bima_branch' => 'OTHER', 'id_branches' => $other->getKey()]);
        $job = $this->pull([$this->row(changes: [5 => 'OTHER']), $this->row('NEW', [5 => 'UNKNOWN'])]);
        $this->assertSame(2, $job->stats['conflict']);
        $this->assertSame($this->branch->getKey(), WorkOrder::first()->id_branches);
        $stale = $this->preview([$this->row(changes: [13 => 'CLOSE'])]);
        $this->pull([$this->row(changes: [13 => 'CHANGED AGAIN'])]);
        app(BimaSheetPullService::class)->pull($stale);
        $this->assertSame(1, $stale->fresh()->stats['conflict']);
        $this->assertSame('CHANGED AGAIN', WorkOrder::first()->bima_status);
    }

    public function test_mapping_change_after_preview_is_checked_before_commit(): void
    {
        $job = $this->preview([$this->row()]);
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Other']);
        BranchMapping::first()->update(['id_branches' => $other->getKey()]);
        app(BimaSheetPullService::class)->pull($job);
        $this->assertSame(1, $job->fresh()->stats['conflict']);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_retry_same_commit_does_not_duplicate_wo_or_audit(): void
    {
        $job = $this->preview([$this->row()]);
        $job->update(['stage' => 'commit']);
        $service = app(BimaSheetPullService::class);
        $service->pull($job);
        $audits = AuditLog::where('action', 'bima.inserted')->count();
        $service->pull($job->fresh());
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertSame($audits, AuditLog::where('action', 'bima.inserted')->count());
    }

    public function test_job_header_failure_stops_before_any_wo_write_and_revoked_actor_is_blocked(): void
    {
        $job = $this->preview([]);
        $job->update(['state' => 'queued']);
        $this->sheets->rows = [['BAD HEADER'], $this->row()];
        (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame('failed', $job->fresh()->state);
        $this->assertDatabaseCount('work_orders', 0);
        $this->area->forceFill(['active' => false])->save();
        $job->refresh()->update(['state' => 'queued']);
        (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame('failed', $job->fresh()->state);
        $this->assertStringContainsString('dicabut', $job->fresh()->error);
    }

    public function test_connection_lock_defers_job_without_writing(): void
    {
        $job = $this->preview([]);
        $job->update(['state' => 'queued']);
        $lock = Cache::lock('sheets:'.$job->connection_hash, 240);
        $lock->get();
        (new ProcessSheetSync($job->getKey()))->handle(app(BimaSheetPullService::class));
        $this->assertSame('queued', $job->fresh()->state);
        $lock->release();
    }

    public function test_late_duplicate_queue_failure_cannot_overwrite_terminal_success(): void
    {
        $job = $this->pull([$this->row()]);
        (new ProcessSheetSync($job->getKey()))->failed(new \RuntimeException('Synthetic duplicate queue delivery'));
        $this->assertSame('success', $job->fresh()->state);
        $this->assertNull($job->fresh()->error);
    }
}
