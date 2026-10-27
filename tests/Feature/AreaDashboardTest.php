<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

class AreaDashboardTest extends OloTestCase
{
    public function test_progress_and_owner_tabs_render_separately_preserve_filters_and_do_not_write_data(): void
    {
        $this->fixtures();
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $this->actingAs($this->area);
        $this->get(route('area.dashboard'))->assertOk()->assertViewHas('activeTab', 'progress')
            ->assertSee('id="area-dashboard-progress"', false)->assertDontSee('Rekap jenis pekerjaan')->assertDontSee('Perolehan PS harian')
            ->assertDontSee('id="owner-group-title"', false);
        $filters = ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY', 'month' => '2026-09'];
        $page = $this->get(route('area.dashboard', $filters + ['tab' => 'owner-group']))->assertOk()->assertViewHas('activeTab', 'owner-group')
            ->assertSee('Distribusi Owner Group')->assertSee('id="area-dashboard-owner-group"', false)
            ->assertDontSee('Rekap jenis pekerjaan')->assertDontSee('Perolehan PS harian');
        $this->assertSame(1, $page->viewData('ownerGroups')['total']);
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame(3, $xpath->query('//nav[@aria-label="Tab dashboard Area"]/a')->length);
        $this->assertSame('area-tab-owner-group', $xpath->query('//nav[@aria-label="Tab dashboard Area"]/a[@aria-current="page"]')->item(0)->getAttribute('id'));
        $progressUrl = $xpath->query('//a[@id="area-tab-progress"]')->item(0)->getAttribute('href');
        parse_str(parse_url($progressUrl, PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['tab' => 'progress'], $query);
        $this->assertSame(1, $xpath->query('//form//input[@type="hidden" and @name="tab" and @value="owner-group"]')->length);
        $this->assertSame(1, $xpath->query('//form//input[@type="hidden" and @name="month" and @value="2026-09"]')->length);
        $this->get($progressUrl)->assertOk()->assertViewHas('activeTab', 'progress')->assertViewHas('branch_count', 1)->assertDontSee('id="owner-group-title"', false);
        $achUrl = $xpath->query('//a[@id="area-tab-ach-ps"]')->item(0)->getAttribute('href');
        parse_str(parse_url($achUrl, PHP_URL_QUERY), $achQuery);
        $this->assertEquals($filters + ['tab' => 'ach-ps'], $achQuery);
        $this->get($achUrl)->assertOk()->assertViewHas('activeTab', 'ach-ps')->assertSee('Perolehan PS harian')->assertSee('id="area-dashboard-ach-ps"', false)
            ->assertDontSee('Rekap jenis pekerjaan')->assertDontSee('Distribusi Owner Group')->assertDontSee('Mapping Order Region');
        $this->getJson(route('area.dashboard', ['tab' => 'forged']))->assertUnprocessable()->assertJsonValidationErrors('tab');
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
        $this->actingAs($this->user())->get(route('area.dashboard', ['tab' => 'owner-group']))->assertForbidden();
    }

    private function fixtures(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 17:05:00', 'UTC'));
        $this->branch->update(['region_id' => 1]);
        $this->pull(array_map(fn ($id) => $this->row($id, [1 => '2026-10-18']), ['PS-TODAY', 'PS-PREVIOUS', 'WAIT-BIMA', 'BIMA-ONLY', 'DESK', 'SURVEY', 'CANCEL', 'PS-MISSING', 'OGP', 'RESUBMIT', 'FUTURE']));
        $updates = [
            'PS-TODAY' => ['progress_category' => 'CLOSE', 'status_bima' => ' complete ', 'tgl_ps' => '2026-10-18'],
            'PS-PREVIOUS' => ['progress_category' => 'CLOSE', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-17', 'jenis_pekerjaan' => 'MODIFY'],
            'WAIT-BIMA' => ['progress_category' => 'CLOSE', 'status_bima' => 'INCOMPLETE', 'status_final' => 'CLOSE'],
            'BIMA-ONLY' => ['status_bima' => 'COMPLETE', 'status_final' => 'CLOSE'],
            'DESK' => ['progress_category' => 'CLOSE ON DESK', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-18'],
            'SURVEY' => ['progress_category' => 'SURVEY'], 'CANCEL' => ['progress_category' => 'CANCEL'],
            'PS-MISSING' => ['progress_category' => 'CLOSE', 'status_bima' => 'COMPLETE'],
            'OGP' => ['progress_category' => 'OGP BIMA'], 'RESUBMIT' => ['progress_category' => 'RESUBMIT AO'],
            'FUTURE' => ['progress_category' => 'CLOSE', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-19'],
        ];
        foreach ($updates as $id => $data) {
            WorkOrder::where('no_tq_sc_id', $id)->firstOrFail()->update($data + ['task_bima' => 'Approval E2E Testing Manual']);
        }
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Branch Regional Dua', 'region_id' => 2]);
        WorkOrder::where('no_tq_sc_id', 'SURVEY')->update(['id_branches' => $other->getKey()]);
        Branch::create(['code' => 'EMPTY', 'name' => 'Branch tanpa order', 'region_id' => 2]);
        WorkOrder::where('no_tq_sc_id', 'BIMA-ONLY')->firstOrFail()->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'ACCESS', 'description' => 'Akses belum tersedia', 'pic' => 'PIC Uji']);
    }

    public function test_only_four_area_roles_have_dashboard_and_the_correct_home_after_login(): void
    {
        $this->get(route('area.dashboard'))->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $user = $this->user($role);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('area.dashboard'));
            $response = $this->get(route('area.dashboard'))->assertOk()->assertSee('Dashboard Area')->assertSee('Progress Region &amp; Branch', false)->assertDontSee('Rekap jenis pekerjaan')->assertDontSee('Dashboard Branch');
            if ($role === Role::AreaCoordinator) {
                $response->assertDontSee('Sync Center');
            }
            $this->get('/')->assertRedirect(route('area.dashboard'));
            $this->post('/logout')->assertRedirect();
        }
        foreach ([Role::BranchAdmin, Role::TeamLeader, Role::MaterialAdmin, Role::Bot] as $role) {
            $this->actingAs($this->user($role))->get(route('area.dashboard'))->assertForbidden();
        }
        $this->actingAs($this->user(Role::SuperAdmin, false))->get(route('area.dashboard'))->assertForbidden();
    }

