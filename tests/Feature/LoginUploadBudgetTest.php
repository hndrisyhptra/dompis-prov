<?php

namespace Tests\Feature;

use App\Models\SyncJob;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;

class LoginUploadBudgetTest extends OloTestCase
{
    private function measure(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        try {
            $response = $callback();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        return [$response, ['queries' => count($queries), 'database_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'app_ms' => round((hrtime(true) - $started) / 1e6, 2)]];
    }

    public function test_login_redirect_and_dashboard_have_bounded_queries_with_many_orders(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['source_system' => 'BIMA', 'no_tq_sc_id' => 'LOGIN-PERF-'.$i, 'id_branches' => $this->branch->getKey(),
                'jenis_pekerjaan' => 'NEW INSTALL', 'tgl_order' => '2026-10-20', 'progress_category' => 'SURVEY', 'mapping_order' => 'OPEN',
                'source_bima_fields' => '{}', 'source_snapshot_hash' => hash('sha256', 'login-'.$i)];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('work_orders')->insert($chunk);
        }
        [$login, $loginStats] = $this->measure(fn () => $this->post('/login', ['login' => $this->area->email, 'password' => 'password']));
        $login->assertRedirect(route('area.dashboard'));
        $this->assertAuthenticatedAs($this->area);
        $this->assertLessThanOrEqual(4, $loginStats['queries']);
        [$dashboard, $dashboardStats] = $this->measure(fn () => $this->get(route('area.dashboard')));
        $dashboard->assertOk()->assertViewHas('summary', fn ($summary) => $summary['total'] === 1000);
        $this->assertLessThanOrEqual(10, $dashboardStats['queries']);
        file_put_contents('/private/tmp/olo-login-request-profile-'.DB::getDriverName().'.json', json_encode(['login' => $loginStats, 'dashboard' => $dashboardStats], JSON_PRETTY_PRINT));
    }

    public function test_upload_staging_uses_bounded_batch_queries_and_never_runs_preview_during_upload(): void
    {
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $this->actingAs($this->area);
        $headers = ['Date Created', 'ORDER ID', 'Service No.', 'Description', 'CRM Order Type', 'Owner Group', 'Status',
            'Product Name', 'Address', 'Customer Name', 'Workzone', 'District TIF', 'Regional TIF', 'Date Modified'];
        $profiles = [];
        foreach ([200, 1000] as $size) {
            $rows = [];
            for ($i = 0; $i < $size; $i++) {
                $rows[] = ['2026-10-20', 'UPLOAD-PERF-'.$i, '000SID-'.$i, 'Site-Survey Manual', 'NEW INSTALL', 'DISTRICT', 'STARTWORK',
                    'METRO', 'Alamat sintetis '.$i, 'Pelanggan sintetis '.$i, 'TST', 'BRANCH UJI', 'REGION UJI', '2026-10-28'];
            }
            $file = XlsxFile::make($headers, $rows, 'Work Orders');
            try {
                [$response, $stats] = $this->measure(fn () => $this->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()]));
                $response->assertSessionHasNoErrors()->assertRedirect();
                $this->assertLessThanOrEqual((int) ceil($size / 50) + 5, $stats['queries']);
                $job = SyncJob::latest('id_sync_jobs')->firstOrFail();
                $this->assertSame('awaiting_mapping', $job->state);
                $this->assertSame($size, $job->rows()->count());
                $this->assertSame('000SID-0', $job->rows()->orderBy('row_index')->first()->payload['raw'][2]['value']);
                $this->get(route('imports.mapping', $job))->assertOk()->assertViewHas('rowCount', $size);
                $profiles[$size] = $stats + ['bytes' => filesize($file->getRealPath())];
            } finally {
                unlink($file->getRealPath());
            }
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, WorkOrder::count());
        file_put_contents('/private/tmp/olo-upload-request-profile-'.DB::getDriverName().'.json', json_encode($profiles, JSON_PRETTY_PRINT));
    }
}
