<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessWorkOrderImport;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Services\BimaWorkOrdersTemplate;
use App\Services\ExcelWorkOrderImportService;
use App\Services\ExcelWorkOrderMapper;
use App\Services\WorkOrderDerivedFields;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\XlsxFile;

class BimaWorkOrdersTemplateTest extends OloTestCase
{
    public function test_excel_handoff_from_area_to_tl_hd_admin_branch_and_both_dashboards_uses_one_central_order(): void
    {
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $job = $this->import($this->templateRow([7 => 'Approval E2E Testing Manual', 10 => 'STARTWORK']));
        $this->assertSame(1, $job->stats['inserted']);
        $this->get(route('sync.show', $job))->assertOk()->assertSee('Order terpusat, Branch siap menindaklanjuti')->assertSee('Buka Work Orders');
        $order = WorkOrder::sole();
        $hash = $order->source_snapshot_hash;
        $users = [];
        foreach ([Role::TeamLeader, Role::BranchHd, Role::BranchAdmin] as $role) {
            $user = $this->user($role);
            $user->branches()->attach($this->branch);
            $users[] = $user;
            $this->actingAs($user)->get(route('work-orders.index'))->assertOk()->assertSee('000777')->assertSee(route('work-orders.action.edit', [$order, 'close']));
            $this->post(route('imports.upload'))->assertForbidden();
        }
        $url = route('work-orders.action.update', [$order, 'progress']);
        $issueUrl = route('work-orders.action.update', [$order, 'issue']);
        $closeUrl = route('work-orders.action.update', [$order, 'close']);
        $this->actingAs($users[0])->patch($url, ['revision' => 0, 'progress_category' => 'SURVEY', 'notes' => 'Survey sudah dijadwalkan.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($users[1])->patch($issueUrl, ['revision' => 1, 'progress_category' => 'HOLD', 'notes' => 'Akses gedung tertunda.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($users[2])->patch($issueUrl, ['revision' => 2, 'progress_category' => 'HOLD', 'notes' => 'PIC mengonfirmasi jadwal akses.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->patch($closeUrl, ['revision' => 3, 'progress_category' => 'CLOSE', 'notes' => 'Akses tersedia, instalasi dan pengujian selesai.', 'resolve_issues' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('CLOSE', $order->fresh()->progress_category);
        $this->assertSame($hash, $order->fresh()->source_snapshot_hash);
        $this->assertFalse($order->fresh()->isPsEligible());
        $this->travel(1)->minutes();
        $this->import($this->templateRow([7 => 'Approval E2E Testing Manual', 10 => 'COMPLETE']));
        $this->assertSame(4, $order->fresh()->revision);
        $this->assertTrue($order->fresh()->isPsEligible());
        $this->assertSame('RESOLVED', $order->issues()->sole()->status);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('wo_status_events', 4);
        foreach ($users as $user) {
            $this->actingAs($user)->get(route('branch.dashboard'))->assertOk()->assertViewHas('closed_by_branch', 1)->assertViewHas('waiting_bima', 0)->assertViewHas('ps_total', 1)->assertViewHas('period_complete', 0);
        }
        $area = $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk();
        $this->assertSame(1, $area->viewData('summary')['total']);
        $this->assertSame(1, $area->viewData('summary')['closed_by_branch']);
        $this->assertSame(1, $area->viewData('summary')['ps_total']);
        $this->assertSame(1, $area->viewData('summary')['missing_ps_date']);
    }

    private const HEADERS = ['Date Created', 'Date Modified', 'Workorder', 'ORDER-ID', 'SC Order No/Track ID/CSRM No', 'OSS Order ID', 'Service No.', 'Description', 'CRM Order Type', 'Owner Group', 'Status', 'Product Name', 'Address', 'witel', 'Customer Name', 'Workzone', 'Region/Site ID', 'Status Date', 'Sched Start', 'Contact Number', 'Measurement', 'Measurement Date', 'Measurement Result', 'WO Class', 'No. Kontrak(KB/KL/P8)', 'Product Type', 'Booking Date', 'Area TIF', 'District TIF', 'Regional TIF', 'Channel ID TSEL', 'Order ID TSEL', 'STATUS-ORDER', 'MAPPING-ORDER', 'UMUR (HARI)', 'KATEGORI UMUR'];

    private function templateRow(array $changes = []): array
    {
        return array_replace(array_fill(0, 36, ''), [0 => '2026-10-20 10:20:30.123', 3 => '000777', 6 => '000SID', 7 => 'ACTIVATION', 8 => 'NEW INSTALL', 10 => 'COMPLETE', 11 => 'METRO', 12 => 'Alamat fiktif', 14 => 'Pelanggan fiktif', 15 => 'TST', 28 => 'BRANCH UJI', 29 => 'REGION UJI', 32 => 'CLOSE', 34 => '1', 35 => '<3'], $changes);
    }

    private function import(array $row, bool $includeOwner = true): SyncJob
    {
        $file = XlsxFile::make(self::HEADERS, [$row], 'Work Orders');
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::latest('id_sync_jobs')->firstOrFail();
        $this->assertSame(BimaWorkOrdersTemplate::NAME, $job->snapshot['import']['template']);
        $this->get(route('imports.mapping', $job))->assertOk()->assertSee('Template BIMA Work Orders terdeteksi');
        $mapping = (new ExcelWorkOrderMapper)->suggest(self::HEADERS);
        $this->assertCount(13, $mapping);
        $this->assertSame('10', $mapping['owner_grup']);
        if (! $includeOwner) {
            unset($mapping['owner_grup']);
        }
        foreach (['status_order', 'tgl_ps', 'ps_hari_ini', 'umur_hari', 'kategori_umur'] as $field) {
            $this->assertArrayNotHasKey($field, $mapping);
        }
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => $mapping])->assertSessionHasNoErrors()->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame('preview_ready', $job->fresh()->state);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));

        return $job->fresh();
    }

