<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\BimaSheetPullService;
use App\Services\ExcelWorkOrderImportService;
use App\Services\ExcelWorkOrderMapper;
use App\Services\WorkOrderDerivedFields;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;

class ExcelWorkOrderImportTest extends OloTestCase
{
    private const HEADERS = ['IDENTITAS', 'CABANG SUMBER', 'KODE STO', 'PELANGGAN', 'STATUS ORDER', 'TGL PS', 'SID', 'BRANCH', 'STATUS FINAL'];

    private const MAP = ['no_tq_sc_id' => 1, 'branch' => 2, 'sto' => 3, 'nama_olo' => 4, 'status_bima' => 5, 'tgl_ps' => 6, 'sid' => 7, 'status_final' => 9];

    private function upload(array $rows, ?string $key = null): SyncJob
    {
        $file = XlsxFile::make(self::HEADERS, $rows);
        $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => $key ?? (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        unlink($file->getRealPath());

        return SyncJob::latest('id_sync_jobs')->firstOrFail();
    }

    private function excelPreview(SyncJob $job, array $mapping = self::MAP): void
    {
        $this->actingAs($this->area)->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => $mapping])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('preview_ready', $job->refresh()->state);
    }

    private function excelCommit(SyncJob $job): void
    {
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $job->refresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['olo.inbound' => 'excel']);
        Queue::fake();
    }

    private function excelRow(string $id = '000321', array $changes = []): array
    {
        return array_replace([$id, 'BRANCH UJI', 'TST', 'Pelanggan Privat Uji', 'COMPLETE', '2026-10-19', '000SID', 'DISTRICT REFERENSI', 'CLOSE'], $changes);
    }

    public function test_new_flow_is_default_without_google_and_upload_mapping_preview_commit_are_idempotent(): void
    {
        $this->actingAs($this->area)->get(route('sync.index'))->assertOk()->assertSee('Upload &amp; periksa kolom', false)->assertDontSee('Buat preview BIMA_RAW');
        $this->post(route('sync.preview'), ['idempotency_key' => (string) Str::uuid()])->assertConflict();
        $key = (string) Str::uuid();
        $job = $this->upload([$this->excelRow()], $key);
        $this->upload([$this->excelRow()], $key);
        $this->assertDatabaseCount('sync_jobs', 1);
        $this->assertDatabaseCount('sync_job_rows', 1);
        $this->get(route('imports.mapping', $job))->assertOk()->assertSee('Mapping import Excel')->assertSee('Pelanggan Privat Uji');
        $payload = DB::table('sync_job_rows')->value('payload');
        $this->assertStringNotContainsString('Pelanggan Privat Uji', $payload);
        $this->assertArrayNotHasKey('payload', $job->rows()->first()->toArray());
        $this->excelPreview($job);
        $this->assertDatabaseCount('work_orders', 0);
        $this->getJson(route('sync.show', $job))->assertJsonMissingPath('snapshot');
        $this->excelCommit($job);
        $this->assertSame('success', $job->state);
        $order = WorkOrder::sole();
        $this->assertSame('000321', $order->no_tq_sc_id);
        $this->assertSame('000SID', $order->sid);
        $this->assertNull($order->source_district);
        $this->assertSame($this->branch->getKey(), $order->id_branches);
        $this->assertNull($job->rows()->first()->payload);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        Queue::assertPushed(ProcessWorkOrderImport::class, 2);
        $again = $this->upload([$this->excelRow()]);
        $this->excelPreview($again);
        $this->assertSame(1, $again->stats['unchanged']);
        $this->excelCommit($again);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertSame(0, $this->sheets->reads);
    }

    public function test_permissions_owner_mapping_safety_expiry_lock_and_revocation_are_enforced(): void
    {
        foreach ([Role::BranchAdmin, Role::TeamLeader, Role::MaterialAdmin, Role::AreaCoordinator] as $role) {
            $this->actingAs($this->user($role))->post(route('imports.upload'))->assertForbidden();
        }
        $job = $this->upload([$this->excelRow()]);
        $this->actingAs($this->user(Role::AreaAdmin))->get(route('imports.mapping', $job))->assertForbidden();
        $this->post(route('imports.preview', $job), ['mapping' => self::MAP, 'confirmed_mapping' => 1])->assertForbidden();
        $this->actingAs($this->area)->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => self::MAP + ['source_district' => 8]])->assertSessionHasErrors('mapping');
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => ['no_tq_sc_id' => 1]])->assertSessionHasErrors('mapping');
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => ['no_tq_sc_id' => 1, 'sto' => 1]])->assertSessionHasErrors('mapping');
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => ['no_tq_sc_id' => 100, 'sto' => 2]])->assertSessionHasErrors('mapping.no_tq_sc_id');
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => self::MAP])->assertRedirect();
        $lock = Cache::lock('olo:work-order-inbound', 240);
        $lock->get();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('queued', $job->fresh()->state);
        $lock->release();
        $this->area->forceFill(['active' => false])->save();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('failed', $job->fresh()->state);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_duplicate_invalid_and_numeric_ids_are_rejected_and_valid_rows_still_commit(): void
    {
        $job = $this->upload([$this->excelRow('DUP'), $this->excelRow('DUP', [8 => 'WRONG']), $this->excelRow('GOOD'), $this->excelRow('BAD-FINAL', [8 => 'WRONG']), $this->excelRow('1', [0 => ['type' => 'n', 'value' => '123']]), $this->excelRow('FORMULA', [3 => ['type' => 'str', 'value' => 'cached', 'formula' => 'A1']])]);
        $this->excelPreview($job);
        $this->assertSame(5, $job->stats['rejected']);
        $this->assertSame(1, $job->stats['new']);
        $this->excelCommit($job);
        $this->assertSame('partial_success', $job->state);
        $this->assertSame(['GOOD'], WorkOrder::pluck('no_tq_sc_id')->all());
        $this->assertSame(0, $job->rows()->whereNotNull('payload')->count());
    }

    public function test_import_does_not_overwrite_progress_unmapped_fields_or_issues_and_detects_stale_sources(): void
    {
        $this->pull([$this->row('000321')]);
        $order = WorkOrder::sole();
        $order->update(['progress_category' => 'SURVEY', 'operational_status' => 'SURVEY', 'revision' => 4, 'alamat' => 'Alamat lama', 'bw' => '100M']);
        $order->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'OTHER', 'description' => 'Kendala tetap', 'pic' => 'PIC']);
        $before = $order->fresh()->only(['progress_category', 'operational_status', 'revision', 'last_progress_at', 'branch_completed_at', 'verified_at', 'alamat', 'bw']);
        $job = $this->upload([$this->excelRow()]);
        $this->excelPreview($job);
        $this->excelCommit($job);
        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('wo_issues', 1);
        $stale = $this->upload([$this->excelRow(changes: [3 => 'Perubahan baru'])]);
        $this->excelPreview($stale);
        $order->update(['source_snapshot_hash' => str_repeat('a', 64)]);
        $this->excelCommit($stale);
        $this->assertSame(1, $stale->stats['conflict']);
        $this->assertSame('Pelanggan Privat Uji', $order->fresh()->nama_olo);
    }

    public function test_only_one_branch_is_used_even_with_duplicate_headers_and_reference_formula_is_not_a_kpi(): void
    {
        $file = XlsxFile::make(['NO TQ/SC/ID', 'BRANCH', 'STO', 'BRANCH', 'PS HARI INI'], [['000888', 'BRANCH UJI', 'TST', ['type' => 'str', 'formula' => 'A1', 'value' => 'District cached'], ['type' => 'n', 'formula' => '1', 'value' => '1']]]);
        $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        unlink($file->getRealPath());
        $job = SyncJob::latest('id_sync_jobs')->first();
        $mapping = (new ExcelWorkOrderMapper)->suggest($job->snapshot['import']['headers']);
        $this->assertSame('2', $mapping['branch']);
        $this->assertArrayNotHasKey('source_district', $mapping);
        $this->get(route('imports.mapping', $job))->assertOk()->assertSee('name="mapping[branch]"', false)->assertDontSee('mapping[source_district]', false);
        $this->excelPreview($job, $mapping);
        $this->excelCommit($job);
        $order = WorkOrder::sole();
        $this->assertSame('1', $order->ps_hari_ini);
        $this->assertNull($order->progress_category);
        $this->assertFalse($order->isPsEligible());
        $this->assertSame('BRANCH UJI', $order->branch);
        $this->assertArrayNotHasKey('BRANCH (district referensi)', $order->source_bima_fields);
        $this->get(route('work-orders.show', $order))->assertOk()->assertDontSee('BRANCH (district referensi)');
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertDatabaseCount('work_orders', 1);
    }

    public function test_all_requested_columns_are_mapped_and_displayed_without_changing_branch_progress(): void
    {
        $headers = ['TGL ORDER', 'BULAN', 'TAHUN', 'REGIONAL', 'BRANCH', 'STO', 'NO TQ/SC/ID', 'NAMA OLO', 'ALAMAT', 'LAYANAN', 'JENIS PEKERJAAN', 'BW', 'STATUS ORDER', 'TASK BIMA', 'TGL PS', 'SID', 'KETERANGAN', 'ODP', 'SN', 'OLT', 'PORT OLT', 'METRO', 'PORT METRO', 'STATUS FINAL (OPEN,CLOSE,CANCEL)', 'BULAN PS', 'PS HARI INI', 'UMUR (HARI)', 'KATEGORI UMUR'];
        $values = ['2026-10-01', 'OKTOBER', '2026', 'REGION UJI', 'BRANCH UJI', 'TST', '000999', 'Nama Uji', 'Alamat Uji', 'METRO', 'NEW INSTALL', '100M', 'COMPLETE', 'ACTIVATION', '2026-10-19', '000SID', 'Catatan BIMA', 'ODP-01', '000SN', 'OLT-01', '0/1', 'METRO-01', '0/2', 'CLOSE', 'OKTOBER', '1', '18', '15-30'];
        $file = XlsxFile::make($headers, [$values]);
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::sole();
        $mapping = (new ExcelWorkOrderMapper)->suggest($headers);
        $this->assertCount(24, $mapping);
        $this->excelPreview($job, $mapping);
        $this->excelCommit($job);
        $order = WorkOrder::sole();
        foreach ($mapping as $field => $column) {
            $actual = in_array($field, ['tgl_order', 'tgl_ps']) ? $order->$field->format('Y-m-d') : $order->$field;
            $this->assertSame($values[(int) $column - 1], $actual, $field);
        }
        $this->assertSame('10', $order->bulan);
        $this->assertSame('2026', $order->tahun);
        $this->assertSame('ACTIVATION-COMPLETE', $order->status_order);
        $this->assertNull($order->mapping_order);
        $this->assertSame(WorkOrderDerivedFields::dates('2026-10-01')['umur_hari'], $order->umur_hari);
        $this->assertNull($order->progress_category);
        $this->assertSame('NEW', $order->operational_status);
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Catatan BIMA')->assertSee('METRO-01')->assertSee('BRANCH UJI');
    }

    public function test_large_excel_snapshot_uses_small_per_row_payloads_and_commits_idempotently(): void
    {
        $rows = [];
        for ($i = 0; $i < 170; $i++) {
            $rows[] = ['000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'BRANCH UJI', str_repeat('A', 8000)];
        }
        $file = XlsxFile::make(['NO TQ/SC/ID', 'BRANCH', 'ALAMAT'], $rows);
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::sole();
        $lengths = DB::table('sync_job_rows')->pluck('payload')->map('strlen');
        $this->assertGreaterThan(1024 * 1024, $lengths->sum());
        $this->assertLessThan(65536, $lengths->max());
        $this->excelPreview($job, ['no_tq_sc_id' => 1, 'branch' => 2, 'alamat' => 3]);
        $this->excelCommit($job);
        $this->assertSame(170, $job->stats['inserted']);
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertDatabaseCount('work_orders', 170);
        $this->assertSame(0, $job->rows()->whereNotNull('payload')->count());
    }

    public function test_expired_mapping_preview_and_changed_branch_are_blocked(): void
    {
        $job = $this->upload([$this->excelRow()]);
        $job->update(['expires_at' => now()->subMinute()]);
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => self::MAP])->assertConflict();
        $this->get(route('imports.mapping', $job))->assertOk()->assertSee('Sesi mapping kedaluwarsa');
        $job->update(['expires_at' => now()->addMinute()]);
        $this->excelPreview($job);
        $job->update(['expires_at' => now()->subMinute()]);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertConflict();
        $job->update(['expires_at' => now()->addMinute()]);
        $this->branch->update(['active' => false]);
        $this->excelCommit($job);
        $this->assertSame(1, $job->stats['conflict']);
        $this->assertDatabaseCount('work_orders', 0);
        $legacy = SyncJob::create(['idempotency_key' => (string) Str::uuid(), 'id_users' => $this->area->getKey(), 'direction' => 'pull', 'state' => 'failed', 'connection_hash' => BimaSheetPullService::connectionHash()]);
        $this->post(route('sync.retry', $legacy))->assertConflict();
    }

    public function test_old_column_snapshots_cannot_be_mapped_or_committed_after_schema_change(): void
    {
        foreach (['olo-work-order-xlsx-v1', 'olo-work-order-xlsx-v2-single-branch', 'olo-work-order-xlsx-v3-indonesian-columns'] as $version) {
            $this->travel(1)->minutes();
            $job = $this->upload([$this->excelRow()]);
            $this->excelPreview($job);
            $job->update(['connection_hash' => hash('sha256', $version)]);
            $this->get(route('imports.mapping', $job))->assertConflict();
            $this->get(route('sync.show', $job))->assertOk()->assertSee('untuk memakai struktur kolom terbaru')->assertDontSee('Konfirmasi &amp; import ke MySQL', false);
            $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => self::MAP])->assertConflict();
            $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertConflict();
            $job->update(['stage' => 'commit', 'state' => 'queued']);
            (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
            $this->assertSame('failed', $job->fresh()->state);
            $this->assertDatabaseCount('work_orders', 0);
        }
    }
}
