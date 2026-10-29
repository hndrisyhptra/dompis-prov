<?php

namespace Tests\Feature;

use App\Models\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class WebPerformanceTest extends OloTestCase
{
    public function test_diagnostics_are_disabled_by_default_and_do_not_log_other_routes(): void
    {
        Log::spy();
        $this->get('/login')->assertOk()->assertHeaderMissing('Server-Timing');
        config(['performance.enabled' => true, 'performance.slow_ms' => 0]);
        $this->actingAs($this->area)->get('/work-orders')->assertOk()->assertHeaderMissing('Server-Timing');
        Log::shouldNotHaveReceived('info');
    }

    public function test_timings_use_request_database_deltas_without_logging_inputs_or_sql(): void
    {
        config(['performance.enabled' => true, 'performance.slow_ms' => 0]);
        DB::connection()->logQuery('previous-request-secret', ['old-private-value'], 5000);
        Route::post('/sync/performance-probe', function () {
            DB::connection()->logQuery('private-sql-never-logged', ['customer-private-value'], 123);

            return response()->json(['ok' => true]);
        })->name('performance.probe');
        Log::spy();
        $response = $this->postJson('/sync/performance-probe?secret=private-query', ['password' => 'private-password', 'file' => 'private-workbook'])->assertOk();
        $this->assertStringContainsString('db;dur=123', $response->headers->get('Server-Timing'));
        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            $this->assertSame('web.performance', $message);
            $this->assertSame(['route', 'method', 'status', 'app_ms', 'database_ms'], array_keys($context));
            $this->assertSame('performance.probe', $context['route']);
            $this->assertSame('POST', $context['method']);
            $this->assertSame(200, $context['status']);
            $this->assertSame(123.0, $context['database_ms']);
            $this->assertStringNotContainsString('private-', json_encode($context));

            return true;
        });
    }

    public function test_threshold_and_denied_requests_do_not_change_access_or_responses(): void
    {
        config(['performance.enabled' => true, 'performance.slow_ms' => PHP_INT_MAX]);
        Log::spy();
        $this->get('/login')->assertOk()->assertHeader('Server-Timing');
        Log::shouldNotHaveReceived('info');
        config(['performance.slow_ms' => 0]);
        $this->actingAs($this->user())->get('/sync')->assertForbidden();
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => $message === 'web.performance' && $context['status'] === 403);
    }

    public function test_excel_sync_center_avoids_legacy_queries_and_full_history_snapshots(): void
    {
        config(['olo.inbound' => 'excel']);
        for ($i = 0; $i < 8; $i++) {
            SyncJob::create(['idempotency_key' => (string) Str::uuid(), 'id_users' => $this->area->getKey(), 'direction' => $i % 2 ? 'pull' : 'import',
                'stage' => 'commit', 'state' => 'success', 'connection_hash' => str_repeat('a', 64), 'finished_at' => now(), 'snapshot' => ['private' => str_repeat('source-payload', 1000)]]);
        }
        $this->actingAs($this->area);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $page = $this->get('/sync')->assertOk();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(5, $page->viewData('jobs'));
        $this->assertSame(8, $page->viewData('jobCount'));
        $this->assertLessThanOrEqual(4, count($queries));
        $page->assertViewHas('lastRead', null)->assertViewHas('lastPull', null)->assertViewHas('credentialsReadable', false);
        foreach ($page->viewData('jobs') as $job) {
            $this->assertArrayNotHasKey('snapshot', $job->getAttributes());
        }
        $this->assertArrayNotHasKey('snapshot', $page->viewData('lastImport')->getAttributes());
        $history = $this->get(route('sync.history'))->assertOk();
        foreach ($history->viewData('jobs') as $job) {
            $this->assertArrayNotHasKey('snapshot', $job->getAttributes());
        }
    }
}
