<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\MappingOrder;
use App\Models\WorkOrder;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

class AreaOpenTransactionsTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->branch->update(['region_id' => 1]);
        foreach (['OPEN', 'STUCK', 'PS', 'CANCLWORK'] as $category) {
            MappingOrder::create(['status_order' => 'Transaction '.$category.'-STARTWORK', 'mapping_order' => $category]);
        }
    }

    private function order(string $id, string $mapping = 'OPEN', array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
            'task_bima' => 'Transaction '.$mapping, 'status_bima' => 'STARTWORK', 'progress_category' => 'SURVEY', 'jenis_pekerjaan' => 'NEW INSTALL',
            'layanan' => 'METRO', 'tgl_order' => '2026-07-01', 'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_transaction_rows_reconcile_sections_regions_branches_and_all_types_without_mutation(): void
    {
        $other = Branch::create(['code' => 'OAT-OTHER', 'name' => 'Other Transaction Branch', 'region_id' => 2]);
        $this->order('OPEN-NEW');
        $this->order('OPEN-MOD', extra: ['jenis_pekerjaan' => ' modify ']);
        $this->order('OPEN-DIS', extra: ['jenis_pekerjaan' => 'DISCONNECT', 'id_branches' => $other->getKey()]);
        $this->order('OPEN-SUSP', extra: ['jenis_pekerjaan' => 'SUSPEND', 'id_branches' => null]);
        $this->order('STUCK-RESUME', 'STUCK', ['jenis_pekerjaan' => 'RESUME']);
        $this->order('STUCK-NONE', 'STUCK', ['jenis_pekerjaan' => null]);
        $this->order('STUCK-BLANK', 'STUCK', ['jenis_pekerjaan' => ' ']);
        $this->order('EXCLUDED-PS', 'PS');
        $this->order('EXCLUDED-CANCEL', 'CANCLWORK');
        $this->order('EXCLUDED-UNKNOWN', 'NOT-MAPPED');
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $page = $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertSee('OPEN &amp; STUCK per Jenis Pekerjaan', false)->assertDontSee('Perolehan PS harian');
        $report = $page->viewData('mappingOrders')['transactions'];
        $this->assertSame(7, $report['total']);
        $this->assertSame(4, $report['sections']['OPEN']['total']);
        $this->assertSame(3, $report['sections']['STUCK']['total']);
        $this->assertSame(['NEW INSTALL', 'MODIFY', 'DISCONNECT', 'RESUME', 'SUSPEND', 'TANPA JENIS'], $report['columns']);
        $this->assertSame(2, $report['totals']['TANPA JENIS']);
        foreach ($report['sections'] as $section) {
            $this->assertSame($section['total'], array_sum($section['counts']));
            $this->assertSame($section['total'], array_sum(array_column($section['regions'], 'total')));
            foreach ($section['regions'] as $region) {
                $this->assertSame($region['total'], array_sum($region['counts']));
                $this->assertSame($region['total'], array_sum(array_column($region['branches'], 'total')));
            }
        }
        $this->assertSame(7, array_sum($report['totals']));
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_all_transaction_numbers_open_exact_lists_and_modals_with_filters_and_combined_grand_total(): void
    {
        $other = Branch::create(['code' => 'OAT-LINK', 'name' => 'Second Branch', 'region_id' => 2]);
        $noRegion = Branch::create(['code' => 'OAT-NONE', 'name' => 'No Region']);
        $this->order('OPEN-NEW');
        $this->order('OPEN-MOD', extra: ['jenis_pekerjaan' => 'MODIFY']);
        $this->order('STUCK-DIS', 'STUCK', ['jenis_pekerjaan' => 'DISCONNECT', 'id_branches' => $other->getKey()]);
        $this->order('REGIONLESS', 'STUCK', ['id_branches' => $noRegion->getKey()]);
        $this->order('UNASSIGNED', extra: ['id_branches' => null]);
        $this->order('EXCLUDED-PS', 'PS');
        $this->actingAs($this->area);
        foreach ([[], ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY', 'month' => '2026-01']] as $filters) {
            $page = $this->get(route('area.dashboard', $filters))->assertOk();
            $report = $page->viewData('mappingOrders')['transactions'];
            $this->assertSame($filters ? 1 : 5, $report['total']);
            $this->assertSame($filters ? ['MODIFY'] : ['NEW INSTALL', 'MODIFY', 'DISCONNECT'], $report['columns']);
            $xpath = $this->xpath($page->getContent());
            foreach ($xpath->query('//table[contains(@class,"open-transaction-table")]//a[@data-dashboard-orders]') as $link) {
                $url = $link->getAttribute('href');
                $list = $this->get($url)->assertOk();
                $expected = (int) str_replace(',', '', $link->textContent);
                $this->assertSame($expected, $list->viewData('total'), $url);
                $modal = $this->get($url.'&modal=1')->assertOk()->assertHeader('X-Dashboard-Fragment', 'orders');
                $this->assertSame($expected, $modal->viewData('orders')->total());
            }
            foreach ($xpath->query('//table[contains(@class,"open-transaction-table")]//button[@data-region-toggle]') as $button) {
                $this->assertSame(1, $xpath->query('//tbody[@id="'.$button->getAttribute('aria-controls').'" and @hidden]')->length);
            }
        }
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor)->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'mapping_order' => 'open_stuck']))->assertOk()->assertViewHas('total', 2)->assertSee('Mapping Order: OPEN &amp; STUCK', false);
        $this->get(route('area.dashboard', ['tab' => 'ach-ps']))->assertForbidden();
        $this->getJson(route('work-orders.index', ['mapping_order' => 'open_stuck_unsafe']))->assertUnprocessable();
    }

    public function test_ach_ps_owns_month_filter_and_daily_chart_with_unchanged_eligibility_and_empty_state(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 01:00:00', 'UTC'));
        $this->order('PS-TODAY', extra: ['progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-27']);
        $this->order('PS-YESTERDAY', extra: ['progress_category' => 'CLOSE', 'task_bima' => 'Testing dan Approval', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-26']);
        $this->order('MAPPING-PS-NOT-ELIGIBLE', 'PS', ['tgl_ps' => '2026-10-27']);
        $this->order('FUTURE-PS', extra: ['progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-29']);
        $filters = ['tab' => 'ach-ps', 'region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'NEW INSTALL', 'month' => '2026-10'];
        $this->actingAs($this->area);
        $page = $this->get(route('area.dashboard', $filters))->assertOk()->assertSee('Perolehan PS harian')->assertSee('id="month" type="month"', false)
            ->assertDontSee('Rekap jenis pekerjaan')->assertDontSee('OPEN &amp; STUCK per Jenis Pekerjaan', false)->assertDontSee('Distribusi Owner Group');
        $this->assertSame(2, $page->viewData('month_ps'));
        $this->assertNull($page->viewData('mappingOrders'));
        $series = collect($page->viewData('series'))->keyBy('date');
        $this->assertSame(1, $series['2026-10-27']['count']);
        $this->assertSame(0, $series['2026-10-29']['count']);
        foreach ($this->xpath($page->getContent())->query('//nav[@aria-label="Tab dashboard Area"]/a') as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame('2026-10', $query['month']);
            $this->assertSame('METRO', $query['service']);
        }
        $progress = $this->get(route('area.dashboard', array_replace($filters, ['tab' => 'progress'])))->assertOk()->assertDontSee('Perolehan PS harian')->assertSee('name="month" value="2026-10"', false);
        $this->assertNull($progress->viewData('month_ps'));
        $this->get(route('area.dashboard', array_replace($filters, ['month' => '2026-09'])))->assertOk()->assertSee('Belum ada perolehan PS dalam bulan ini.');
        $this->getJson(route('area.dashboard', ['tab' => 'ach-ps-unsafe']))->assertUnprocessable();
    }
}
