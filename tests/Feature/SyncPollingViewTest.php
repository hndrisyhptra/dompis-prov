<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SyncJob;
use App\Services\ExcelWorkOrderImportService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SyncPollingViewTest extends OloTestCase
{
    private function job(array $attributes = []): SyncJob
    {
        config(['olo.inbound' => 'excel']);

        return SyncJob::create($attributes + ['idempotency_key' => (string) Str::uuid(), 'id_users' => $this->area->getKey(), 'direction' => 'import',
            'stage' => 'preview', 'state' => 'preview_ready', 'stats' => ['read' => 2, 'new' => 2], 'expires_at' => now()->addMinutes(30),
            'connection_hash' => ExcelWorkOrderImportService::connectionHash(), 'snapshot' => ['import' => ['private' => 'not-in-status']]]);
    }

    public function test_different_operator_sees_specific_owner_message_and_never_gets_confirmation_permission(): void
    {
        Queue::fake();
        $job = $this->job();
        $other = $this->user(Role::SuperAdmin);
        $this->actingAs($other)->get(route('sync.show', $job))->assertOk()->assertSee($this->area->name)
            ->assertSee('Hanya akun pengunggah yang dapat mengonfirmasi import')->assertDontSee('Preview sudah kedaluwarsa')
            ->assertDontSee('name="confirmed"', false)->assertDontSee('Konfirmasi &amp; import ke MySQL', false);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertForbidden();
        $this->assertSame('preview_ready', $job->fresh()->state);
        $this->actingAs($this->area)->get(route('sync.show', $job))->assertOk()->assertSee('name="confirmed"', false)->assertSee('Konfirmasi &amp; import ke MySQL', false);
        $this->assertDatabaseCount('work_orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_each_preview_blocker_is_explained_and_backend_still_rejects_commit(): void
    {
        Queue::fake();
        $this->actingAs($this->area);
        foreach ([
            [['expires_at' => now()->subMinute()], 'excel', 'Preview sudah kedaluwarsa'],
            [['expires_at' => null], 'excel', 'Preview sudah kedaluwarsa'],
            [[], 'sheets', 'Mode penerimaan WO sudah berubah'],
            [['connection_hash' => str_repeat('a', 64)], 'excel', 'struktur kolom import berubah'],
            [['stats' => ['read' => 0]], 'excel', 'Preview belum memiliki baris order'],
        ] as [$attributes, $mode, $message]) {
            $job = $this->job($attributes);
            config(['olo.inbound' => $mode]);
            $this->get(route('sync.show', $job))->assertOk()->assertSee($message)->assertDontSee('name="confirmed"', false);
            $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertConflict();
            $this->assertSame('preview_ready', $job->fresh()->state);
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_polling_uses_relative_url_and_uncached_json_even_with_wrong_absolute_app_root(): void
    {
        $job = $this->job(['state' => 'running']);
        $this->actingAs($this->area);
        URL::forceRootUrl('http://old-backend.test');
        try {
            $page = $this->get('/sync/jobs/'.$job->getKey())->assertOk()->assertSee('data-sync-poll="/sync/jobs/'.$job->getKey().'"', false)
                ->assertSee('data-poll-retry', false);
            $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
            $this->assertStringContainsString('Accept', $page->headers->get('Vary'));
            $status = $this->getJson('/sync/jobs/'.$job->getKey())->assertOk()->assertJsonPath('state', 'running')->assertJsonMissingPath('snapshot');
            $this->assertStringContainsString('no-store', $status->headers->get('Cache-Control'));
            $job->update(['state' => 'preview_ready']);
            $this->getJson('/sync/jobs/'.$job->getKey())->assertOk()->assertJsonPath('state', 'preview_ready');
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
