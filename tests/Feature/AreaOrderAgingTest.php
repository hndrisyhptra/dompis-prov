<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\MappingOrder;
use App\Models\WorkOrder;
use App\Services\WorkOrderDerivedFields;
use App\Support\OrderAging;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;

class AreaOrderAgingTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-26 17:05:00', 'UTC'));
        $this->branch->update(['region_id' => 1]);
        foreach (['OPEN', 'STUCK', 'PS', 'CANCLWORK'] as $category) {
            MappingOrder::create(['status_order' => 'Aging '.$category.'-STARTWORK', 'mapping_order' => $category]);
        }
    }

    private function order(string $id, ?int $age, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
            'task_bima' => 'Aging OPEN', 'status_bima' => 'STARTWORK', 'progress_category' => 'SURVEY', 'jenis_pekerjaan' => 'NEW INSTALL', 'layanan' => 'METRO',
            'tgl_order' => $age === null ? null : CarbonImmutable::now('Asia/Jakarta')->subDays($age)->toDateString(),
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_calendar_boundaries_match_existing_formula_and_ignore_stale_stored_ages_without_mutation(): void
    {
        foreach ([0, 7, 8, 14, 15, 28, 29, -2, null] as $i => $age) {
            $order = $this->order('BOUNDARY-'.$i, $age, $age === 29 ? ['id_branches' => null] : []);
            $expectedLabel = WorkOrderDerivedFields::dates($order->tgl_order?->toDateString())['kategori_umur'];
            $this->assertSame($age === null ? null : OrderAging::LABELS[match (true) {
                $age > 28 => 'over_28', $age > 14 => '14_28', $age > 7 => '7_14', default => '0_7'
            }], $expectedLabel);
        }
        $this->order('EXCLUDED-STUCK', 40, ['task_bima' => 'Aging STUCK']);
        $this->order('EXCLUDED-PS', 50, ['task_bima' => 'Aging PS']);
        DB::table('work_orders')->update(['umur_hari' => 999, 'kategori_umur' => '> 28 HARI']);
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $page = $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertSee('Mapping Aging Order')->assertSee('OPEN &amp; STUCK per Jenis Pekerjaan', false)->assertDontSee('per Jenis Transaksi');
        $report = $page->viewData('mappingOrders')['aging'];
        $this->assertSame('2026-10-27', $report['date']);
        $this->assertSame(9, $report['total']);
        $this->assertSame(['0_7' => 3, '7_14' => 2, '14_28' => 2, 'over_28' => 1, 'unknown' => 1], $report['counts']);
        $this->assertSame(9, array_sum($report['counts']));
        $this->assertSame(9, array_sum(array_column($report['regions'], 'total')));
        foreach ($report['regions'] as $region) {
            $this->assertSame($region['total'], array_sum($region['counts']));
            $this->assertSame($region['total'], array_sum(array_column($region['branches'], 'total')));
        }
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->get(route('area.dashboard', ['tab' => 'owner-group']))->assertDontSee('Mapping Aging Order');
        $this->get(route('area.dashboard', ['tab' => 'ach-ps']))->assertDontSee('Mapping Aging Order');
    }

    public function test_all_aging_numbers_reconcile_with_scoped_lists_and_modals_under_active_filters(): void
    {
        $other = Branch::create(['code' => 'AGING-OTHER', 'name' => 'Other Aging Branch', 'region_id' => 2]);
        $regionless = Branch::create(['code' => 'AGING-NOREG', 'name' => 'No Region']);
        $this->order('YOUNG', 7);
        $this->order('MID-MODIFY', 8, ['jenis_pekerjaan' => 'MODIFY']);
        $this->order('NULL-MODIFY', null, ['jenis_pekerjaan' => 'MODIFY']);
        $this->order('OTHER-BRANCH', 15, ['id_branches' => $other->getKey()]);
        $this->order('REGIONLESS', 29, ['id_branches' => $regionless->getKey()]);
        $this->order('UNASSIGNED', 14, ['id_branches' => null]);
        $this->order('STUCK', 40, ['task_bima' => 'Aging STUCK']);
        $this->actingAs($this->area);
        foreach ([[], ['region' => 1, 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY']] as $filters) {
            $page = $this->get(route('area.dashboard', $filters))->assertOk();
            $this->assertSame($filters ? 2 : 6, $page->viewData('mappingOrders')['aging']['total']);
            $xpath = $this->xpath($page->getContent());
            foreach ($xpath->query('//table[contains(@class,"mapping-aging-table")]//a[@data-dashboard-orders]') as $link) {
                $url = $link->getAttribute('href');
                $expected = (int) str_replace(',', '', $link->textContent);
                $list = $this->get($url)->assertOk();
                $this->assertSame($expected, $list->viewData('total'), $url);
                $modal = $this->get($url.'&modal=1')->assertOk()->assertHeader('X-Dashboard-Fragment', 'orders');
                $this->assertSame($expected, $modal->viewData('orders')->total());
                foreach ($modal->viewData('orders') as $order) {
                    $modal->assertSee(route('work-orders.show', $order));
                    $this->assertSame('OPEN', $order->mapping_order);
                }
            }
        }
        $snapshot = ['jenis_pekerjaan' => 'all', 'mapping_order' => 'OPEN', 'aging_bucket' => '0_7', 'aging_on' => '2026-10-27'];
        $this->travel(1)->days();
        $this->get(route('work-orders.index', $snapshot))->assertViewHas('total', 1)->assertSee('YOUNG');
        $this->get(route('work-orders.index', array_replace($snapshot, ['aging_on' => '2026-10-28'])))->assertViewHas('total', 0);
    }

    public function test_age_filter_requires_valid_snapshot_preserves_navigation_and_does_not_escape_branch_scope(): void
    {
        $this->order('OWN', 8);
        $other = Branch::create(['code' => 'AGING-FOREIGN', 'name' => 'Foreign Aging Branch', 'region_id' => 2]);
        $this->order('FOREIGN', 8, ['id_branches' => $other->getKey()]);
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor);
        $filters = ['mapping_order' => 'OPEN', 'aging_bucket' => '7_14', 'aging_on' => '2026-10-27'];
        $page = $this->get(route('work-orders.index', $filters))->assertOk()->assertViewHas('total', 1)->assertSee('Umur: 7-14 HARI')->assertDontSee('>FOREIGN<', false)
            ->assertSee('name="aging_bucket" value="7_14"', false)->assertSee('name="aging_on" value="2026-10-27"', false);
        foreach ($this->xpath($page->getContent())->query('//nav[@aria-label="Antrean order"]/a') as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $params);
            $this->assertSame('7_14', $params['aging_bucket']);
            $this->assertSame('2026-10-27', $params['aging_on']);
        }
        $this->get(route('work-orders.index', $filters + ['branch' => $other->getKey()]))->assertForbidden();
        $this->get(route('area.dashboard'))->assertForbidden();
        foreach ([['aging_bucket' => '7_14'], ['aging_on' => '2026-10-27'], ['aging_bucket' => 'unsafe', 'aging_on' => '2026-10-27'], ['aging_bucket' => '0_7', 'aging_on' => '2026-02-30']] as $invalid) {
            $this->getJson(route('work-orders.index', $invalid))->assertUnprocessable();
        }
    }
}
