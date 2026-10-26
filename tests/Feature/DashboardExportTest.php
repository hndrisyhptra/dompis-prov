<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\MappingOwnergrup;
use App\Models\WorkOrder;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use ZipArchive;

class DashboardExportTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-25 18:30:00', 'UTC'));
        $this->branch->update(['region_id' => 1]);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-XLSX', 'kelompok_ownergrup' => 'DISTRICT']);
        $this->order('0000123', ['nama_olo' => '=HYPERLINK("https://example.invalid","TEST")']);
        $this->order('OWN-PS', ['tgl_order' => '2026-07-19', 'progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-26']);
        $this->order('OWN-MOD', ['jenis_pekerjaan' => 'MODIFY', 'layanan' => 'INTERNET', 'owner_grup' => null]);
        $other = Branch::create(['code' => 'XLSX-OTHER', 'name' => 'Other Export Branch', 'region_id' => 2]);
        $this->order('PRIVATE-OTHER', ['id_branches' => $other->getKey()]);
        $this->order('UNASSIGNED', ['id_branches' => null]);
    }

    private function order(string $id, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(), 'jenis_pekerjaan' => 'NEW INSTALL',
            'layanan' => 'METRO', 'tgl_order' => '2026-10-26', 'progress_category' => 'SURVEY', 'owner_grup' => 'OWNER-XLSX', 'source_bima_fields' => ['private' => 'PRIVATE-SOURCE'], 'source_snapshot_hash' => hash('sha256', $id)]);
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

            return compact('rows', 'types', 'xpath', 'parts');
        } finally {
            @unlink($path);
        }
    }

    public function test_each_area_table_downloads_real_xlsx_reconciles_with_dashboard_and_retains_filters(): void
    {
        $this->actingAs($this->area);
        $filters = ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'NEW INSTALL', 'month' => '2026-10'];
        $page = $this->get(route('area.dashboard', $filters))->assertOk();
        foreach (['progress', 'owners', 'months', 'types', 'daily'] as $table) {
            $url = route('area.dashboard.export', ['table' => $table] + $filters);
            $dom = new DOMDocument;
            @$dom->loadHTML($page->getContent());
            $links = new DOMXPath($dom);
            $link = $links->query('//a[@data-dashboard-export and contains(@href, "/exports/'.$table.'?")]')->item(0);
            $this->assertNotNull($link);
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $actualFilters);
            $this->assertEquals($filters, $actualFilters);
            $book = $this->workbook($this->get($url));
            $this->assertStringContainsString('Branch Uji', $book['rows'][3][0]);
            $this->assertStringContainsString('METRO', $book['rows'][3][0]);
            $this->assertStringContainsString('26 Oct 2026 01:30:00 WIB', $book['rows'][2][0]);
            $this->assertSame('frozen', $book['xpath']->evaluate('string(//s:pane/@state)'));
            $this->assertSame(0, $book['xpath']->query('//s:f')->length);
            $data = array_filter($book['rows'], fn ($key) => $key >= 7, ARRAY_FILTER_USE_KEY);
            if ($table === 'progress') {
                $last = end($data);
                $this->assertSame((string) $page->viewData('grand')['total'], end($last));
                $this->assertSame('2', end($last));
            } elseif ($table === 'owners') {
                $last = end($data);
                $this->assertSame('2', end($last));
                $this->assertSame('2', $last[2]);
            } elseif ($table === 'months') {
                $this->assertCount(2, $data); // The graph month does not erase July's order cohort.
                $this->assertSame(['1', '0', '0', '1'], array_slice(array_values($data)[0], 1));
                $this->assertSame(['1', '1', '1', '0'], array_slice(array_values($data)[1], 1));
            } elseif ($table === 'types') {
                $this->assertSame(['WO total', '2', '2'], $data[7]);
            } else {
                $this->assertCount(31, $data);
                $this->assertSame('1', $data[32][1]); // 26 October.
                $this->assertSame(['', 'Belum berjalan'], array_slice($data[33], 1));
                $this->assertNotSame('inlineStr', $book['types']['A7']);
            }
        }
        $this->assertDatabaseCount('work_orders', 5);
        $this->assertDatabaseCount('audit_logs', 5);
    }

    public function test_branch_downloads_follow_assignments_defaults_days_and_explicit_job_type(): void
    {
        $user = $this->user(Role::BranchHd);
        $user->branches()->attach($this->branch);
        $this->actingAs($user);
        $page = $this->get(route('branch.dashboard', ['days' => 7]))->assertOk();
        foreach (['owners', 'months', 'daily', 'attention'] as $table) {
            $page->assertSee(route('branch.dashboard.export', ['table' => $table, 'days' => 7]));
            $book = $this->workbook($this->get(route('branch.dashboard.export', ['table' => $table, 'days' => 7])));
            $text = json_encode($book['rows']);
            $this->assertStringNotContainsString('PRIVATE-OTHER', $text);
            $this->assertStringNotContainsString('Other Export Branch', $text);
            $this->assertStringNotContainsString('UNASSIGNED', $text);
            $this->assertStringNotContainsString('OWN-MOD', $text);
            if ($table === 'daily') {
                $this->assertCount(7, array_filter($book['rows'], fn ($key) => $key >= 7, ARRAY_FILTER_USE_KEY));
                $this->assertSame('1', $book['rows'][13][1]);
            }
        }
        $book = $this->workbook($this->get(route('branch.dashboard.export', ['table' => 'attention', 'jenis_pekerjaan' => 'MODIFY'])));
        $this->assertSame('OWN-MOD', $book['rows'][7][0]);
        $other = Branch::where('code', 'XLSX-OTHER')->sole();
        $this->get(route('branch.dashboard.export', ['table' => 'owners', 'branch' => $other->getKey()]))->assertForbidden();
        $this->get(route('dashboard.orders.export', ['branch' => $other->getKey()]))->assertForbidden();
    }

    public function test_modal_and_attention_export_all_matches_preserve_text_identifiers_and_never_execute_source_formulas(): void
    {
        for ($i = 0; $i < 28; $i++) {
            $this->order('MANY-'.$i);
        }
        $user = $this->user();
        $user->branches()->attach($this->branch);
        $this->actingAs($user);
        $filters = ['branch' => $this->branch->getKey(), 'queue' => 'outstanding', 'bucket' => 'survey', 'owner_group' => 'DISTRICT', 'order_month' => '2026-10', 'jenis_pekerjaan' => 'NEW INSTALL'];
        $modal = $this->get(route('work-orders.index', $filters + ['modal' => 1, 'page' => 2]))->assertOk();
        $this->assertSame(29, $modal->viewData('orders')->total());
        $this->assertCount(4, $modal->viewData('orders'));
        $modal->assertSee(route('dashboard.orders.export', $filters));
        $book = $this->workbook($this->get(route('dashboard.orders.export', $filters + ['page' => 2])));
        $this->assertStringContainsString('Daftar order dashboard', $book['rows'][2][0]);
        $data = array_filter($book['rows'], fn ($key) => $key >= 7, ARRAY_FILTER_USE_KEY);
        $this->assertCount(29, $data);
        $row = array_values(array_filter($data, fn ($row) => $row[0] === '0000123'))[0];
        $this->assertSame('=HYPERLINK("https://example.invalid","TEST")', $row[3]);
        $this->assertSame('inlineStr', $book['types']['A7']);
        $this->assertSame(0, $book['xpath']->query('//s:f')->length);
        $this->assertStringNotContainsString('PRIVATE-SOURCE', $book['parts']['xl/worksheets/sheet1.xml']->saveXML());
        $attention = $this->workbook($this->get(route('branch.dashboard.export', ['table' => 'attention'])));
        $this->assertCount(29, array_filter($attention['rows'], fn ($key) => $key >= 7, ARRAY_FILTER_USE_KEY));
        $this->assertDatabaseCount('work_orders', 33);
    }

    public function test_modal_export_uses_identical_queue_region_owner_status_and_ps_date_filter(): void
    {
        $this->actingAs($this->area);
        foreach ([['queue' => 'complete', 'ps_date' => '2026-10-26'], ['q' => 'OWN', 'status' => 'CLOSE'], ['region' => 2, 'owner_group' => 'DISTRICT'], ['unassigned' => 1], ['without_region' => 1], ['bucket' => 'survey', 'service' => 'INTERNET', 'jenis_pekerjaan' => 'MODIFY']] as $filters) {
            $filters += ['jenis_pekerjaan' => 'all'];
            $modal = $this->get(route('work-orders.index', $filters + ['modal' => 1]))->assertOk();
            $book = $this->workbook($this->get(route('dashboard.orders.export', $filters)));
            $actual = array_map(fn ($row) => $row[0], array_values(array_filter($book['rows'], fn ($key) => $key >= 7, ARRAY_FILTER_USE_KEY)));
            $this->assertSame($modal->viewData('orders')->pluck('no_tq_sc_id')->all(), $actual);
        }
    }

    public function test_download_access_validation_unknown_tables_and_empty_scope_are_safe(): void
    {
        $this->get(route('area.dashboard.export', ['table' => 'progress']))->assertRedirect(route('login'));
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $this->actingAs($this->user($role));
            $this->workbook($this->get(route('area.dashboard.export', ['table' => 'owners'])));
        }
        $user = $this->user();
        $this->actingAs($user)->get(route('area.dashboard.export', ['table' => 'progress']))->assertForbidden();
        $empty = $this->workbook($this->get(route('branch.dashboard.export', ['table' => 'attention'])));
        $this->assertSame('Tidak ada data sesuai filter.', $empty['rows'][7][0]);
        $this->get(route('branch.dashboard.export', ['table' => 'progress']))->assertNotFound();
        $this->actingAs($this->area)->get(route('area.dashboard.export', ['table' => 'unsafe']))->assertNotFound();
        foreach ([['month' => '2026-99'], ['region' => 'x']] as $invalid) {
            $this->getJson(route('area.dashboard.export', ['table' => 'daily'] + $invalid))->assertUnprocessable();
        }
        $this->getJson(route('branch.dashboard.export', ['table' => 'daily', 'days' => 300]))->assertUnprocessable();
        $this->getJson(route('dashboard.orders.export', ['bucket' => 'unsafe']))->assertUnprocessable();
        foreach ([$this->user(Role::Bot), $this->user(Role::BranchAdmin, false)] as $denied) {
            $this->actingAs($denied)->get(route('branch.dashboard.export', ['table' => 'owners']))->assertForbidden();
            $this->get(route('dashboard.orders.export'))->assertForbidden();
        }
    }
}
