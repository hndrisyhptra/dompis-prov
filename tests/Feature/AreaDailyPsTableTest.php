<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;
use App\Services\AreaAnalyticsService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;

class AreaDailyPsTableTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 01:00:00', 'UTC'));
        $this->branch->update(['region_id' => 1]);
    }

    private function order(string $id, ?string $date, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
            'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'progress_category' => 'CLOSE', 'tgl_ps' => $date,
            'jenis_pekerjaan' => 'NEW INSTALL', 'layanan' => 'METRO', 'tgl_order' => '2026-07-01',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
    }

    private function fixtures(): void
    {
        $other = Branch::create(['code' => 'PDM-OTHER', 'name' => 'Second Branch', 'region_id' => 2]);
        $archived = Branch::create(['code' => 'PDM-ARCHIVED', 'name' => 'Archived Branch', 'region_id' => 1]);
        foreach (['2026-10-01', '2026-10-13', '2026-10-20', '2026-10-27'] as $i => $date) {
            $this->order('OWN-'.$i, $date);
        }
        $this->order('OTHER-SAME-DATE', '2026-10-27', ['id_branches' => $other->getKey(), 'jenis_pekerjaan' => 'MODIFY']);
        $this->order('UNASSIGNED', '2026-10-14', ['id_branches' => null]);
        $this->order('ARCHIVED', '2026-10-13', ['id_branches' => $archived->getKey()]);
        $archived->delete();
        $this->order('FUTURE', '2026-10-29');
        $this->order('NO-DATE', null);
        $this->order('NOT-CLOSED', '2026-10-27', ['progress_category' => 'SURVEY']);
        $this->order('NOT-ELIGIBLE', '2026-10-27', ['task_bima' => 'Site Survey']);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_separate_branch_matrix_uses_last_fifteen_calendar_days_and_detail_month_reconciles_chart(): void
    {
        $this->fixtures();
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $this->actingAs($this->area);
        $page = $this->get(route('area.dashboard', ['tab' => 'ach-ps', 'month' => '2026-10']))->assertOk()->assertSee('PS harian per Branch')->assertSee('Detail bulan')->assertDontSee('Lihat data PS harian');
        $table = $page->viewData('psDailyTable');
        $this->assertCount(15, $table['dates']);
        $this->assertSame('2026-10-13', $table['start']);
        $this->assertSame('2026-10-27', $table['end']);
        $this->assertSame(6, $table['total']);
        $this->assertSame(7, $table['month_total']);
        $this->assertSame(7, $page->viewData('month_ps'));
        $this->assertSame(2, collect($page->viewData('series'))->firstWhere('date', '2026-10-27')['count']);
        $this->assertCount(4, $table['rows']);
        $this->assertSame(3, $table['rows'][$this->branch->getKey()]['total']);
        $this->assertSame(4, $table['rows'][$this->branch->getKey()]['month_total']);
        $this->assertSame(6, array_sum($table['totals']));
        $this->assertSame(6, array_sum(array_column($table['rows'], 'total')));
        $xpath = $this->xpath($page->getContent());
        $this->assertSame(17, $xpath->query('//table[contains(@class,"ps-branch-daily-matrix")]/thead/tr/th')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="ps-daily-branch-title"]')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="area-chart-title"]//table')->length);
        $detailLink = $xpath->query('//section[@aria-labelledby="ps-daily-branch-title"]//a[contains(@href,"ps_view=month")]')->item(0)->getAttribute('href');
        $detail = $this->get($detailLink)->assertOk()->assertSee('Ringkas 15 hari')->assertSee('Tanggal belum berjalan');
        $this->assertCount(31, $detail->viewData('psDailyTable')['dates']);
        $this->assertSame(7, $detail->viewData('psDailyTable')['total']);
        $this->assertSame(7, $detail->viewData('month_ps'));
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_every_cell_and_period_total_matches_modal_scope_and_preserves_filters_across_detail_views(): void
    {
        $this->fixtures();
        $this->actingAs($this->area);
        foreach ([['ps_view' => 'recent'], ['ps_view' => 'month', 'region' => 1, 'service' => 'METRO', 'jenis_pekerjaan' => 'NEW INSTALL']] as $filters) {
            $filters += ['tab' => 'ach-ps', 'month' => '2026-10'];
            $page = $this->get(route('area.dashboard', $filters))->assertOk();
            $xpath = $this->xpath($page->getContent());
            foreach ($xpath->query('//table[contains(@class,"ps-branch-daily-matrix")]//a[@data-dashboard-orders]') as $link) {
                $url = $link->getAttribute('href');
                $expected = (int) str_replace(',', '', $link->textContent);
                $list = $this->get($url)->assertOk();
                $this->assertSame($expected, $list->viewData('total'), $url);
                $modal = $this->get($url.'&modal=1')->assertOk()->assertHeader('X-Dashboard-Fragment', 'orders');
                $this->assertSame($expected, $modal->viewData('orders')->total());
                foreach ($modal->viewData('orders') as $order) {
                    $modal->assertSee(route('work-orders.show', $order));
                }
            }
            $toggle = $xpath->query('//section[@aria-labelledby="ps-daily-branch-title"]//a[not(@data-dashboard-orders)]')->item(0)->getAttribute('href');
            parse_str(parse_url($toggle, PHP_URL_QUERY), $params);
            $this->assertSame('2026-10', $params['month']);
            $this->assertSame($filters['ps_view'] === 'month' ? 'recent' : 'month', $params['ps_view']);
            if (isset($filters['region'])) {
                $this->assertSame('1', $params['region']);
                $this->assertSame('METRO', $params['service']);
                $this->assertSame('NEW INSTALL', $params['jenis_pekerjaan']);
            }
        }
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor)->get(route('work-orders.index', ['ps_from' => '2026-10-13', 'ps_to' => '2026-10-27', 'jenis_pekerjaan' => 'all']))->assertViewHas('total', 3);
        $other = Branch::where('code', 'PDM-OTHER')->sole();
        $this->get(route('work-orders.index', ['ps_from' => '2026-10-01', 'ps_to' => '2026-10-31', 'branch' => $other->getKey()]))->assertForbidden();
        $this->get(route('area.dashboard', ['tab' => 'ach-ps']))->assertForbidden();
    }

    public function test_calendar_windows_cover_leap_month_early_month_and_future_dates_without_counting_future_ps(): void
    {
        $this->order('FEBRUARY', '2024-02-29');
        $this->order('FUTURE', '2026-11-15');
        $this->actingAs($this->area);
        $past = $this->get(route('area.dashboard', ['tab' => 'ach-ps', 'month' => '2024-02']))->assertOk()->viewData('psDailyTable');
        $this->assertSame('2024-02-15', $past['start']);
        $this->assertSame('2024-02-29', $past['end']);
        $this->assertCount(15, $past['dates']);
        $full = $this->get(route('area.dashboard', ['tab' => 'ach-ps', 'month' => '2024-02', 'ps_view' => 'month']))->assertOk()->viewData('psDailyTable');
        $this->assertCount(29, $full['dates']);
        $this->assertSame(1, $full['total']);
        $futurePage = $this->get(route('area.dashboard', ['tab' => 'ach-ps', 'month' => '2026-11']))->assertOk();
        $future = $futurePage->viewData('psDailyTable');
        $this->assertCount(15, $future['dates']);
        $this->assertSame(0, $future['total']);
        $this->assertSame(15, count(array_filter($future['dates'], fn ($date) => $date['future'])));
        $this->get(route('work-orders.index', $future['period_filters'] + ['jenis_pekerjaan' => 'all']))->assertViewHas('total', 0);
        $this->travelTo(Carbon::parse('2026-10-05 01:00:00', 'UTC'));
        $early = $this->get(route('area.dashboard', ['tab' => 'ach-ps', 'month' => '2026-10']))->assertOk()->viewData('psDailyTable');
        $this->assertCount(5, $early['dates']);
        $this->assertSame('2026-10-01', $early['start']);
        $this->assertSame('2026-10-05', $early['end']);
    }

    public function test_period_filters_validate_and_persist_and_ach_matrix_has_no_additional_queries(): void
    {
        $this->actingAs($this->area);
        foreach ([['ps_from' => '2026-10-01'], ['ps_to' => '2026-10-02'], ['ps_from' => '2026-10-02', 'ps_to' => '2026-10-01'],
            ['ps_from' => '2026-02-30', 'ps_to' => '2026-03-01'], ['ps_from' => '2026-10-01', 'ps_to' => '2026-10-02', 'ps_date' => '2026-10-01']] as $invalid) {
            $this->getJson(route('work-orders.index', $invalid))->assertUnprocessable();
        }
        $this->getJson(route('area.dashboard', ['tab' => 'ach-ps', 'ps_view' => 'unsafe']))->assertUnprocessable();
        $this->order('VISIBLE', '2026-10-20');
        $page = $this->get(route('work-orders.index', ['ps_from' => '2026-10-13', 'ps_to' => '2026-10-27']))->assertOk()->assertSee('name="ps_from" value="2026-10-13"', false)->assertSee('name="ps_to" value="2026-10-27"', false);
        foreach ($this->xpath($page->getContent())->query('//nav[@aria-label="Antrean order"]/a') as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $params);
            $this->assertSame('2026-10-13', $params['ps_from']);
            $this->assertSame('2026-10-27', $params['ps_to']);
        }
        $queryCounts = [];
        foreach ([5, 30] as $size) {
            for ($i = WorkOrder::count(); $i < $size; $i++) {
                $this->order('BUDGET-'.$i, '2026-10-20');
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $data = app(AreaAnalyticsService::class)->summary($this->area, ['tab' => 'ach-ps']);
                $queryCounts[$size] = count(DB::getQueryLog());
                $this->assertSame($size, $data['psDailyTable']['total']);
                $this->assertLessThanOrEqual(9, $queryCounts[$size]);
            } finally {
                DB::disableQueryLog();
            }
        }
        $this->assertSame($queryCounts[5], $queryCounts[30]);
    }
}