    public function test_regional_totals_ps_rules_missing_dates_and_chart_reconcile_without_changing_data(): void
    {
        $this->fixtures();
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $response = $this->actingAs($this->area)->get(route('area.dashboard', ['month' => '2026-10']))->assertOk()->assertSee('Branch tanpa order')->assertSee('CLOSE menunggu BIMA')->assertSee('PS tanpa tanggal');
        $summary = $response->viewData('summary');
        $this->assertSame(['total' => 11, 'ps_total' => 4, 'ps_today' => 1, 'incoming_today' => 11, 'outstanding' => 4, 'open_issues' => 1, 'waiting_bima' => 1, 'missing_ps_date' => 1, 'future_ps_date' => 1, 'unmapped' => 0, 'unupdated' => 4, 'closed_by_branch' => 5, 'potential_ps' => 0, 'new_order' => 0], $summary);
        $grand = $response->viewData('grand');
        $this->assertSame(11, $grand['total']);
        $this->assertSame(11, array_sum(array_diff_key($grand, ['total' => true, 'ps_today' => true, 'incoming_today' => true])));
        $this->assertSame(11, $grand['incoming_today']);
        $this->assertSame(5, $grand['close']);
        $this->assertSame(2, $grand['bima']);
        $this->assertNull($response->viewData('month_ps'));
        $response->assertViewMissing('recap');
        $chart = $this->get(route('area.dashboard', ['tab' => 'ach-ps', 'month' => '2026-10']))->assertOk();
        $this->assertSame($summary, $chart->viewData('summary'));
        $this->assertSame(2, $chart->viewData('month_ps'));
        $series = collect($chart->viewData('series'))->keyBy('date');
        $this->assertSame(31, $series->count());
        $this->assertSame(1, $series['2026-10-18']['count']);
        $this->assertSame(1, $series['2026-10-17']['count']);
        $this->assertSame(0, $series['2026-10-19']['count']);
        foreach (['MODIFY' => 1, 'NEW INSTALL' => 3] as $type => $expected) {
            $this->get(route('area.dashboard', ['jenis_pekerjaan' => $type]))->assertOk()
                ->assertViewHas('summary', fn ($data) => $data['ps_total'] === $expected);
        }
        $this->assertSame(11, array_sum(array_map(fn ($group) => $group['totals']['total'], $response->viewData('groups'))));
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
    }