    public function test_owner_template_import_skip_and_update_preserve_unmapped_source_and_branch_progress(): void
    {
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $first = $this->import($this->templateRow());
        $this->assertSame(1, $first->stats['inserted']);
        $wo = WorkOrder::sole();
        $this->assertSame('000777', $wo->no_tq_sc_id);
        $this->assertSame('000SID', $wo->sid);
        $this->assertSame('2026-10-20', $wo->tgl_order->toDateString());
        $this->assertSame('ACTIVATION', $wo->task_bima);
        $this->assertSame('NEW INSTALL', $wo->jenis_pekerjaan);
        $this->assertSame('METRO', $wo->layanan);
        $this->assertSame('Alamat fiktif', $wo->alamat);
        $this->assertSame('Pelanggan fiktif', $wo->nama_olo);
        $this->assertSame('TST', $wo->sto);
        $this->assertSame('BRANCH UJI', $wo->branch);
        $this->assertSame('REGION UJI', $wo->regional);
        $this->assertSame($this->branch->getKey(), $wo->id_branches);
        $this->assertSame('COMPLETE', $wo->status_bima);
        $this->assertSame('ACTIVATION-COMPLETE', $wo->status_order);
        $this->assertNull($wo->tgl_ps);
        $this->assertSame('10', $wo->bulan);
        $this->assertSame('2026', $wo->tahun);
        $this->assertSame(WorkOrderDerivedFields::dates('2026-10-20')['umur_hari'], $wo->umur_hari);
        $wo->update(['progress_category' => 'SURVEY', 'operational_status' => 'SURVEY', 'revision' => 9, 'status_bima' => 'OPEN', 'tgl_ps' => '2026-10-19']);
        $stamp = $wo->fresh()->updated_at;
        $this->travel(1)->minutes();
        $same = $this->import($this->templateRow([1 => '2026-10-20 12:00:00.000']));
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertEquals($stamp, $wo->fresh()->updated_at);
        $changed = $this->import($this->templateRow([14 => 'Pelanggan diperbarui']));
        $this->assertSame(1, $changed->stats['updated']);
        $wo->refresh();
        $this->assertSame('Pelanggan diperbarui', $wo->nama_olo);
        $this->assertSame('SURVEY', $wo->progress_category);
        $this->assertSame(9, $wo->revision);
        $this->assertSame('ACTIVATION-COMPLETE', $wo->status_order);
        $this->assertSame('2026-10-19', $wo->tgl_ps->toDateString());
        $this->assertDatabaseCount('bima_task_logs', 1);
        $changes = $wo->taskLogs()->sole()->field_changes;
        $this->assertSame('Pelanggan fiktif', $changes['nama_olo']['before']);
        $this->assertSame('Pelanggan diperbarui', $changes['nama_olo']['after']);
        $this->assertArrayNotHasKey('tgl_order', $changes);
        $this->assertArrayNotHasKey('tgl_ps', $changes);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertSame(0, $this->sheets->reads);
    }

