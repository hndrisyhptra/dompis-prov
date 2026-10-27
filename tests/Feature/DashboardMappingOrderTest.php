<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\MappingOrder;
use App\Models\Region;
use App\Models\WorkOrder;
use App\Services\MappingOrderAnalyticsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;

class DashboardMappingOrderTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (MappingOrderAnalyticsService::CATEGORIES as $category) {
            MappingOrder::create(['status_order' => 'Map '.$category.'-STARTWORK', 'mapping_order' => $category]);
        }
        $this->branch->update(['region_id' => 1]);
    }

    private function order(string $id, ?string $mapping = 'OPEN', array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
            'task_bima' => $mapping ? 'Map '.$mapping : 'Unknown task', 'status_bima' => 'STARTWORK', 'progress_category' => 'SURVEY',
            'sto' => '001', 'regional' => 'SOURCE TEXT IS NOT REGION', 'jenis_pekerjaan' => 'NEW INSTALL', 'layanan' => 'METRO',
            'tgl_order' => '2026-07-01', 'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
    }

    private function mappingLinks(string $html)
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return (new DOMXPath($dom))->query('//table[contains(@class,"mapping-order-table")]//a[@data-dashboard-orders]');
    }

    public function test_region_mapping_is_independent_of_ps_kpi_and_keeps_unknown_unassigned_and_regionless_orders(): void
    {
        $other = Branch::create(['code' => 'MOR-REGION', 'name' => 'Region Dua', 'region_id' => 2]);
        $regionless = Branch::create(['code' => 'MOR-NONE', 'name' => 'No Region']);
        $this->order('MAPPING-PS', 'PS'); // Mapping PS, but Branch has not closed and BIMA is not eligible.
        MappingOrder::create(['status_order' => 'Approval E2E Testing Manual-COMPLETE', 'mapping_order' => 'OPEN']);
        $this->order('ACTUAL-PS', extra: ['task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'progress_category' => 'CLOSE']);
        $this->order('CANCEL', 'CANCLWORK', ['id_branches' => $other->getKey(), 'progress_category' => 'CANCEL']);
        $this->order('STUCK', 'STUCK', ['id_branches' => $other->getKey()]);
        $this->order('UNASSIGNED', null, ['id_branches' => null]);
        $invalid = $this->order('UNKNOWN-CATEGORY', extra: ['id_branches' => $regionless->getKey()]);
        DB::table('work_orders')->where('id_work_orders', $invalid->getKey())->update(['mapping_order' => 'ps']);
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $page = $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertSee('Mapping Order Region')->assertSee('Tanpa Region')->assertSee('Belum dipetakan');
        $report = $page->viewData('mappingOrders');
        $this->assertSame(6, $report['total']);
        $this->assertSame(['PS' => 1, 'CANCLWORK' => 1, 'OPEN' => 1, 'STUCK' => 1, 'unmapped' => 2], $report['totals']);
        $this->assertSame(1, $page->viewData('summary')['ps_total']);
        $this->assertSame(2, $report['rows'][1]['total']);
        $this->assertSame(Region::findOrFail(1)->name, $report['rows'][1]['name']);
        $this->assertSame(2, $report['rows']['unassigned']['total']);
        $this->assertSame(6, array_sum(array_column($report['rows'], 'total')));
        foreach ($report['rows'] as $row) {
            $this->assertSame($row['total'], array_sum($row['counts']));
        }
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('wo_status_events', 0);
    }

    public function test_area_table_is_only_in_progress_tab_and_follows_region_branch_service_and_job_filters(): void
    {
        $other = Branch::create(['code' => 'MOR-FILTER', 'name' => 'Other Region', 'region_id' => 2]);
        $this->order('MATCH', 'STUCK');
        $this->order('MODIFY', 'PS', ['jenis_pekerjaan' => 'MODIFY']);
        $this->order('OTHER-SERVICE', 'OPEN', ['layanan' => 'INTERNET']);
        $this->order('OTHER-REGION', 'OPEN', ['id_branches' => $other->getKey()]);
        $this->actingAs($this->area);
        $filters = ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'NEW INSTALL', 'month' => '2026-01'];
        $page = $this->get(route('area.dashboard', $filters))->assertOk();
        $this->assertSame(1, $page->viewData('mappingOrders')['total']);
        $this->assertSame(1, $page->viewData('mappingOrders')['totals']['STUCK']);
        // Month selects the PS chart, not a hidden date limit on this cumulative table.
        $this->get(route('area.dashboard', $filters + ['tab' => 'owner-group']))->assertOk()->assertViewHas('mappingOrders', null)->assertDontSee('Mapping Order Region');
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $this->actingAs($this->user($role))->get(route('area.dashboard'))->assertOk()->assertSee('Mapping Order Region');
        }
        $this->actingAs($this->user(Role::BranchAdmin))->get(route('area.dashboard'))->assertForbidden();
    }

    public function test_branch_subtotals_and_sto_children_keep_assignment_type_filter_and_missing_sto(): void
    {
        $other = Branch::create(['code' => 'MOR-FOREIGN', 'name' => 'Foreign Branch', 'region_id' => 2]);
        $this->order('FIRST', 'OPEN');
        $this->order('TRIMMED', 'STUCK', ['sto' => ' 001 ']);
        $this->order('CASE-STO', 'PS', ['sto' => 'abc']);
        $this->order('CASE-STO-UPPER', 'OPEN', ['sto' => 'ABC']);
        $this->order('NULL-STO', null, ['sto' => null]);
        $this->order('BLANK-STO', 'CANCLWORK', ['sto' => '  ']);
        $this->order('ZERO-STO', 'OPEN', ['sto' => '0']);
        $this->order('MODIFY', 'PS', ['jenis_pekerjaan' => 'MODIFY']);
        $this->order('FOREIGN', 'PS', ['id_branches' => $other->getKey()]);
        foreach ([Role::BranchAdmin, Role::BranchHd, Role::TeamLeader] as $role) {
            $actor = $this->user($role);
            $actor->branches()->attach($this->branch);
            $page = $this->actingAs($actor)->get(route('branch.dashboard'))->assertOk()->assertSee('Mapping Order Branch / STO')->assertSee('STO belum terisi');
            $report = $page->viewData('mappingOrders');
            $this->assertSame(7, $report['total']);
            $this->assertCount(1, $report['rows']);
            $row = $report['rows'][$this->branch->getKey()];
            $this->assertSame(7, array_sum(array_column($row['children'], 'total')));
            $this->assertSame(2, $row['children']['sto:001']['total']);
            $this->assertSame(2, $row['children']['sto:']['total']);
            $this->assertSame(1, $row['children']['sto:0']['total']);
            $this->assertCount(5, $row['children']);
            $this->get(route('branch.dashboard', ['jenis_pekerjaan' => 'all', 'days' => 7]))->assertViewHas('mappingOrders', fn ($r) => $r['total'] === 8);
            $this->get(route('branch.dashboard', ['branch' => $other->getKey()]))->assertForbidden();
            $actor->branches()->detach();
            $this->get(route('branch.dashboard'))->assertViewHas('mappingOrders', fn ($r) => $r['total'] === 0)->assertSee('Belum ada order dalam filter ini');
        }
    }

    public function test_every_mapping_number_opens_a_scoped_modal_with_the_same_count_and_detail_links(): void
    {
        $other = Branch::create(['code' => 'MOR-SECOND', 'name' => 'Second Branch', 'region_id' => 2]);
        $noRegion = Branch::create(['code' => 'MOR-UNREG', 'name' => 'No Region']);
        $this->order('OPEN-A');
        $this->order('STUCK-A', 'STUCK');
        $this->order('OPEN-B', 'OPEN', ['id_branches' => $other->getKey()]);
        $this->order('MISSING-STO', null, ['sto' => null]);
        $this->order('NO-REGION', 'PS', ['id_branches' => $noRegion->getKey()]);
        $this->order('UNASSIGNED', 'CANCLWORK', ['id_branches' => null]);
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach([$this->branch->getKey(), $other->getKey()]);
        foreach ([[$this->area, 'area.dashboard'], [$actor, 'branch.dashboard']] as [$user, $route]) {
            $page = $this->actingAs($user)->get(route($route))->assertOk();
            foreach ($this->mappingLinks($page->getContent()) as $link) {
                $url = $link->getAttribute('href');
                $list = $this->get($url)->assertOk();
                $this->assertSame((int) str_replace(',', '', $link->textContent), $list->viewData('total'), $url);
                $modal = $this->get($url.(str_contains($url, '?') ? '&' : '?').'modal=1')->assertOk()->assertHeader('X-Dashboard-Fragment', 'orders');
                $this->assertSame($list->viewData('total'), $modal->viewData('orders')->total());
                foreach ($modal->viewData('orders') as $order) {
                    $modal->assertSee(route('work-orders.show', $order));
                    if ($user->is($actor)) {
                        $this->assertContains($order->id_branches, [$this->branch->getKey(), $other->getKey()]);
                    }
                }
            }
        }
    }

    public function test_new_detail_filters_are_validated_and_preserved_by_order_navigation(): void
    {
        $this->order('STUCK-ONLY', 'STUCK', ['sto' => 'ABC']);
        $this->order('OPEN-OTHER', 'OPEN');
        $this->actingAs($this->area);
        $filters = ['jenis_pekerjaan' => 'all', 'mapping_order' => 'STUCK', 'sto' => 'ABC'];
        $page = $this->get(route('work-orders.index', $filters))->assertOk()->assertViewHas('total', 1)->assertSee('Mapping Order: STUCK')->assertSee('STO: ABC')
            ->assertSee('name="mapping_order" value="STUCK"', false)->assertSee('name="sto" value="ABC"', false);
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//nav[@aria-label="Antrean order"]/a') as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $params);
            $this->assertSame('STUCK', $params['mapping_order']);
            $this->assertSame('ABC', $params['sto']);
        }
        foreach ([['mapping_order' => 'unsafe'], ['sto_missing' => 'yes'], ['sto_missing' => 1, 'sto' => 'ABC'], ['sto' => str_repeat('x', 256)]] as $invalid) {
            $this->getJson(route('work-orders.index', $invalid))->assertUnprocessable();
        }
        $this->get(route('work-orders.index', ['mapping_order' => 'STUCK', 'sto' => 'abc']))->assertViewHas('total', 0);
        $this->get(route('work-orders.index', ['sto_missing' => 1]))->assertViewHas('total', 0);
    }
}