    public function test_filters_month_and_drilldowns_match_and_branch_cannot_use_them_to_escape_scope(): void
    {
        $this->fixtures();
        $this->actingAs($this->area);
        $filtered = $this->get(route('area.dashboard', ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY', 'month' => '2026-09']))->assertOk();
        $this->assertSame(1, $filtered->viewData('summary')['total']);
        $this->assertSame(1, $filtered->viewData('branch_count'));
        $this->assertSame(1, $filtered->viewData('summary')['ps_total']);
        $this->assertSame([], $filtered->viewData('series'));
        $this->assertNull($filtered->viewData('month_ps'));
        $ach = $this->get(route('area.dashboard', ['tab' => 'ach-ps', 'region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'jenis_pekerjaan' => 'MODIFY', 'month' => '2026-09']))->assertOk();
        $this->assertSame(30, count($ach->viewData('series')));
        $this->assertSame(0, $ach->viewData('month_ps'));
        $this->assertSame($filtered->viewData('summary'), $ach->viewData('summary'));
        foreach (['2026-13', '2026-10-01', '1900-01', '2101-01'] as $month) {
            $this->get(route('area.dashboard', ['month' => $month]))->assertSessionHasErrors('month');
        }
        $this->get(route('area.dashboard', ['branch' => 99999]))->assertForbidden();
        $this->get(route('area.dashboard', ['region' => 99999]))->assertSessionHasErrors('region');
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'queue' => 'ps_today']))->assertSee('PS-TODAY')->assertDontSee('PS-PREVIOUS')->assertDontSee('WAIT-BIMA')->assertDontSee('BIMA-ONLY')->assertDontSee('>DESK<', false);
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'queue' => 'waiting_bima']))->assertSee('WAIT-BIMA')->assertDontSee('PS-TODAY');
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'queue' => 'missing_ps_date']))->assertSee('PS-MISSING')->assertDontSee('PS-TODAY');
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'bucket' => 'bima', 'region' => 1, 'service' => 'METRO']))->assertSee('OGP')->assertSee('RESUBMIT')->assertDontSee('PS-TODAY');
        $user = $this->user();
        $user->branches()->attach($this->branch);
        $other = Branch::where('code', 'OTHER')->firstOrFail();
        $this->actingAs($user)->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'region' => 2]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'branch' => $other->getKey()]))->assertForbidden();
    }

    public function test_empty_unmapped_inactive_and_additional_job_types_are_not_lost(): void
    {
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertSee('Belum ada order dalam filter ini');
        $this->get(route('area.dashboard', ['tab' => 'ach-ps']))->assertOk()->assertViewHas('month_ps', 0);
        $this->pull([$this->row('UNMAPPED'), $this->row('ARCHIVED'), $this->row('NO-TYPE')]);
        WorkOrder::where('no_tq_sc_id', 'UNMAPPED')->update(['id_branches' => null, 'jenis_pekerjaan' => 'EXTRA']);
        WorkOrder::where('no_tq_sc_id', 'NO-TYPE')->update(['jenis_pekerjaan' => null]);
        $archived = Branch::create(['code' => 'ARCHIVED', 'name' => 'Cabang nonaktif']);
        WorkOrder::where('no_tq_sc_id', 'ARCHIVED')->update(['id_branches' => $archived->getKey()]);
        $archived->delete();
        $response = $this->get(route('area.dashboard'))->assertOk()->assertSee('Cabang nonaktif')->assertSee('Belum terpetakan')->assertSee('EXTRA');
        $this->assertSame(3, $response->viewData('grand')['total']);
        $this->assertSame(1, $response->viewData('summary')['unmapped']);
        $this->get(route('work-orders.index', ['branch' => $archived->getKey(), 'jenis_pekerjaan' => 'all']))->assertOk()->assertSee('ARCHIVED');
        $this->get(route('work-orders.index', ['queue' => 'unmapped', 'jenis_pekerjaan' => 'all']))->assertSee('UNMAPPED')->assertDontSee('NO-TYPE');
        $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'TANPA JENIS']))->assertSee('NO-TYPE')->assertDontSee('ARCHIVED');
    }
}