    public function test_preset_requires_all_owner_headers_at_the_declared_columns_and_accepts_order_id_spelling(): void
    {
        $headers = self::HEADERS;
        $this->assertSame('4', BimaWorkOrdersTemplate::mapping($headers)['no_tq_sc_id']);
        $headers[3] = ' ORDER ID ';
        $this->assertSame('4', BimaWorkOrdersTemplate::mapping($headers)['no_tq_sc_id']);
        $headers[3] = 'ORDER';
        $this->assertSame('4', BimaWorkOrdersTemplate::mapping($headers)['no_tq_sc_id']);
        $headers[28] = 'Wrong district header';
        $this->assertNull(BimaWorkOrdersTemplate::mapping($headers));
        $this->assertArrayNotHasKey('no_tq_sc_id', (new ExcelWorkOrderMapper)->suggest(['ORDER', 'Description']));
    }

    public function test_order_header_restores_all_thirteen_choices_on_an_existing_pending_upload_without_writes(): void
    {
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $headers = self::HEADERS;
        $headers[3] = 'ORDER';
        $file = XlsxFile::make($headers, [$this->templateRow()], 'Work Orders');
        try {
            $this->actingAs($this->area)->post(route('imports.upload'), ['file' => $file, 'header_row' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertRedirect();
        } finally {
            unlink($file->getRealPath());
        }
        $job = SyncJob::sole();
        $this->assertSame(BimaWorkOrdersTemplate::NAME, $job->snapshot['import']['template']);
        // Reproduce the pending upload created before ORDER was recognized.
        $snapshot = $job->snapshot;
        $snapshot['import']['template'] = null;
        $job->update(['snapshot' => $snapshot]);
        $expected = ['tgl_order' => '1', 'no_tq_sc_id' => '4', 'sid' => '7', 'task_bima' => '8', 'jenis_pekerjaan' => '9',
            'owner_grup' => '10', 'status_bima' => '11', 'layanan' => '12', 'alamat' => '13', 'nama_olo' => '15', 'sto' => '16', 'branch' => '29', 'regional' => '30'];
        $response = $this->get(route('imports.mapping', $job))->assertOk()->assertSee('Template BIMA Work Orders terdeteksi')->assertViewHas('mapping', $expected);
        $this->assertSame(array_keys($expected), array_keys($response->viewData('primaryFields')));
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach ($expected as $field => $column) {
            $node = $xpath->query('//select[@id="map-'.$field.'"]/option[@selected]')->item(0);
            $this->assertNotNull($node, $field);
            $this->assertSame($column, $node->getAttribute('value'), $field);
        }
        $this->assertSame(13, $xpath->query('//form/div[contains(@class,"import-mapping-row")]')->length);
        foreach (ExcelWorkOrderMapper::DERIVED_FIELDS as $field) {
            $response->assertDontSee('name="mapping['.$field.']"', false);
        }
        $this->assertSame($snapshot, $job->fresh()->snapshot);
        $this->assertDatabaseCount('work_orders', 0);
        Queue::assertNothingPushed();
        // Saved intentional choices stay until the operator explicitly restores the preset.
        $snapshot['import']['mapping'] = ['no_tq_sc_id' => 5, 'sto' => 16];
        $job->update(['snapshot' => $snapshot]);
        $this->get(route('imports.mapping', $job))->assertViewHas('mapping', ['no_tq_sc_id' => 5, 'sto' => 16]);
        $this->withSession(['_old_input' => ['mapping' => ['no_tq_sc_id' => 5]]])->get(route('imports.mapping', [$job, 'use_bima_mapping' => 1]))
            ->assertViewHas('mapping', $expected)->assertViewHas('useBimaDefaults', true);
        $this->assertSame($snapshot, $job->fresh()->snapshot);
        // Submit the restored mapping to exercise the normal preview/commit contract.
        $this->post(route('imports.preview', $job), ['confirmed_mapping' => 1, 'mapping' => $expected])->assertSessionHasNoErrors()->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $this->assertSame(1, $job->fresh()->stats['new']);
        $this->post(route('sync.commit', $job), ['confirmed' => 1])->assertRedirect();
        (new ProcessWorkOrderImport($job->getKey()))->handle(app(ExcelWorkOrderImportService::class));
        $order = WorkOrder::sole();
        $this->assertSame('000777', $order->no_tq_sc_id);
        $this->assertSame('000SID', $order->sid);
        $this->assertSame('ACTIVATION-COMPLETE', $order->status_order);
        $this->assertSame('2026-10-20', $order->tgl_order->toDateString());
        $this->assertSame('BRANCH UJI', $order->branch);
        $this->assertSame('REGION UJI', $order->regional);
        $this->assertSame('success', $job->fresh()->state);
    }

    public function test_owner_group_from_column_j_updates_hash_and_history_without_overwriting_progress_or_devices(): void
    {
        config(['olo.inbound' => 'excel']);
        Queue::fake();
        $first = $this->import($this->templateRow([9 => 'OLO AREA UJI']));
        $this->assertSame(1, $first->stats['inserted']);
        $order = WorkOrder::sole();
        $this->assertSame('OLO AREA UJI', $order->owner_grup);
        $this->assertSame('OLO AREA UJI', $order->source_bima_fields['OWNER GROUP']);
        $hash = $order->source_snapshot_hash;
        $order->update(['progress_category' => 'SURVEY', 'operational_status' => 'SURVEY', 'revision' => 4,
            'last_progress_at' => now(), 'odp' => 'ODP-LOCAL', 'device_overrides' => ['odp']]);
        $stamp = $order->fresh()->updated_at;
        $this->travel(1)->minutes();
        $same = $this->import($this->templateRow([9 => 'OLO AREA UJI']));
        $this->assertSame(1, $same->stats['unchanged']);
        $this->assertSame($hash, $order->fresh()->source_snapshot_hash);
        $this->assertEquals($stamp, $order->fresh()->updated_at);
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->travel(1)->minutes();
        $changed = $this->import($this->templateRow([9 => 'OLO BRANCH UJI']));
        $this->assertSame(1, $changed->stats['updated']);
        $order->refresh();
        $this->assertSame('OLO BRANCH UJI', $order->owner_grup);
        $this->assertNotSame($hash, $order->source_snapshot_hash);
        $this->assertSame('SURVEY', $order->progress_category);
        $this->assertSame(4, $order->revision);
        $this->assertSame('ODP-LOCAL', $order->odp);
        $this->assertSame(['label' => 'OWNER GROUP', 'before' => 'OLO AREA UJI', 'after' => 'OLO BRANCH UJI'], $order->taskLogs()->sole()->field_changes['owner_grup']);
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Owner Group')->assertSee('OLO BRANCH UJI')->assertDontSee('Konfirmasi PS')->assertDontSee('PS terkonfirmasi');
        $this->travel(1)->minutes();
        $this->import($this->templateRow([9 => 'DIABAIKAN']), false);
        $this->assertSame('OLO BRANCH UJI', $order->fresh()->owner_grup);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->travel(1)->minutes();
        $this->import($this->templateRow([9 => '']));
        $this->assertNull($order->fresh()->owner_grup);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertSame(0, $this->sheets->reads);
    }

    public function test_new_preset_requires_owner_group_header_at_j(): void
    {
        $headers = self::HEADERS;
        $this->assertSame('10', BimaWorkOrdersTemplate::mapping($headers)['owner_grup']);
        $headers[9] = 'Different header';
        $this->assertNull(BimaWorkOrdersTemplate::mapping($headers));
        $this->assertArrayNotHasKey('owner_grup', (new ExcelWorkOrderMapper)->suggest($headers));
    }
}
