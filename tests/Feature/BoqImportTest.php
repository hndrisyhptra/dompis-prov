<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\BoqJt;
use App\Models\DesignatorPackagePrice;
use App\Models\DesignatorType;
use App\Models\Package;
use App\Models\WorkOrder;
use App\Services\Boq\BoqImportService;
use App\Support\BoqAmount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\XlsxFile;

class BoqImportTest extends OloTestCase
{
    private function order(bool $jt = true): WorkOrder
    {
        $this->pull([$this->row('BOQ-WO', [14 => 'Review Order TSQ', 13 => 'STARTWORK'])]);
        $order = WorkOrder::sole();
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor);
        Storage::fake('local');
        if ($jt) {
            $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 0, 'progress_category' => 'JT', 'notes' => 'Pekerjaan memerlukan JT.', 'boq_next' => 'skip'])->assertSessionHasNoErrors()->assertRedirect(route('work-orders.show', $order));
        }

        return $order->fresh();
    }

    private function upload(WorkOrder $order, array $rows = [['M-RJ45-CAT6', '2']], array $extra = [], array $headers = ['DESIGNATOR', 'QTY'])
    {
        $this->travel(1)->minutes();
        $file = XlsxFile::make($headers, $rows, 'BOQ');
        try {
            return $this->post(route('boqs.store', $order), $extra + ['file' => $file, 'package_id' => 2, 'order_revision' => $order->fresh()->revision,
                'boq_revision' => $order->boq()->value('revision') ?? 0, 'header_row' => 1]);
        } finally {
            unlink($file->getRealPath());
        }
    }

    public function test_jt_is_optional_skip_preserves_progress_and_upload_remains_available_after_next_progress(): void
    {
        $order = $this->order();
        $this->get(route('work-orders.action.edit', [$order, 'progress']))->assertOk()->assertSee('Skip dulu')->assertSee('Simpan JT &amp; Upload RAB/BOQ', false);
        $this->assertDatabaseCount('boq_jt', 0);
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Upload RAB/BOQ')->assertSee('Pekerjaan memerlukan JT.');
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 1, 'progress_category' => 'INSTALASI', 'notes' => 'Lanjut instalasi.'])->assertSessionHasNoErrors();
        $this->get(route('boqs.create', $order))->assertOk()->assertSee('Skip dulu')->assertSee('Paket 5')->assertSee('Paket 10');
        $this->upload($order)->assertSessionHasNoErrors()->assertRedirect(route('boqs.show', $order));
        $this->assertSame('INSTALASI', $order->fresh()->progress_category);
        $this->assertSame(2, $order->fresh()->revision);
    }

    public function test_jt_upload_choice_redirects_after_saving_and_non_jt_or_unvisited_jt_cannot_import(): void
    {
        $order = $this->order(false);
        $this->get(route('boqs.create', $order))->assertUnprocessable();
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 0, 'progress_category' => 'SURVEY', 'notes' => 'Survey', 'boq_next' => 'upload'])->assertSessionHasErrors('boq_next');
        $this->assertSame(0, $order->fresh()->revision);
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 0, 'progress_category' => 'JT', 'notes' => 'JT perlu BOQ.', 'boq_next' => 'upload'])->assertSessionHasNoErrors()->assertRedirect(route('boqs.create', $order));
        $this->assertSame('JT', $order->fresh()->progress_category);
        $this->assertDatabaseCount('boq_jt', 0);
    }

    public function test_xlsx_import_exact_totals_private_source_preview_and_idempotent_retry_keep_work_order_unchanged(): void
    {
        $order = $this->order();
        $before = $order->getAttributes();
        $rows = [['M-RJ45-CAT6', '2'], ['M-Rak Pasif spliter 1:4', '1']];
        $this->upload($order, $rows)->assertSessionHasNoErrors()->assertRedirect(route('boqs.show', $order));
        $boq = BoqJt::sole();
        $this->assertSame('1615235.00', $boq->grand_total);
        $this->assertSame(2, $boq->item_count);
        $this->assertSame('MATERIAL', $boq->items()->first()->type);
        $this->assertSame(1, $boq->revision);
        $this->assertSame(1, $boq->histories()->count());
        Storage::disk('local')->assertExists($boq->file_path);
        $download = $this->get(route('boqs.download', $order))->assertOk();
        $this->assertSame($boq->file_hash, hash('sha256', $download->streamedContent()));
        $this->get(route('boqs.show', $order))->assertOk()->assertSee('1.615.235,00')->assertSee('Rincian material')->assertSee('Versi 1')->assertDontSee($boq->file_path);
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Preview BOQ');
        $this->upload($order, $rows, ['boq_revision' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('boq_histories', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('boq'));
        $this->assertEquals($before, $order->fresh()->getAttributes());
        DesignatorPackagePrice::where('designator_id', 531)->where('package_id', 2)->update(['price' => '999.99']);
        $this->assertSame('6500.00', $boq->items()->where('designator_id', 531)->sole()->unit_price);
    }

    public function test_replacement_appends_history_and_soft_deleted_item_versions_without_double_counting_current_total(): void
    {
        $order = $this->order();
        $this->upload($order)->assertSessionHasNoErrors();
        $boq = BoqJt::sole();
        $original = $boq->file_path;
        $this->upload($order, [['M-RJ45-CAT6', '3']])->assertSessionHasNoErrors();
        $boq->refresh();
        $this->assertSame('19500.00', $boq->grand_total);
        $this->assertSame(2, $boq->revision);
        $this->assertSame(1, $boq->items()->count());
        $this->assertSame(2, $boq->items()->withTrashed()->count());
        $this->assertSame(2, $boq->histories()->count());
        $latest = $boq->histories()->first();
        $this->assertSame('13000.00', $latest->before_data['grand_total']);
        $this->assertSame('6500.00', BoqItem::withTrashed()->findOrFail($latest->before_data['item_ids'][0])->unit_price);
        $this->assertNotNull(BoqItem::withTrashed()->findOrFail($latest->before_data['item_ids'][0])->deleted_at);
        $this->assertSame('19500.00', $latest->after_data['grand_total']);
        Storage::disk('local')->assertExists($original);
        $this->assertSame(2, AuditLog::whereIn('action', ['boq.created', 'boq.updated'])->count());
        $this->upload($order, [['M-RJ45-CAT6', '4']], ['boq_revision' => 1])->assertConflict();
        $this->assertSame('19500.00', $boq->fresh()->grand_total);
    }

    public function test_invalid_unknown_duplicate_formula_type_and_qty_fail_all_rows_without_files_or_partial_items(): void
    {
        $order = $this->order();
        foreach ([[['UNKNOWN', '1']], [['M-RJ45-CAT6', '-1']], [['M-RJ45-CAT6', '0']], [['M-RJ45-CAT6', '1,5']], [['M-RJ45-CAT6', '0.0001']], [['M-RJ45-CAT6', '1'], ['M-RJ45-CAT6', '2']], [['M-RJ45-CAT6', ['type' => 'n', 'formula' => '1+1', 'value' => '2']]]] as $rows) {
            $this->upload($order, $rows)->assertSessionHasErrors('file');
        }
        $this->upload($order, [['M-RJ45-CAT6', '1', 'JASA']], [], ['DESIGNATOR', 'QTY', 'TYPE'])->assertSessionHasErrors('file');
        DesignatorPackagePrice::where('designator_id', 531)->where('package_id', 2)->delete();
        $this->upload($order)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('boq_jt', 0);
        $this->assertDatabaseCount('boq_items', 0);
        $this->assertDatabaseCount('boq_histories', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('boq'));
    }

    public function test_decimal_rounding_uses_exact_arithmetic_and_rejects_database_overflow(): void
    {
        $order = $this->order();
        DesignatorPackagePrice::where('designator_id', 531)->where('package_id', 2)->update(['price' => '0.01']);
        $this->upload($order, [['M-RJ45-CAT6', '0.500']])->assertSessionHasNoErrors();
        $this->assertSame('0.01', BoqJt::sole()->grand_total);
        $this->assertSame('9.999.999.999.999.999,99', BoqAmount::format('9999999999999999.99'));
        DesignatorPackagePrice::where('designator_id', 531)->where('package_id', 2)->update(['price' => '9999999999999.99']);
        $this->upload($order, [['M-RJ45-CAT6', '999999999999.999']])->assertSessionHasErrors('file');
        $this->assertSame('0.01', BoqJt::sole()->grand_total);
    }

    public function test_csv_template_and_import_use_master_type_and_inactive_type_or_package_cannot_be_used(): void
    {
        $order = $this->order();
        $template = $this->get(route('boqs.template', $order))->assertOk()->streamedContent();
        $this->assertStringContainsString('DESIGNATOR,QTY,TYPE', $template);
        $file = UploadedFile::fake()->createWithContent('boq.csv', "\xEF\xBB\xBFDESIGNATOR,QTY,TYPE\nM-RJ45-CAT6,1,MATERIAL\n");
        $this->post(route('boqs.store', $order), ['file' => $file, 'package_id' => 2, 'order_revision' => 1, 'boq_revision' => 0, 'header_row' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('6500.00', BoqJt::sole()->grand_total);
        DesignatorType::findOrFail(1)->update(['is_active' => false]);
        $this->upload($order)->assertSessionHasErrors('file');
        Package::findOrFail(2)->delete();
        $this->upload($order)->assertSessionHasErrors('package_id');
    }

    public function test_scope_role_and_stale_assignment_are_checked_on_create_upload_preview_download_and_template(): void
    {
        $order = $this->order();
        $this->upload($order)->assertSessionHasNoErrors();
        foreach ([$this->user(Role::BranchHd), $this->user(Role::BranchAdmin, false)] as $actor) {
            $this->actingAs($actor);
            foreach (['boqs.create', 'boqs.show', 'boqs.download', 'boqs.template'] as $route) {
                $this->get(route($route, $order))->assertForbidden();
            }
            $this->post(route('boqs.store', $order))->assertForbidden();
        }
        $viewer = $this->user(Role::MaterialAdmin);
        $viewer->branches()->attach($this->branch);
        $this->actingAs($viewer)->get(route('boqs.show', $order))->assertOk();
        $this->get(route('boqs.create', $order))->assertForbidden();
        $this->post(route('boqs.store', $order))->assertForbidden();
        $this->actingAs($this->area);
        $order->increment('revision');
        $this->upload($order, extra: ['order_revision' => 1])->assertConflict();
        $this->assertDatabaseCount('boq_histories', 1);
    }

    public function test_failed_audit_rolls_back_new_boq_and_removes_only_the_new_private_file(): void
    {
        $order = $this->order();
        $actor = auth()->user();
        AuditLog::creating(function ($log) {
            if ($log->action === 'boq.created') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $file = XlsxFile::make(['DESIGNATOR', 'QTY'], [['M-RJ45-CAT6', '1']]);
        try {
            app(BoqImportService::class)->import($actor, $order, $file, ['package_id' => 2, 'order_revision' => 1, 'boq_revision' => 0, 'header_row' => 1]);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            unlink($file->getRealPath());
        }
        $this->assertDatabaseCount('boq_jt', 0);
        $this->assertDatabaseCount('boq_items', 0);
        $this->assertDatabaseCount('boq_histories', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('boq'));
        $this->assertSame('JT', $order->fresh()->progress_category);
    }

    public function test_bima_reimport_preserves_boq_snapshots_and_late_upload_can_use_old_jt_history(): void
    {
        $order = $this->order();
        $this->upload($order)->assertSessionHasNoErrors();
        $boq = BoqJt::sole();
        $before = $boq->getAttributes();
        $items = $boq->items()->get()->toArray();
        $histories = $boq->histories()->get()->toArray();
        $this->pull([$this->row('BOQ-WO', [14 => 'Activate Service', 13 => 'STARTWORK'])]);
        $this->assertSame('INSTALASI', $order->fresh()->progress_category);
        $this->assertEquals($before, $boq->fresh()->getAttributes());
        $this->assertEquals($items, $boq->items()->get()->toArray());
        $this->assertEquals($histories, $boq->histories()->get()->toArray());
        $this->assertTrue(app(BoqImportService::class)->eligible($order->fresh()));
        // Historical evidence may contain JT only as the old category of a later update.
        $order->events()->where('new_progress_category', 'JT')->delete();
        $this->assertTrue(app(BoqImportService::class)->eligible($order->fresh()));
        $this->get(route('boqs.create', $order))->assertOk();
    }

    public function test_failed_php_upload_and_oversize_recover_in_indonesian_without_creating_boq(): void
    {
        $order = $this->order();
        $path = tempnam(sys_get_temp_dir(), 'boq-upload-test-');
        try {
            $file = new UploadedFile($path, 'boq.xlsx', null, UPLOAD_ERR_PARTIAL, true);
            $this->post(route('boqs.store', $order), ['file' => $file])->assertSessionHasErrors('file');
            $this->assertStringContainsString('terputus', session('errors')->first('file'));
        } finally {
            unlink($path);
        }
        $this->withServerVariables(['CONTENT_LENGTH' => 50 * 1024 * 1024]);
        $this->post(route('boqs.store', $order))->assertStatus(413)->assertSee('Kembali ke Work Order')->assertDontSee('Kembali ke Sync Center');
        $this->postJson(route('boqs.store', $order))->assertStatus(413)->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('boq_jt', 0);
    }
}
