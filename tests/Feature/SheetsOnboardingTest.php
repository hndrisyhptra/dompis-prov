<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Services\BimaRowMapper;
use Illuminate\Support\Facades\Queue;

class SheetsOnboardingTest extends OloTestCase
{
    public function test_empty_real_read_is_visible_but_cannot_be_committed(): void
    {
        Queue::fake();
        $job = $this->preview([]);
        $this->actingAs($this->area)->get(route('sync.show', $job))->assertOk()
            ->assertSee('BIMA_RAW belum berisi order')->assertDontSee('Konfirmasi & tarik ke MySQL', false);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertConflict();
        $this->assertSame('preview', $job->fresh()->stage);
        $this->assertDatabaseCount('work_orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_completed_read_status_is_scoped_to_current_connection(): void
    {
        // Only exercise the local file-readability indicator; the gateway remains the test fake.
        config(['olo.sheets.driver' => 'google', 'olo.sheets.spreadsheet_id' => 'test-connection-spreadsheet', 'olo.sheets.credentials_path' => __FILE__]);
        $job = $this->preview([]);
        $this->actingAs($this->area)->get('/sync')->assertOk()->assertSee('Pembacaan BIMA_RAW terakhir berhasil');
        $job->update(['connection_hash' => str_repeat('0', 64)]);
        $this->get('/sync')->assertOk()->assertDontSee('Pembacaan BIMA_RAW terakhir berhasil');
    }

    public function test_sync_center_shows_source_link_and_missing_credentials_without_calling_google(): void
    {
        config(['olo.sheets.driver' => 'google', 'olo.sheets.spreadsheet_id' => 'test-spreadsheet-identifier', 'olo.sheets.credentials_path' => '']);
        $this->actingAs($this->area)->get('/sync')->assertOk()
            ->assertSee('https://docs.google.com/spreadsheets/d/test-spreadsheet-identifier/edit', false)
            ->assertSee('Kredensial service account belum tersedia')
            ->assertSee('BIMA_RAW sebagai tab tambahan');
        $this->assertSame(0, $this->sheets->reads);
    }

    public function test_diagnostic_checks_header_and_data_without_import_or_publish(): void
    {
        $this->sheets->rows = [BimaRowMapper::HEADERS, $this->row()];
        $this->artisan('olo:sheets:check')->expectsOutputToContain('header 32 kolom sesuai kontrak')
            ->expectsOutputToContain('Baris valid format: 1')->assertSuccessful();
        $this->assertSame(0, WorkOrder::count());
        $this->assertSame([], $this->sheets->monitoring);
    }

    public function test_diagnostic_detects_duplicate_ids_and_bad_headers(): void
    {
        $this->sheets->rows = [BimaRowMapper::HEADERS, $this->row(), $this->row()];
        $this->artisan('olo:sheets:check')->expectsOutputToContain('ID duplikat: 1')->assertFailed();
        $this->sheets->rows = [['WRONG HEADER']];
        $this->artisan('olo:sheets:check')->expectsOutputToContain('Header BIMA_RAW harus persis 32 kolom')->assertFailed();
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_diagnostic_simulation_never_claims_google_is_connected(): void
    {
        config(['olo.sheets.driver' => 'fake']);
        $this->sheets->rows = [BimaRowMapper::HEADERS];
        $this->artisan('olo:sheets:check')->expectsOutputToContain('SIMULASI')->assertSuccessful();
    }
}
