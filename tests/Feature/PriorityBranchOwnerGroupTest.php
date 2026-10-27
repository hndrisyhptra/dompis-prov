<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\MappingOwnergrup;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PriorityOrderService;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;

class PriorityBranchOwnerGroupTest extends OloTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MappingOwnergrup::create(['owner_grup' => 'OWNER-D', 'kelompok_ownergrup' => 'DISTRICT']);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-P', 'kelompok_ownergrup' => 'PMD']);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-C', 'kelompok_ownergrup' => 'CSO']);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-INACTIVE', 'kelompok_ownergrup' => 'PMD', 'active' => false]);
    }

    private function order(string $id, array $extra = [], ?User $selector = null): WorkOrder
    {
        $order = WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
            'owner_grup' => 'OWNER-D', 'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK', 'progress_category' => 'SURVEY',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
        app(PriorityOrderService::class)->set($selector ?? $this->area, $order, true);

        return $order;
    }

    private function branchUser(Branch $branch): User
    {
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($branch);

        return $actor;
    }

    private function tableLinks($page)
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());

        return (new DOMXPath($dom))->query('//table[contains(@class,"priority-branch-owner-matrix")]//a');
    }

    public function test_pivot_counts_all_pages_once_and_reconciles_rows_columns_ps_and_unassigned(): void
    {
        $other = Branch::create(['code' => 'PBO-OTHER', 'name' => 'Other Branch']);
        $branchActor = $this->branchUser($this->branch);
        for ($i = 0; $i < 30; $i++) {
            $order = $this->order('ALL-PAGES-'.$i);
            if ($i === 0) {
                app(PriorityOrderService::class)->set($branchActor, $order, true);
            }
        }
        $this->order('OTHER-PMD', ['id_branches' => $other->getKey(), 'owner_grup' => 'OWNER-P']);
        $this->order('UNASSIGNED', ['id_branches' => null, 'owner_grup' => 'UNKNOWN']);
        $this->order('LOCAL-PRIVATE', selector: $branchActor);
        $ps = $this->order('PS-EXCLUDED');
        $ps->update(['task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'progress_category' => 'CLOSE']);
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $this->actingAs($this->area);
        $this->get(route('priorities.monitoring'))->assertDontSee('Branch per Owner Group');
        $page = $this->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertOk()->assertSee('Branch per Owner Group')->assertSee('Belum ditugaskan')->assertSee('Seluruh hasil filter');
        $pivot = $page->viewData('branchOwners');
        $this->assertSame(32, $pivot['total']);
        $this->assertSame($page->viewData('report')['total'], $pivot['total']);
        $this->assertCount(25, $page->viewData('orders'));
        $this->assertCount(3, $pivot['rows']);
        $this->assertSame(32, array_sum(array_column($pivot['rows'], 'total')));
        $this->assertSame(30, $pivot['totals']['DISTRICT']);
        $this->assertSame(1, $pivot['totals']['PMD']);
        $this->assertSame(1, $pivot['totals']['unmapped']);
        foreach ($pivot['rows'] as $row) {
            $this->assertSame($row['total'], array_sum($row['counts']));
        }
        $second = $this->get(route('priorities.monitoring', ['tab' => 'owner-group', 'page' => 2]))->assertOk();
        $this->assertSame($pivot, $second->viewData('branchOwners'));
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
    }

    public function test_pivot_preserves_local_privacy_sources_and_assignment_changes(): void
    {
        $other = Branch::create(['code' => 'PBO-PRIVATE', 'name' => 'Private Other Branch']);
        $actor = $this->branchUser($this->branch);
        $foreignActor = $this->branchUser($other);
        $shared = $this->order('SHARED');
        app(PriorityOrderService::class)->set($actor, $shared, true);
        $local = $this->order('LOCAL', ['owner_grup' => 'OWNER-C'], $actor);
        $this->order('FOREIGN-LOCAL', ['id_branches' => $other->getKey(), 'owner_grup' => 'OWNER-P'], $foreignActor);
        $this->actingAs($this->area)->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertViewHas('branchOwners', fn ($r) => $r['total'] === 1 && $r['totals']['CSO'] === 0 && $r['totals']['PMD'] === 0)->assertDontSee('Private Other Branch</th>', false);
        $page = $this->actingAs($actor)->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertOk();
        $this->assertSame(2, $page->viewData('branchOwners')['total']);
        $this->assertCount(1, $page->viewData('branchOwners')['rows']);
        $this->get(route('priorities.monitoring', ['tab' => 'owner-group', 'source' => 'area']))->assertViewHas('branchOwners', fn ($r) => $r['total'] === 1);
        $this->get(route('priorities.monitoring', ['tab' => 'owner-group', 'source' => 'branch']))->assertViewHas('branchOwners', fn ($r) => $r['total'] === 2);
        $this->get(route('priorities.monitoring', ['tab' => 'owner-group', 'branch' => $other->getKey()]))->assertForbidden();
        $local->update(['id_branches' => $other->getKey()]);
        $this->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertViewHas('branchOwners', fn ($r) => $r['total'] === 1);
        $this->actingAs($foreignActor)->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertViewHas('branchOwners', fn ($r) => $r['total'] === 1 && $r['totals']['CSO'] === 0);
    }

    public function test_every_cell_and_total_opens_the_exact_filtered_list_including_zero_and_unassigned(): void
    {
        $other = Branch::create(['code' => 'PBO-LINKS', 'name' => 'Links Branch']);
        $this->order('MATCH-D');
        $this->order('MATCH-P', ['id_branches' => $other->getKey(), 'owner_grup' => 'OWNER-P']);
        $this->order('MATCH-UNASSIGNED', ['id_branches' => null, 'owner_grup' => 'OWNER-C']);
        $this->order('OUTSIDE-SEARCH');
        $this->order('MATCH-OTHER-STATUS', ['status_bima' => 'COMPLETE']);
        $this->actingAs($this->area);
        $filters = ['tab' => 'owner-group', 'q' => 'MATCH-', 'bima_status' => 'STARTWORK', 'bima_task' => 'Site Survey'];
        $page = $this->get(route('priorities.monitoring', $filters))->assertOk();
        $this->assertSame(3, $page->viewData('branchOwners')['total']);
        $seenUnassigned = $seenZero = false;
        foreach ($this->tableLinks($page) as $link) {
            $url = $link->getAttribute('href');
            parse_str(parse_url($url, PHP_URL_QUERY), $params);
            $this->assertArrayNotHasKey('tab', $params);
            $this->assertSame('MATCH-', $params['q']);
            $this->assertSame('STARTWORK', $params['bima_status']);
            $this->assertSame('Site Survey', $params['bima_task']);
            $list = $this->get($url)->assertOk();
            $this->assertSame((int) str_replace(',', '', $link->textContent), $list->viewData('orders')->total(), $url);
            $seenUnassigned = $seenUnassigned || ! empty($params['unassigned']);
            $seenZero = $seenZero || (int) $link->textContent === 0;
        }
        $this->assertTrue($seenUnassigned);
        $this->assertTrue($seenZero);
        $this->get(route('priorities.index', ['unassigned' => 1]))->assertViewHas('orders', fn ($rows) => $rows->total() === 1 && $rows->first()->id_branches === null)->assertSee('id="priority-unassigned" name="unassigned" value="1" checked', false);
        $this->getJson(route('priorities.monitoring', ['tab' => 'owner-group', 'unassigned' => 'forged']))->assertUnprocessable();
        $this->getJson(route('priorities.index', ['unassigned' => 1, 'branch' => $this->branch->getKey()]))->assertUnprocessable();
    }

    public function test_mapping_changes_are_immediate_exact_and_totals_keep_unknown_inactive_and_empty_values(): void
    {
        foreach (['OWNER-D', 'OWNER-INACTIVE', 'owner-d', '', null] as $i => $owner) {
            $this->order('MAPPING-'.$i, ['owner_grup' => $owner]);
        }
        $this->actingAs($this->area);
        $filters = ['tab' => 'owner-group'];
        $page = $this->get(route('priorities.monitoring', $filters))->assertOk();
        $this->assertSame(5, $page->viewData('branchOwners')['total']);
        $this->assertSame(4, $page->viewData('branchOwners')['totals']['unmapped']);
        $filtered = $this->get(route('priorities.monitoring', $filters + ['owner_group' => 'unmapped']))->assertOk();
        $this->assertSame(4, $filtered->viewData('branchOwners')['total']);
        MappingOwnergrup::where('owner_grup', 'OWNER-D')->update(['kelompok_ownergrup' => 'CSO']);
        $this->get(route('priorities.monitoring', $filters))->assertViewHas('branchOwners', fn ($r) => $r['totals']['CSO'] === 1 && $r['totals']['DISTRICT'] === 0);
        MappingOwnergrup::where('owner_grup', 'OWNER-D')->update(['active' => false]);
        $this->get(route('priorities.monitoring', $filters))->assertViewHas('branchOwners', fn ($r) => $r['totals']['unmapped'] === 5);
    }

    public function test_owner_tab_query_budget_is_bounded_and_other_tabs_do_not_load_the_pivot(): void
    {
        $this->actingAs($this->area);
        $counts = [];
        foreach ([5, 30] as $size) {
            for ($i = WorkOrder::count(); $i < $size; $i++) {
                $this->order('PIVOT-PERF-'.$i);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $page = $this->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertOk();
                $counts[$size] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame($size, $page->viewData('branchOwners')['total']);
            $this->assertLessThanOrEqual(12, $counts[$size]);
        }
        $this->assertSame($counts[5], $counts[30]);
        foreach (['branch', 'issues'] as $tab) {
            $this->get(route('priorities.monitoring', ['tab' => $tab]))->assertViewHas('branchOwners', null)->assertDontSee('Branch per Owner Group');
        }
    }
}
