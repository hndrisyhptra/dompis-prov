<?php

namespace Tests\Feature;

use App\Jobs\ProcessSheetSync;
use App\Models\WorkOrder;
use App\Services\BimaSheetPullService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class NamedKeysBehaviorTest extends OloTestCase
{
    public function test_mapping_form_and_job_json_use_named_ids(): void
    {
        $this->actingAs($this->area)->post(route('mappings.store'), ['bima_branch' => 'NEW LABEL', 'id_branches' => $this->branch->getKey()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('branch_mappings', ['bima_branch' => 'NEW LABEL', 'id_branches' => $this->branch->getKey()]);
        $this->post(route('mappings.store'), ['bima_branch' => 'INVALID LABEL', 'id_branches' => 999999])->assertSessionHasErrors('id_branches');
        $job = $this->preview([$this->row()]);
        $this->getJson(route('sync.show', $job))->assertOk()->assertJsonPath('id_sync_jobs', $job->getKey())->assertJsonMissingPath('id')->assertJsonMissingPath('snapshot');
    }

    public function test_preview_created_before_id_rename_can_still_pull_idempotently(): void
    {
        $job = $this->preview([$this->row()]);
        $snapshot = $job->snapshot;
        foreach ($snapshot as &$entry) {
            $entry['branch_id'] = $entry['id_branches'];
            unset($entry['id_branches']);
        }
        unset($entry);
        $job->update(['snapshot' => $snapshot, 'stage' => 'commit']);
        app(BimaSheetPullService::class)->pull($job);
        app(BimaSheetPullService::class)->pull($job->fresh());
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertSame($this->branch->getKey(), WorkOrder::firstOrFail()->id_branches);
        $this->assertSame('success', $job->fresh()->state);
    }

    public function test_database_session_can_restore_user_with_custom_primary_key_and_logout(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $this->post('/login', ['email' => $this->area->email, 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('sessions', ['user_id' => $this->area->getKey()]);
        $sessionId = session()->getId();
        $this->app['auth']->forgetGuards();
        $this->withCookie(config('session.cookie'), $sessionId)->get('/sync')->assertOk();
        $this->assertAuthenticatedAs($this->area);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_database_queue_cache_locks_and_failed_jobs_keep_laravel_contracts(): void
    {
        $job = $this->preview([$this->row()]);
        $job->update(['stage' => 'commit', 'state' => 'queued']);
        $queue = Queue::connection('database');
        $id = $queue->push(new ProcessSheetSync($job->getKey()));
        $this->assertDatabaseHas('jobs', ['id' => $id]);
        $message = $queue->pop();
        $this->assertSame((string) $id, (string) $message->getJobId());
        $message->release(0);
        $this->assertSame(1, $queue->size());
        $message = $queue->pop();
        $message->fire();
        $message->delete();
        $this->assertSame(0, $queue->size());
        $this->assertSame('success', $job->fresh()->state);
        $this->assertDatabaseCount('work_orders', 1);

        $cache = Cache::store('database');
        $cache->put('named-id-test', 'value', 60);
        $this->assertSame('value', $cache->get('named-id-test'));
        $lock = $cache->lock('named-id-lock', 10);
        $this->assertTrue($lock->get());
        $this->assertFalse($cache->lock('named-id-lock', 10)->get());
        $this->assertTrue($lock->release());
        $cache->forget('named-id-test');

        $failed = app('queue.failer');
        $uuid = $failed->log('database', 'default', json_encode(['uuid' => '00000000-0000-4000-8000-000000000999']), new \RuntimeException('Synthetic failure'));
        $this->assertSame($uuid, $failed->find($uuid)->id);
        $this->assertTrue($failed->forget($uuid));
        $batch = Bus::batch([])->name('Named keys test')->dispatch();
        $this->assertSame('Named keys test', Bus::findBatch($batch->id)->name);
        $batch->delete();
    }
}
