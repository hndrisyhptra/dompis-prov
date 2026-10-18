<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\WorkOrder;
use Carbon\Carbon;

class AreaDashboardTest extends OloTestCase
{
    private function fixtures(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 17:05:00', 'UTC'));
        $this->branch->update(['region_id' => 1]);
        $this->pull(array_map(fn ($id) => $this->row($id, [1 => '2026-10-18']), ['PS-TODAY', 'PS-PREVIOUS', 'WAIT-BIMA', 'BIMA-ONLY', 'DESK', 'SURVEY', 'CANCEL', 'PS-MISSING', 'OGP', 'RESUBMIT', 'FUTURE']));
        $updates = [
            'PS-TODAY' => ['progress_category' => 'CLOSE', 'bima_status' => ' complete ', 'bima_ps_date' => '2026-10-18'],
            'PS-PREVIOUS' => ['progress_category' => 'CLOSE', 'bima_status' => 'CLOSE', 'bima_ps_date' => '2026-10-17', 'job_type' => 'MODIFY'],
            'WAIT-BIMA' => ['progress_category' => 'CLOSE', 'bima_status' => 'INCOMPLETE', 'bima_final_status' => 'CLOSE'],
            'BIMA-ONLY' => ['bima_status' => 'COMPLETE', 'bima_final_status' => 'CLOSE'],
            'DESK' => ['progress_category' => 'CLOSE ON DESK', 'bima_status' => 'CLOSE', 'bima_ps_date' => '2026-10-18'],
            'SURVEY' => ['progress_category' => 'SURVEY'], 'CANCEL' => ['progress_category' => 'CANCEL'],
            'PS-MISSING' => ['progress_category' => 'CLOSE', 'bima_status' => 'COMPLETE'],
            'OGP' => ['progress_category' => 'OGP BIMA'], 'RESUBMIT' => ['progress_category' => 'RESUBMIT AO'],
            'FUTURE' => ['progress_category' => 'CLOSE', 'bima_status' => 'COMPLETE', 'bima_ps_date' => '2026-10-19'],
        ];
        foreach ($updates as $id => $data) {
            WorkOrder::where('external_order_id', $id)->update($data);
        }
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Branch Regional Dua', 'region_id' => 2]);
        WorkOrder::where('external_order_id', 'SURVEY')->update(['id_branches' => $other->getKey()]);
        Branch::create(['code' => 'EMPTY', 'name' => 'Branch tanpa order', 'region_id' => 2]);
        WorkOrder::where('external_order_id', 'BIMA-ONLY')->firstOrFail()->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'ACCESS', 'description' => 'Akses belum tersedia', 'pic' => 'PIC Uji']);
    }

    public function test_only_four_area_roles_have_dashboard_and_the_correct_home_after_login(): void
    {
        $this->get(route('area.dashboard'))->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $user = $this->user($role);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('area.dashboard'));
            $response = $this->get(route('area.dashboard'))->assertOk()->assertSee('Dashboard Area')->assertSee('Rekap jenis pekerjaan')->assertDontSee('Dashboard Branch');
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

    public function test_regional_totals_types_ps_rules_missing_dates_and_chart_reconcile_without_changing_data(): void
    {
        $this->fixtures();
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $response = $this->actingAs($this->area)->get(route('area.dashboard', ['month' => '2026-10']))->assertOk()->assertSee('Branch tanpa order')->assertSee('CLOSE menunggu BIMA')->assertSee('PS tanpa tanggal');
        $summary = $response->viewData('summary');
        $this->assertSame(['total' => 11, 'ps_total' => 4, 'ps_today' => 1, 'incoming_today' => 11, 'outstanding' => 4, 'open_issues' => 1, 'waiting_bima' => 1, 'missing_ps_date' => 1, 'future_ps_date' => 1, 'unmapped' => 0], $summary);
        $grand = $response->viewData('grand');
        $this->assertSame(11, $grand['total']);
        $this->assertSame(11, array_sum(array_diff_key($grand, ['total' => true, 'ps_today' => true])));
        $this->assertSame(5, $grand['close']);
        $this->assertSame(2, $grand['bima']);
        $this->assertSame(2, $response->viewData('month_ps'));
        $series = collect($response->viewData('series'))->keyBy('date');
        $this->assertSame(31, $series->count());
        $this->assertSame(1, $series['2026-10-18']['count']);
        $this->assertSame(1, $series['2026-10-17']['count']);
        $this->assertSame(0, $series['2026-10-19']['count']);
        $recap = $response->viewData('recap');
        $this->assertSame(1, $recap['MODIFY']['ps_total']);
        $this->assertSame(3, $recap['NEW INSTALL']['ps_total']);
        $this->assertSame(11, array_sum(array_column($recap, 'total')));
        $this->assertSame(11, array_sum(array_map(fn ($group) => $group['totals']['total'], $response->viewData('groups'))));
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
    }

    public function test_filters_month_and_drilldowns_match_and_branch_cannot_use_them_to_escape_scope(): void
    {
        $this->fixtures();
        $this->actingAs($this->area);
        $filtered = $this->get(route('area.dashboard', ['region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'METRO', 'job_type' => 'MODIFY', 'month' => '2026-09']))->assertOk();
        $this->assertSame(1, $filtered->viewData('summary')['total']);
        $this->assertSame(1, $filtered->viewData('branch_count'));
        $this->assertSame(['MODIFY'], array_keys($filtered->viewData('recap')));
        $this->assertSame(30, count($filtered->viewData('series')));
        $this->assertSame(0, $filtered->viewData('month_ps'));
        foreach (['2026-13', '2026-10-01', '1900-01', '2101-01'] as $month) {
            $this->get(route('area.dashboard', ['month' => $month]))->assertSessionHasErrors('month');
        }
        $this->get(route('area.dashboard', ['branch' => 99999]))->assertForbidden();
        $this->get(route('area.dashboard', ['region' => 99999]))->assertSessionHasErrors('region');
        $this->get(route('work-orders.index', ['job_type' => 'all', 'queue' => 'ps_today']))->assertSee('PS-TODAY')->assertDontSee('PS-PREVIOUS')->assertDontSee('WAIT-BIMA')->assertDontSee('BIMA-ONLY')->assertDontSee('>DESK<', false);
        $this->get(route('work-orders.index', ['job_type' => 'all', 'queue' => 'waiting_bima']))->assertSee('WAIT-BIMA')->assertDontSee('PS-TODAY');
        $this->get(route('work-orders.index', ['job_type' => 'all', 'queue' => 'missing_ps_date']))->assertSee('PS-MISSING')->assertDontSee('PS-TODAY');
        $this->get(route('work-orders.index', ['job_type' => 'all', 'bucket' => 'bima', 'region' => 1, 'service' => 'METRO']))->assertSee('OGP')->assertSee('RESUBMIT')->assertDontSee('PS-TODAY');
        $user = $this->user();
        $user->branches()->attach($this->branch);
        $other = Branch::where('code', 'OTHER')->firstOrFail();
        $this->actingAs($user)->get(route('work-orders.index', ['job_type' => 'all', 'region' => 2]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->get(route('work-orders.index', ['job_type' => 'all', 'branch' => $other->getKey()]))->assertForbidden();
    }

    public function test_empty_unmapped_inactive_and_additional_job_types_are_not_lost(): void
    {
        $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertViewHas('month_ps', 0)->assertSee('Belum ada order dalam filter ini');
        $this->pull([$this->row('UNMAPPED'), $this->row('ARCHIVED'), $this->row('NO-TYPE')]);
        WorkOrder::where('external_order_id', 'UNMAPPED')->update(['id_branches' => null, 'job_type' => 'EXTRA']);
        WorkOrder::where('external_order_id', 'NO-TYPE')->update(['job_type' => null]);
        $archived = Branch::create(['code' => 'ARCHIVED', 'name' => 'Cabang nonaktif']);
        WorkOrder::where('external_order_id', 'ARCHIVED')->update(['id_branches' => $archived->getKey()]);
        $archived->delete();
        $response = $this->get(route('area.dashboard'))->assertOk()->assertSee('Cabang nonaktif')->assertSee('Belum terpetakan')->assertSee('EXTRA');
        $this->assertSame(3, $response->viewData('grand')['total']);
        $this->assertSame(1, $response->viewData('summary')['unmapped']);
        $this->get(route('work-orders.index', ['branch' => $archived->getKey(), 'job_type' => 'all']))->assertOk()->assertSee('ARCHIVED');
        $this->get(route('work-orders.index', ['queue' => 'unmapped', 'job_type' => 'all']))->assertSee('UNMAPPED')->assertDontSee('NO-TYPE');
        $this->get(route('work-orders.index', ['job_type' => 'TANPA JENIS']))->assertSee('NO-TYPE')->assertDontSee('ARCHIVED');
    }
}
