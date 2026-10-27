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

class PriorityMonitoringTest extends OloTestCase
{
    private function order(string $id, array $extra = [], ?User $selector = null): WorkOrder
    {
        $order = WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $this->branch->getKey(),
            'jenis_pekerjaan' => 'MODIFY', 'owner_grup' => 'OWNER-PRIORITY-DISTRICT', 'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
        app(PriorityOrderService::class)->set($selector ?? $this->area, $order, true);

        return $order;
    }

    private function branchUser(?Branch $branch = null, Role $role = Role::BranchHd): User
    {
        $user = $this->user($role);
        $user->branches()->attach($branch ?? $this->branch);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        MappingOwnergrup::create(['owner_grup' => 'OWNER-PRIORITY-DISTRICT', 'kelompok_ownergrup' => 'DISTRICT']);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-PRIORITY-PMD', 'kelompok_ownergrup' => 'PMD']);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-INACTIVE', 'kelompok_ownergrup' => 'DISTRICT', 'active' => false]);
    }

    public function test_three_reports_reconcile_use_exact_active_mapping_and_preserve_orders(): void
    {
        $this->order('SURVEY-D');
        $this->order('SURVEY-COMPLETE', ['status_bima' => 'COMPLETE']);
        $this->order('APPROVAL-PMD', ['status_bima' => 'COMPLETE', 'task_bima' => 'Approval Survey', 'owner_grup' => 'OWNER-PRIORITY-PMD']);
        foreach ([null, '', 'UNKNOWN', 'OWNER-INACTIVE', 'owner-priority-district'] as $i => $owner) {
            $this->order('UNMAPPED-'.$i, ['owner_grup' => $owner, 'task_bima' => null, 'status_bima' => null]);
        }
        $before = WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all();
        $this->actingAs($this->area)->get(route('priorities.monitoring'))->assertOk()->assertSee('Rincian Order per Branch')->assertDontSee('Status BIMA × Owner Group');
        $page = $this->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertOk()
            ->assertSee('Status BIMA × Owner Group')->assertSee('Rincian Task per Owner Group')->assertDontSee('Rincian Order per Branch')
            ->assertSee('Belum dipetakan')->assertSee('Task BIMA belum tersedia')->assertSee('Status BIMA belum tersedia')
            ->assertDontSee('data-excel-download', false);
        $report = $page->viewData('report');
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame(3, $xpath->query('//div[contains(@class,"priority-monitor-scroll")]/table')->length);
        $this->assertSame(0, $xpath->query('//div[contains(@class,"priority-monitor-page")]//button[@data-region-toggle and not(@data-region-open)]')->length);
        $this->assertSame(8, $report['total']);
        $this->assertSame(8, $page->viewData('orders')->total());
        $this->assertSame(2, $report['totals']['DISTRICT']);
        $this->assertSame(1, $report['totals']['PMD']);
        $this->assertSame(5, $report['totals']['unmapped']);
        $this->assertSame(8, array_sum(array_column($report['statuses'], 'total')));
        $this->assertSame(8, array_sum(array_column($report['owners'], 'total')));
        foreach ($report['statuses'] as $status) {
            $this->assertSame($status['total'], array_sum($status['counts']));
            $this->assertSame($status['total'], array_sum(array_column($status['tasks'], 'total')));
        }
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('wo_status_events', 0);
        $this->assertDatabaseCount('bima_task_logs', 0);
    }

    public function test_monitoring_privacy_deduplication_source_and_transfer_follow_the_bookmark_scope(): void
    {
        $other = Branch::create(['code' => 'PM-OTHER', 'name' => 'Other Monitoring Branch']);
        $branchUser = $this->branchUser();
        $otherUser = $this->branchUser($other);
        $shared = $this->order('SHARED');
        app(PriorityOrderService::class)->set($branchUser, $shared, true);
        $local = $this->order('LOCAL-SECRET', [], $branchUser);
        $this->order('OTHER-PRIVATE', ['id_branches' => $other->getKey()], $otherUser);
        $this->order('OTHER-AREA', ['id_branches' => $other->getKey()]);
        $area = $this->actingAs($this->area)->get(route('priorities.monitoring'))->assertOk()->assertDontSee('LOCAL-SECRET');
        $this->assertSame(2, $area->viewData('report')['total']);
        $page = $this->actingAs($branchUser)->get(route('priorities.monitoring'))->assertOk()->assertSee('LOCAL-SECRET')->assertDontSee('OTHER-AREA')->assertDontSee('OTHER-PRIVATE');
        $this->assertSame(2, $page->viewData('report')['total']);
        foreach (['area' => 1, 'branch' => 2] as $source => $count) {
            $this->get(route('priorities.monitoring', ['source' => $source]))->assertViewHas('report', fn ($r) => $r['total'] === $count);
        }
        $this->get(route('priorities.monitoring', ['branch' => $other->getKey()]))->assertForbidden();
        $shared->update(['id_branches' => $other->getKey()]);
        $local->update(['id_branches' => $other->getKey()]);
        $this->get(route('priorities.monitoring'))->assertViewHas('report', fn ($r) => $r['total'] === 0)->assertDontSee('SHARED')->assertDontSee('LOCAL-SECRET');
        $new = $this->actingAs($otherUser)->get(route('priorities.monitoring'))->assertOk()->assertSee('SHARED')->assertDontSee('LOCAL-SECRET');
        $this->assertSame(3, $new->viewData('report')['total']);
        $this->get(route('priorities.monitoring', ['source' => 'area']))->assertViewHas('report', fn ($r) => $r['total'] === 2);
        $this->actingAs($this->area)->getJson(route('priorities.monitoring', ['source' => 'branch']))->assertUnprocessable();
        $otherUser->branches()->detach();
        $this->actingAs($otherUser)->get(route('priorities.monitoring'))->assertViewHas('report', fn ($r) => $r['total'] === 0);
    }

    public function test_filtered_counts_links_and_list_are_identical_including_missing_case_sensitive_values_and_zero(): void
    {
        $this->order('MATCH');
        $this->order('CASE-TASK', ['task_bima' => 'site survey']);
        $this->order('CASE-STATUS', ['status_bima' => 'startwork']);
        $this->order('MISSING-NULL', ['task_bima' => null, 'status_bima' => null, 'owner_grup' => null]);
        $this->order('MISSING-EMPTY', ['task_bima' => '', 'status_bima' => '', 'owner_grup' => null]);
        $this->order('ZERO', ['task_bima' => '0', 'status_bima' => '0']);
        $this->actingAs($this->area);
        foreach ([['owner_group' => 'DISTRICT'], ['owner_group' => 'unmapped'], ['bima_status' => 'STARTWORK'], ['bima_task' => 'Site Survey'],
            ['bima_status' => 'STARTWORK', 'bima_task' => 'Site Survey', 'owner_group' => 'DISTRICT'],
            ['bima_task_missing' => 1], ['bima_status_missing' => 1, 'owner_group' => 'unmapped'], ['bima_status' => '0'], ['q' => 'MISSING-']] as $filters) {
            $page = $this->get(route('priorities.monitoring', $filters))->assertOk();
            $list = $this->get(route('priorities.index', $filters))->assertOk();
            $this->assertSame($list->viewData('orders')->total(), $page->viewData('report')['total']);
            $this->assertSame($list->viewData('orders')->total(), $page->viewData('orders')->total());
        }
        $page = $this->get(route('priorities.monitoring', ['tab' => 'owner-group']))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//a[contains(concat(" ",normalize-space(@class)," ")," count-link ")]') as $link) {
            $response = $this->get($link->getAttribute('href'))->assertOk();
            $this->assertSame((int) str_replace(',', '', $link->textContent), $response->viewData('orders')->total(), $link->getAttribute('href'));
        }
        $this->get(route('priorities.monitoring', ['bima_status' => 'STARTWORK', 'bima_task' => 'Site Survey']))->assertViewHas('report', fn ($r) => $r['total'] === 1);
        $this->get(route('priorities.monitoring', ['bima_status' => '0']))->assertViewHas('report', fn ($r) => $r['total'] === 1);
        $this->get(route('priorities.monitoring', ['bima_status_missing' => 1]))->assertViewHas('report', fn ($r) => $r['total'] === 2);
        MappingOwnergrup::where('owner_grup', 'OWNER-PRIORITY-DISTRICT')->update(['active' => false]);
        $this->get(route('priorities.monitoring', ['owner_group' => 'DISTRICT']))->assertViewHas('report', fn ($r) => $r['total'] === 0);
    }

    public function test_menu_permissions_validation_empty_and_detail_pagination_are_real(): void
    {
        $this->get(route('priorities.monitoring'))->assertRedirect(route('login'));
        for ($i = 0; $i < 28; $i++) {
            $this->order('PAGINATED-'.str_pad($i, 3, '0', STR_PAD_LEFT));
        }
        foreach (Role::cases() as $role) {
            $user = $this->branchUser(role: $role);
            $this->actingAs($user);
            $allowed = in_array($role, [Role::SuperAdmin, Role::AreaAdmin, Role::AreaHd, Role::BranchAdmin, Role::BranchHd, Role::TeamLeader], true);
            $response = $this->get(route('priorities.monitoring'));
            $allowed ? $response->assertOk()->assertSee('aria-label="Submenu Order Prioritas"', false)->assertSee('Monitoring Order Prioritas')->assertSee('List Order Prioritas') : $response->assertForbidden();
        }
        $this->actingAs($this->area);
        $page = $this->get(route('priorities.monitoring'))->assertOk()->assertSee('page=2', false);
        $this->assertSame(28, $page->viewData('report')['total']);
        $this->assertCount(25, $page->viewData('orders'));
        $second = $this->get(route('priorities.monitoring', ['page' => 2]))->assertOk();
        $this->assertCount(3, $second->viewData('orders'));
        $this->assertSame(28, $second->viewData('report')['total']);
        $this->get(route('priorities.monitoring', ['page' => 99]))->assertOk()->assertSee('Tidak ada order di halaman ini.')->assertSee('Kembali ke halaman pertama');
        $this->get(route('priorities.monitoring', ['state' => 'closed']))->assertViewHas('report', fn ($r) => $r['total'] === 0)->assertSee('Belum ada order prioritas sesuai filter');
        foreach ([['owner_group' => 'FORGED'], ['state' => 'FORGED'], ['bima_task' => str_repeat('a', 256)], ['bima_status_missing' => 1, 'bima_status' => 'COMPLETE'], ['bima_task_missing' => 'FORGED']] as $filters) {
            $this->getJson(route('priorities.monitoring', $filters))->assertUnprocessable();
        }
        $this->get(route('area.dashboard'))->assertOk()->assertDontSee('Operasional Area ke Branch');
        $this->area->forceFill(['active' => false])->save();
        $this->actingAs($this->area->fresh())->get(route('priorities.monitoring'))->assertForbidden();
    }
}
