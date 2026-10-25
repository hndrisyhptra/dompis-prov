<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\WorkOrder;
use App\Models\WorkOrderKml;
use App\Support\WorkOrderUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class WorkOrderKmlTest extends OloTestCase
{
    private function order(): WorkOrder
    {
        $this->pull([$this->row('KML-WO')]);
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor);
        Storage::fake('local');

        return WorkOrder::sole();
    }

    private function xml(string $coordinate = '110,-7'): string
    {
        return '<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><name>Lokasi ODP</name><Point><coordinates>'.$coordinate.'</coordinates></Point></Placemark></kml>';
    }

    private function upload(WorkOrder $order, ?string $xml = null, array $extra = [])
    {
        $this->travel(1)->minutes();

        return $this->post(route('work-orders.kml.store', $order), $extra + [
            'file' => UploadedFile::fake()->createWithContent('jalur.kml', $xml ?? $this->xml()),
            'order_revision' => $order->fresh()->revision, 'kml_revision' => $order->kmls()->max('revision') ?? 0]);
    }

    public function test_empty_upload_private_source_geojson_preview_and_revisions_preserve_all_work_order_data(): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        $this->get(route('work-orders.kml.show', $order))->assertOk()->assertSee('Belum ada KML')->assertSee('Upload &amp; Preview peta', false);
        $this->upload($order)->assertSessionHasNoErrors()->assertRedirect(route('work-orders.kml.show', $order));
        $version = WorkOrderKml::sole();
        Storage::disk('local')->assertExists($version->file_path);
        $this->assertStringStartsWith('kml/', $version->file_path);
        $this->get(route('work-orders.kml.show', $order))->assertOk()->assertSee('data-kml-preview', false)->assertSee('Versi 1');
        $this->get(route('work-orders.kml.data', $order))->assertOk()->assertJsonPath('features.0.geometry.coordinates', [110, -7])->assertJsonPath('features.0.properties.name', 'Lokasi ODP');
        $response = $this->get(route('work-orders.kml.download', $order))->assertOk();
        $this->assertSame($this->xml(), $response->streamedContent());
        $this->upload($order, extra: ['kml_revision' => 0])->assertSessionHasNoErrors(); // Retry of the same bytes.
        $this->assertDatabaseCount('work_order_kmls', 1);
        $this->upload($order, $this->xml('111,-8'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('work_order_kmls', 2);
        Storage::disk('local')->assertExists($version->file_path);
        $this->assertSame(2, WorkOrderKml::max('revision'));
        $this->assertSame(2, AuditLog::where('action', 'work_order.kml_uploaded')->count());
        $this->get(route('work-orders.kml.data', $order))->assertOk()->assertJsonPath('features.0.geometry.coordinates', [111, -8]);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_branch_permissions_active_users_and_assignment_are_checked_on_all_routes(): void
    {
        $order = $this->order();
        $this->upload($order)->assertSessionHasNoErrors();
        foreach ([$this->user(Role::BranchHd), $this->user(Role::BranchAdmin, false)] as $actor) {
            $this->actingAs($actor);
            foreach (['show', 'data', 'download'] as $action) {
                $this->get(route('work-orders.kml.'.$action, $order))->assertForbidden();
            }
            $this->upload($order)->assertForbidden();
        }
        $this->actingAs($this->area);
        $this->get(route('work-orders.kml.show', $order))->assertOk();
        $reader = $this->user(Role::MaterialAdmin);
        $reader->branches()->attach($this->branch);
        $this->actingAs($reader);
        $this->get(route('work-orders.kml.show', $order))->assertOk()->assertDontSee('name="file"', false);
        $this->upload($order)->assertForbidden();
        $this->assertDatabaseCount('work_order_kmls', 1);
    }

    public function test_stale_kml_order_revision_and_reassignment_do_not_overwrite_attachment(): void
    {
        $order = $this->order();
        $this->upload($order)->assertSessionHasNoErrors();
        $this->upload($order, $this->xml('111,-8'), ['kml_revision' => 0])->assertConflict();
        $order->increment('revision');
        $this->upload($order, $this->xml('111,-8'), ['order_revision' => 0])->assertConflict();
        $order->update(['id_branches' => null]);
        $this->upload($order)->assertForbidden();
        $this->assertDatabaseCount('work_order_kmls', 1);
    }

    public function test_bad_xml_extension_entity_and_partial_upload_are_rejected_without_storing_files(): void
    {
        $order = $this->order();
        foreach (['not xml', '<kml/>', '<!DOCTYPE kml [<!ENTITY e SYSTEM "http://127.0.0.1/">]><kml>&e;</kml>', $this->xml('999,95')] as $xml) {
            $this->upload($order, $xml)->assertSessionHasErrors('file');
        }
        $this->upload($order, extra: ['file' => UploadedFile::fake()->createWithContent('route.kmz', $this->xml())])->assertSessionHasErrors('file');
        $path = tempnam(sys_get_temp_dir(), 'olo-kml-partial-');
        try {
            $partial = new UploadedFile($path, 'route.kml', null, UPLOAD_ERR_PARTIAL, true);
            $this->upload($order, extra: ['file' => $partial])->assertSessionHasErrors('file');
        } finally {
            unlink($path);
        }
        $this->assertDatabaseCount('work_order_kmls', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('kml'));
    }

    public function test_audit_failure_rolls_back_new_version_and_file(): void
    {
        $order = $this->order();
        $this->upload($order)->assertSessionHasNoErrors();
        $version = WorkOrderKml::sole();
        AuditLog::creating(function ($audit) {
            if ($audit->action === 'work_order.kml_uploaded') {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->withoutExceptionHandling();
            $this->upload($order, $this->xml('111,-8'));
            $this->fail('Audit failure must roll back');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('work_order_kmls', 1);
        $this->assertSame([$version->file_path], Storage::disk('local')->allFiles('kml'));
    }

    public function test_upload_limits_and_missing_data_preserve_work_order(): void
    {
        $order = $this->order();
        $before = $order->getRawOriginal();
        $this->get(route('work-orders.kml.data', $order))->assertNotFound();
        $this->get(route('work-orders.kml.download', $order))->assertNotFound();
        $this->upload($order, extra: ['file' => UploadedFile::fake()->create('big.kml', 5121)])->assertSessionHasErrors('file');
        $this->withServerVariables(['CONTENT_LENGTH' => 50 * 1024 * 1024]);
        $this->post(route('work-orders.kml.store', $order))
            ->assertStatus(413)->assertSee('KML')->assertSee(min(5, WorkOrderUpload::limitBytes() / 1024 / 1024).' MB')->assertSee('Kembali ke Work Order');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('work_order_kmls', 0);
    }
}
