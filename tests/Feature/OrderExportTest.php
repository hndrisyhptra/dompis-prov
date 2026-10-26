<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MappingOwnergrup;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PriorityOrderService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

class OrderExportTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-25 18:30:00', 'UTC'));
        $this->branch->update(['region_id' => 1]);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-XLSX', 'kelompok_ownergrup' => 'DISTRICT']);
        $this->order('0000123', ['nama_olo' => '=HYPERLINK("https://example.invalid","TEST")', 'sid' => '000SID']);
        $this->order('OWN-PS', ['tgl_order' => '2026-07-19', 'progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-26']);
        $this->order('OWN-MOD', ['jenis_pekerjaan' => 'MODIFY', 'layanan' => 'INTERNET', 'owner_grup' => null, 'last_progress_at' => '2026-10-25 18:00:00']);
        $other = Branch::create(['code' => 'XLSX-OTHER', 'name' => 'Other Export Branch', 'region_id' => 2]);
        $this->order('PRIVATE-OTHER', ['id_branches' => $other->getKey()]);
        $this->order('UNASSIGNED', ['id_branches' => null]);
    }

    private function order(string $id, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(), 'jenis_pekerjaan' => 'NEW INSTALL',
            'layanan' => 'METRO', 'tgl_order' => '2026-10-26', 'progress_category' => 'SURVEY', 'owner_grup' => 'OWNER-XLSX', 'source_bima_fields' => ['private' => 'PRIVATE-SOURCE'], 'source_snapshot_hash' => hash('sha256', $id)]);
    }

    private function branchUser(Role $role = Role::BranchHd, ?Branch $branch = null): User
    {
        $user = $this->user($role);
        $user->branches()->attach($branch ?? $this->branch);

        return $user;
    }

    private function select(User $actor, string $id): void
    {
        app(PriorityOrderService::class)->set($actor, WorkOrder::where('no_tq_sc_id', $id)->sole(), true);
    }

    private function workbook($response): array
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->assertHeader('Cache-Control', 'no-store, private');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $part = new DOMDocument;
                $this->assertTrue($part->loadXML($zip->getFromIndex($i), LIBXML_NONET), $name);
                $parts[$name] = $part;
            }
            $zip->close();
            $xpath = new DOMXPath($parts['xl/worksheets/sheet1.xml']);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = [];
            $types = [];
            foreach ($xpath->query('//s:sheetData/s:row') as $row) {
                foreach ($xpath->query('s:c', $row) as $cell) {
                    $address = $cell->getAttribute('r');
                    $types[$address] = $cell->getAttribute('t');
                    $rows[(int) $row->getAttribute('r')][] = $types[$address] === 'inlineStr' ? $xpath->evaluate('string(s:is/s:t)', $cell) : $xpath->evaluate('string(s:v)', $cell);
                }
            }
            $data = array_values(array_filter($rows, fn ($key) => $key >= 7, ARRAY_FILTER_USE_KEY));
            $headers = $rows[6];
            $this->assertSame(Schema::getColumnListing('work_orders'), $headers);
            $ids = $data[0][0] === 'Tidak ada data sesuai filter.' ? [] : array_column($data, array_search('no_tq_sc_id', $headers, true));
            $records = $ids ? array_map(fn ($row) => array_combine($headers, $row), $data) : [];

            return compact('rows', 'types', 'xpath', 'parts', 'data', 'ids', 'headers', 'records');
        } finally {
            @unlink($path);
        }
    }

    public function test_download_buttons_are_only_on_work_order_and_priority_tables_and_old_dashboard_routes_are_removed(): void
    {
        $this->actingAs($this->area);
        foreach (['area.dashboard', 'branch.dashboard'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('data-excel-download', false)->assertDontSee('Download Excel');
        }
        $this->get(route('work-orders.index', ['modal' => 1]))->assertOk()->assertDontSee('data-excel-download', false);
        $filters = ['queue' => 'outstanding', 'jenis_pekerjaan' => 'all', 'branch' => $this->branch->getKey()];
        $page = $this->get(route('work-orders.index', $filters))->assertOk()->assertSee('data-excel-download', false);
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $link = (new DOMXPath($dom))->query('//a[@data-excel-download]')->item(0);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $actualFilters);
        $this->assertEquals($filters, $actualFilters);
        $filters = ['state' => 'outstanding', 'sort' => 'age', 'q' => 'OWN'];
        $page = $this->get(route('priorities.index', $filters))->assertOk()->assertSee('data-excel-download', false);
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $link = (new DOMXPath($dom))->query('//a[@data-excel-download]')->item(0);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $actualFilters);
        $this->assertEquals($filters, $actualFilters);
        foreach (['/dashboard/exports/progress', '/branch/dashboard/exports/owners', '/dashboard/orders/export'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_work_order_export_matches_every_filter_and_list_sort_with_typed_text_dates_and_no_formula_execution(): void
    {
        $this->actingAs($this->area);
        foreach ([['queue' => 'complete', 'ps_date' => '2026-10-26'], ['q' => 'OWN', 'status' => 'CLOSE'], ['region' => 2, 'owner_group' => 'DISTRICT'], ['unassigned' => 1], ['without_region' => 1], ['bucket' => 'survey', 'service' => 'INTERNET', 'jenis_pekerjaan' => 'MODIFY'], ['branch' => $this->branch->getKey(), 'order_month' => '2026-10']] as $filters) {
            $filters += ['jenis_pekerjaan' => 'all'];
            $page = $this->get(route('work-orders.index', $filters))->assertOk();
            $book = $this->workbook($this->get(route('work-orders.export', $filters)));
            $this->assertSame($page->viewData('orders')->pluck('no_tq_sc_id')->all(), $book['ids']);
            $this->assertStringContainsString('Work Order', $book['rows'][2][0]);
            $this->assertSame(0, $book['xpath']->query('//s:f')->length);
        }
        $book = $this->workbook($this->get(route('work-orders.export', ['q' => '0000123'])));
        $this->assertSame('0000123', $book['records'][0]['no_tq_sc_id']);
        $this->assertSame('000SID', $book['records'][0]['sid']);
        $this->assertSame('=HYPERLINK("https://example.invalid","TEST")', $book['records'][0]['nama_olo']);
        $column = array_search('no_tq_sc_id', $book['headers'], true) + 1;
        $this->assertSame('inlineStr', $book['xpath']->evaluate('string(//s:row[@r="7"]/s:c['.$column.']/@t)'));
        $this->assertSame('46321', $book['records'][0]['tgl_order']);
        $this->assertSame(WorkOrder::where('no_tq_sc_id', '0000123')->sole()->getRawOriginal('source_bima_fields'), $book['records'][0]['source_bima_fields']);
    }

    public function test_work_order_export_includes_all_pages_and_branch_scope_without_mutating_orders(): void
    {
        for ($i = 0; $i < 28; $i++) {
            $this->order('MANY-'.$i);
        }
        $actor = $this->branchUser();
        $this->actingAs($actor);
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $filters = ['queue' => 'outstanding', 'bucket' => 'survey', 'owner_group' => 'DISTRICT', 'order_month' => '2026-10'];
        $page = $this->get(route('work-orders.index', $filters))->assertOk();
        $second = $this->get(route('work-orders.index', $filters + ['page' => 2]))->assertOk();
        $this->assertSame(29, $page->viewData('orders')->total());
        $book = $this->workbook($this->get(route('work-orders.export', $filters + ['page' => 2])));
        $expected = [...$page->viewData('orders')->pluck('no_tq_sc_id')->all(), ...$second->viewData('orders')->pluck('no_tq_sc_id')->all()];
        $this->assertSame($expected, $book['ids']);
        $this->assertNotContains('PRIVATE-OTHER', $book['ids']);
        $this->assertNotContains('OWN-MOD', $book['ids']); // Work Order defaults to NEW INSTALL.
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
        $this->get(route('work-orders.export', ['branch' => Branch::where('code', 'XLSX-OTHER')->sole()->getKey()]))->assertForbidden();
    }

    public function test_priority_exports_all_work_order_columns_while_preserving_area_branch_privacy_and_deduplication(): void
    {
        $branch = $this->branchUser();
        $branch->forceFill(['name' => 'PRIVATE-BRANCH-SELECTOR'])->save();
        $this->select($this->area, '0000123');
        $this->select($this->area, 'PRIVATE-OTHER');
        $this->select($branch, '0000123');
        $this->select($branch, 'OWN-MOD');
        foreach ([Role::SuperAdmin, Role::AreaAdmin, Role::AreaHd] as $role) {
            $this->actingAs($this->user($role));
            $book = $this->workbook($this->get(route('priorities.export')));
            $this->assertEqualsCanonicalizing(['0000123', 'PRIVATE-OTHER'], $book['ids']);
            $this->assertStringNotContainsString('PRIVATE-BRANCH-SELECTOR', json_encode($book['data']));
            $this->assertStringNotContainsString('Prioritas Branch', json_encode($book['data']));
        }
        $this->actingAs($branch);
        $book = $this->workbook($this->get(route('priorities.export')));
        $this->assertEqualsCanonicalizing(['0000123', 'OWN-MOD'], $book['ids']);
        $this->assertNotContains('priority_selected_at', $book['headers']);
        $this->assertNotContains('selection_token', $book['headers']);
        $this->assertNotContains('OWN-PS', $book['ids']);
        $this->assertStringContainsString('Semua jenis', $book['rows'][3][0]);
    }

    public function test_priority_filters_and_sort_are_identical_to_monitoring_page(): void
    {
        $actor = $this->branchUser();
        foreach (['0000123', 'OWN-PS', 'OWN-MOD'] as $id) {
            $this->select($this->area, $id);
        }
        $this->select($actor, 'OWN-MOD');
        WorkOrder::where('no_tq_sc_id', 'OWN-MOD')->sole()->issues()->create(['id_users' => $actor->getKey(), 'category' => 'OTHER', 'description' => 'Kendala uji', 'pic' => 'PIC']);
        $this->actingAs($actor);
        foreach ([['source' => 'area'], ['source' => 'branch'], ['state' => 'issues'], ['state' => 'closed'], ['state' => 'waiting_bima'], ['q' => '000SID'], ['sort' => 'age'], ['sort' => 'updated'], ['sort' => 'selected', 'branch' => $this->branch->getKey()]] as $filters) {
            $page = $this->get(route('priorities.index', $filters))->assertOk();
            $book = $this->workbook($this->get(route('priorities.export', $filters)));
            $this->assertSame($page->viewData('orders')->pluck('no_tq_sc_id')->all(), $book['ids']);
        }
        $other = Branch::where('code', 'XLSX-OTHER')->sole();
        $this->get(route('priorities.export', ['branch' => $other->getKey()]))->assertForbidden();
    }

    public function test_priority_export_includes_all_pages_and_transfer_never_moves_local_branch_bookmarks(): void
    {
        $actor = $this->branchUser();
        $this->select($actor, '0000123');
        $this->select($this->area, '0000123');
        $other = Branch::where('code', 'XLSX-OTHER')->sole();
        WorkOrder::where('no_tq_sc_id', '0000123')->sole()->update(['id_branches' => $other->getKey()]);
        $this->actingAs($actor);
        $this->assertSame([], $this->workbook($this->get(route('priorities.export')))['ids']);
        $this->actingAs($this->branchUser(branch: $other));
        $book = $this->workbook($this->get(route('priorities.export')));
        $this->assertSame(['0000123'], $book['ids']);
        $this->assertStringNotContainsString('Prioritas Branch', json_encode($book['data']));
        for ($i = 0; $i < 28; $i++) {
            $this->order('PAGE-'.$i);
            $this->select($this->area, 'PAGE-'.$i);
        }
        $this->actingAs($this->area);
        $filters = ['q' => 'PAGE-', 'sort' => 'age'];
        $this->get(route('priorities.index', $filters))->assertViewHas('orders', fn ($orders) => $orders->count() === 25);
        $book = $this->workbook($this->get(route('priorities.export', $filters + ['page' => 2])));
        $this->assertCount(28, $book['ids']);
    }

    public function test_export_validation_permissions_empty_scope_and_audit_privacy(): void
    {
        $this->get(route('work-orders.export'))->assertRedirect(route('login'));
        $this->get(route('priorities.export'))->assertRedirect(route('login'));
        foreach ([Role::AreaCoordinator, Role::MaterialAdmin, Role::Bot] as $role) {
            $this->actingAs($this->branchUser($role))->get(route('priorities.export'))->assertForbidden();
        }
        $this->get(route('work-orders.export'))->assertForbidden(); // Bot.
        $inactive = $this->user(Role::AreaHd, false);
        $this->actingAs($inactive)->get(route('work-orders.export'))->assertForbidden();
        $this->get(route('priorities.export'))->assertForbidden();
        $this->actingAs($this->area)->getJson(route('priorities.export', ['source' => 'branch']))->assertUnprocessable();
        $this->getJson(route('priorities.export', ['sort' => 'unsafe']))->assertUnprocessable();
        $this->getJson(route('work-orders.export', ['bucket' => 'unsafe']))->assertUnprocessable();
        $this->getJson(route('work-orders.export', ['order_month' => '2026-99']))->assertUnprocessable();
        $book = $this->workbook($this->get(route('work-orders.export', ['q' => 'PRIVATE-ABSENT'])));
        $this->assertSame([], $book['ids']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'orders.exported']);
        $this->assertStringNotContainsString('PRIVATE-ABSENT', AuditLog::where('action', 'orders.exported')->sole()->getRawOriginal('metadata'));
    }

    public function test_all_physical_column_values_use_raw_database_data_in_both_exports_without_accessors_or_virtual_fields(): void
    {
        $order = WorkOrder::where('no_tq_sc_id', '0000123')->sole();
        DB::table('work_orders')->where('id_work_orders', $order->getKey())->update(['alamat' => 'Alamat lengkap uji', 'branch' => 'BRANCH SUMBER', 'umur_hari' => '999', 'kategori_umur' => 'Kategori tersimpan',
            'source_bima_fields' => json_encode(['TASK BIMA' => 'Sumber uji', 'PRIVATE-OWN-ORDER' => 'JSON disertakan sesuai instruksi pemilik']), 'device_overrides' => json_encode(['sn' => true]), 'progress_category' => null,
            'odp' => 'ODP-UJI', 'sn' => '000SN', 'olt' => 'OLT-UJI', 'port_olt' => '001', 'metro' => 'METRO-UJI', 'port_metro' => '002', 'owner_grup' => 'OWNER-XLSX', 'revision' => 7,
            'last_progress_at' => '2026-10-25 18:02:03']);
        $this->select($this->area, '0000123');
        $order->refresh();
        $before = $order->getRawOriginal();
        $this->assertNotSame(999, $order->umur_hari);
        $this->actingAs($this->area);
        foreach (['work-orders.export', 'priorities.export'] as $route) {
            $book = $this->workbook($this->get(route($route, ['q' => '0000123'])));
            $row = $book['records'][0];
            $this->assertCount(count($before), $book['headers']);
            foreach ($before as $name => $value) {
                if (in_array($name, ['tgl_order', 'tgl_ps']) || str_ends_with($name, '_at')) {
                    continue; // Native Excel date/datetime serials are checked separately.
                }
                $this->assertSame((string) ($value ?? ''), $row[$name], $name);
            }
            $this->assertSame('999', $row['umur_hari']);
            $this->assertSame('BRANCH SUMBER', $row['branch']);
            $this->assertSame('', $row['progress_category']);
            $expected = Carbon::parse('2026-10-25 18:02:03', 'UTC')->getTimestamp() / 86400 + 25569;
            $this->assertEqualsWithDelta($expected, (float) $row['last_progress_at'], .00000001);
        }
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_excel_cell_limit_is_reported_instead_of_silently_truncating_source_json(): void
    {
        DB::table('work_orders')->where('no_tq_sc_id', '0000123')->update(['source_bima_fields' => json_encode(['long' => str_repeat('a', 33000)])]);
        $this->actingAs($this->area)->getJson(route('work-orders.export', ['q' => '0000123']))->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->assertSame(33000, strlen(WorkOrder::where('no_tq_sc_id', '0000123')->sole()->source_bima_fields['long']));
        $this->assertSame(0, AuditLog::where('action', 'orders.exported')->count());
    }
}
