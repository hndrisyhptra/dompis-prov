<?php

namespace Tests\Feature;

use App\Console\LocalServeCommand;
use App\Models\SyncJob;
use App\Support\WorkOrderUpload;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;
use ZipArchive;

class WorkOrderUploadTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['olo.inbound' => 'excel']);
        Queue::fake();
    }

    private function fields(): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'header_row' => 1];
    }

    public function test_php_upload_errors_are_actionable_and_never_create_import_jobs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'olo-upload-test-');
        try {
            foreach ([UPLOAD_ERR_INI_SIZE => 'batas upload server', UPLOAD_ERR_PARTIAL => 'terputus', UPLOAD_ERR_NO_TMP_DIR => 'menyimpan file sementara', UPLOAD_ERR_CANT_WRITE => 'menyimpan file sementara'] as $code => $message) {
                $this->travel(1)->minutes();
                $file = new UploadedFile($path, 'bima.xlsx', null, $code, true);
                $response = $this->actingAs($this->area)->from(route('sync.index'))->post(route('imports.upload'), $this->fields() + ['file' => $file]);
                $response->assertRedirect(route('sync.index'))->assertSessionHasErrors('file');
                $this->assertStringContainsString($message, session('errors')->first('file'));
                $this->assertStringNotContainsString('The file failed to upload', session('errors')->first('file'));
            }
        } finally {
            unlink($path);
        }
        $this->assertDatabaseCount('sync_jobs', 0);
        $this->assertDatabaseCount('work_orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_missing_wrong_extension_and_application_oversize_are_localized(): void
    {
        $this->actingAs($this->area)->post(route('imports.upload'), $this->fields())->assertSessionHasErrors(['file' => 'Pilih file XLSX hasil export BIMA terlebih dahulu.']);
        $this->post(route('imports.upload'), $this->fields() + ['file' => UploadedFile::fake()->create('bima.xls', 1)])->assertSessionHasErrors(['file' => 'Gunakan file berformat XLSX. Simpan file XLS sebagai XLSX terlebih dahulu.']);
        $this->post(route('imports.upload'), $this->fields() + ['file' => UploadedFile::fake()->create('large.xlsx', 10241)])->assertSessionHasErrors(['file' => 'Ukuran file melebihi batas aplikasi 10 MB.']);
        $this->post(route('imports.upload'), $this->fields() + ['file' => [UploadedFile::fake()->create('one.xlsx', 1)]])->assertSessionHasErrors(['file' => 'File upload tidak dapat dibaca. Pilih ulang file XLSX.']);
        $this->assertDatabaseCount('sync_jobs', 0);
    }

    public function test_post_body_too_large_has_indonesian_recovery_page_and_json_instead_of_generic_error(): void
    {
        $this->withServerVariables(['CONTENT_LENGTH' => 50 * 1024 * 1024]);
        $this->post(route('imports.upload'))->assertStatus(413)->assertSee('File terlalu besar')->assertSee('Kembali ke Sync Center')->assertDontSee('The POST data is too large');
        $this->postJson(route('imports.upload'))->assertStatus(413)->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('sync_jobs', 0);
    }

    public function test_workbook_larger_than_two_mb_reaches_mapping_without_creating_orders(): void
    {
        $file = XlsxFile::make(['NO TQ/SC/ID', 'BRANCH'], [['UPLOAD-TEST', 'BRANCH UJI']]);
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        $zip->addFromString('padding.txt', str_repeat('X', 3 * 1024 * 1024));
        $zip->setCompressionName('padding.txt', ZipArchive::CM_STORE);
        $zip->close();
        clearstatcache(true, $file->getRealPath());
        try {
            $this->assertGreaterThan(2 * 1024 * 1024, $file->getSize());
            $this->actingAs($this->area)->post(route('imports.upload'), $this->fields() + ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();
            $job = SyncJob::sole();
            $this->get(route('imports.mapping', $job))->assertOk()->assertSee('UPLOAD-TEST');
            $this->assertSame('mapping', $job->stage);
            $this->assertDatabaseCount('work_orders', 0);
        } finally {
            unlink($file->getRealPath());
        }
    }

    public function test_upload_limit_is_shared_by_ui_and_local_server_binding(): void
    {
        $this->assertInstanceOf(LocalServeCommand::class, app(ServeCommand::class));
        $this->actingAs($this->area)->get(route('sync.index'))->assertOk()
            ->assertViewHas('uploadLimitBytes', WorkOrderUpload::limitBytes())->assertSee('data-upload-limit="'.WorkOrderUpload::limitBytes().'"', false)
            ->assertSee('data-upload-feedback', false);
        $this->assertLessThanOrEqual(10 * 1024 * 1024, WorkOrderUpload::limitBytes());
    }
}
